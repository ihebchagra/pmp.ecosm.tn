<?php
require_once __DIR__ . '/../powertrain/db.php';
require_once __DIR__ . '/../powertrain/auth.php';

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    die("Method not allowed");
}

// Get the POST data
$attempt_id = (int)($_POST['attempt_id'] ?? 0);
$blocs_state = json_decode($_POST['blocs_state'] ?? '[]', true);
$share_token = $_POST['share_token'] ?? null;

if (!$attempt_id) {
    http_response_code(400);
    die("Invalid attempt ID");
}

// Fetch attempt info, including project owner, to validate access
$stmt_attempt = $db->prepare('
    SELECT a.*, p.user_id AS project_owner, p.project_id
    FROM attempts a
    JOIN user_projects p ON a.project_id = p.project_id
    WHERE a.attempt_id = :aid
');
$stmt_attempt->execute(['aid' => $attempt_id]);
$attempt = $stmt_attempt->fetch(PDO::FETCH_ASSOC);

if (!$attempt) {
    http_response_code(404);
    die("Attempt not found");
}

if ($attempt['locked']) {
    http_response_code(403);
    die("This attempt is already locked and cannot be modified");
}

// Authorize the save operation
if ($attempt['is_guest']) {
    if (!$share_token) {
        http_response_code(403);
        die("Token required for guest attempts");
    }
    // Validate the share token
    $stmt_token = $db->prepare("SELECT 1 FROM project_shares WHERE project_id = :pid AND share_token = :token AND share_type = 'exam'");
    $stmt_token->execute(['pid' => $attempt['project_id'], 'token' => $share_token]);
    if (!$stmt_token->fetch()) {
        http_response_code(403);
        die("Invalid token for this attempt");
    }
} else {
    // For non-guests, verify that the logged-in user owns the project
    require_login();
    if ($_SESSION['user']['email'] !== $attempt['project_owner']) {
        http_response_code(403);
        die("You don't have permission to save this attempt");
    }
}

$db->beginTransaction();

try {
    // Get all valid proposition IDs for this project to prevent saving invalid data
    $stmt_valid_props = $db->prepare("
        SELECT bp.proposition_id FROM bloc_propositions bp
        JOIN project_blocs pb ON bp.bloc_id = pb.bloc_id
        WHERE pb.project_id = ?
    ");
    $stmt_valid_props->execute([$attempt['project_id']]);
    $valid_prop_ids = $stmt_valid_props->fetchAll(PDO::FETCH_COLUMN, 0);
    $valid_prop_ids_set = array_flip($valid_prop_ids); // Use as a fast lookup set

    // 1. Save or update all answers for the attempt
    if (!empty($blocs_state)) {
        $stmt_insert_answer = $db->prepare('
            INSERT INTO attempt_answers (attempt_id, proposition_id, penalty_applied)
            VALUES (:aid, :prop_id, :penalty)
            ON CONFLICT (attempt_id, proposition_id) DO UPDATE SET penalty_applied = EXCLUDED.penalty_applied
        ');

        foreach ($blocs_state as $bloc_data) {
            foreach ($bloc_data['chosenPropositionIds'] ?? [] as $prop_id) {
                if (!isset($valid_prop_ids_set[$prop_id])) {
                    error_log("Warning: Skipping invalid proposition_id {$prop_id} for attempt {$attempt_id}");
                    continue;
                }
                $penalty = $bloc_data['appliedPenalties'][$prop_id] ?? null;

                $stmt_insert_answer->execute(['aid' => $attempt_id, 'prop_id' => $prop_id, 'penalty' => $penalty]);
            }
        }
    }

    // 2. Recalculate the entire score server-side to ensure correctness
    // Fetch all project and attempt data needed for calculation
    $stmt_project_data = $db->prepare("
        SELECT pb.bloc_id, bp.proposition_id, bp.solution_points
        FROM project_blocs pb
        LEFT JOIN bloc_propositions bp ON pb.bloc_id = bp.bloc_id
        WHERE pb.project_id = ?
        ORDER BY pb.sequence_number, bp.proposition_id
    ");
    $stmt_project_data->execute([$attempt['project_id']]);
    $project_data = $stmt_project_data->fetchAll(PDO::FETCH_ASSOC);

    $stmt_answers = $db->prepare("
        SELECT aa.proposition_id, aa.penalty_applied, bp.bloc_id, bp.solution_points
        FROM attempt_answers aa
        JOIN bloc_propositions bp ON aa.proposition_id = bp.proposition_id
        WHERE aa.attempt_id = ?
    ");
    $stmt_answers->execute([$attempt_id]);
    $answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

    // Group data by bloc for easier processing
    $blocs = [];
    $props_by_bloc = [];
    foreach ($project_data as $row) {
        $blocs[$row['bloc_id']] = true; // Just to get a unique list of bloc_ids
        if ($row['proposition_id']) {
            $props_by_bloc[$row['bloc_id']][] = $row;
        }
    }

    $answers_by_bloc = [];
    foreach ($answers as $answer) {
        $answers_by_bloc[$answer['bloc_id']][] = $answer;
    }

    // Calculate the normalized score for each bloc
    $bloc_normalized_scores = [];
    $is_attempt_dead = false; // Flag for the whole attempt

    foreach (array_keys($blocs) as $bloc_id) {
        $raw_score = 0;
        $is_bloc_dead = false;

        if (isset($answers_by_bloc[$bloc_id])) {
            foreach ($answers_by_bloc[$bloc_id] as $answer) {
                if ($answer['solution_points'] === 'dead' || $answer['penalty_applied'] === 'dead') {
                    $is_bloc_dead = true;
                    $is_attempt_dead = true; // Set the attempt-level flag
                    break;
                }
                $raw_score += (int)($answer['solution_points'] ?? 0) + (int)($answer['penalty_applied'] ?? 0);
            }
        } else {
            // A bloc with no answers is considered "dead" with a score of 0, but doesn't make the whole attempt dead
            $is_bloc_dead = true;
        }

        if ($is_bloc_dead) {
            $bloc_normalized_scores[$bloc_id] = 0;
            continue;
        }

        // Calculate the possible point range for this bloc
        $min_points_sum = 0;
        $max_points_sum = 0;
        foreach ($props_by_bloc[$bloc_id] ?? [] as $prop) {
            if ($prop['solution_points'] !== 'dead' && is_numeric($prop['solution_points'])) {
                $points = (int)$prop['solution_points'];
                $points < 0 ? ($min_points_sum += $points) : ($max_points_sum += $points);
            }
        }

        // Apply the normalization formula
        $range = $max_points_sum + abs($min_points_sum);
        if ($range > 0) {
            $normalized_score = (($raw_score + abs($min_points_sum)) / $range) * 20;
            $bloc_normalized_scores[$bloc_id] = round($normalized_score, 2);
        } else {
            $bloc_normalized_scores[$bloc_id] = 0;
        }
    }

    // Calculate final score
    $total_normalized_score = 0;
    if ($is_attempt_dead) {
        $total_normalized_score = 0;
    } elseif (!empty($bloc_normalized_scores)) {
        $total_normalized_score = round(array_sum($bloc_normalized_scores) / count($bloc_normalized_scores), 2);
    }

    // 3. Update the attempt, lock it, and save the final score
    $stmt_update_attempt = $db->prepare('UPDATE attempts SET locked = TRUE, result = :result, updated_at = NOW() WHERE attempt_id = :aid');
    $stmt_update_attempt->execute(['result' => $total_normalized_score, 'aid' => $attempt_id]);

    $db->commit();

    // Redirect to the results page
    $redirect_url = 'attempt-result.php?attempt_id=' . $attempt_id;
    if ($attempt['is_guest'] && $share_token) {
        $redirect_url .= '&share_token=' . urlencode($share_token);
    }
    header('Location: ' . $redirect_url);
    exit;

} catch (Exception $e) {
    $db->rollBack();
    error_log('Error saving attempt: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    die("An error occurred while saving your results: " . htmlspecialchars($e->getMessage()));
}
