<?php
// Current Date and Time (UTC): 2025-06-15 15:05:18
// Current User: ihebchagra
require_once __DIR__ . '/../powertrain/db.php';
require_once __DIR__ . '/../powertrain/auth.php';

$db = get_db();

// Check if data was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    die("Method not allowed");
}

// Get the POST data
$attempt_id = isset($_POST['attempt_id']) ? (int)$_POST['attempt_id'] : 0;
$overall_score = isset($_POST['overall_score']) ? (int)$_POST['overall_score'] : 0; // Raw score from client (will be replaced)
$blocs_state = isset($_POST['blocs_state']) ? json_decode($_POST['blocs_state'], true) : [];

// Validate attempt ID
if (!$attempt_id) {
    http_response_code(400);
    die("Invalid attempt ID");
}

// Get attempt info and project owner in a single query
$stmt_attempt = $db->prepare('
    SELECT a.*, p.user_id as project_owner, p.project_id
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

// Check authorization
if ($attempt['locked']) {
    http_response_code(403);
    die("This attempt is already locked and cannot be modified");
}

$share_token = isset($_POST['share_token']) ? $_POST['share_token'] : null;

if ($attempt['is_guest']) {
    if (!$share_token) {
        http_response_code(403);
        die("Token required for guest attempts");
    }
    
    // Validate token
    $stmt_token = $db->prepare('SELECT share_token FROM project_shares WHERE project_id = :pid AND share_token = :token AND share_type = \'exam\'');
    $stmt_token->execute([
        'pid' => $attempt['project_id'],
        'token' => $share_token
    ]);
    
    if (!$stmt_token->fetch()) {
        http_response_code(403);
        die("Invalid token for this attempt");
    }
} else {
    // Not a guest, check that logged in user owns the PROJECT associated with this attempt
    if (!isset($_SESSION['user']) || $_SESSION['user']['email'] !== $attempt['project_owner']) {
        http_response_code(403);
        die("You don't have permission to save this attempt");
    }
}

// Begin database transaction
$db->beginTransaction();

try {
    // Get valid proposition IDs for this project
    $stmt_valid_props = $db->prepare("
        SELECT bp.proposition_id
        FROM bloc_propositions bp
        JOIN project_blocs pb ON bp.bloc_id = pb.bloc_id
        WHERE pb.project_id = ?
    ");
    $stmt_valid_props->execute([$attempt['project_id']]);
    $valid_prop_ids = $stmt_valid_props->fetchAll(PDO::FETCH_COLUMN);
    $valid_prop_ids_set = array_flip($valid_prop_ids); // For faster lookups

    // 1. Save attempt answers first
    if (!empty($blocs_state)) {
        $stmt_insert_answer = $db->prepare('
            INSERT INTO attempt_answers (attempt_id, proposition_id, penalty_applied)
            VALUES (:aid, :prop_id, :penalty)
            ON CONFLICT (attempt_id, proposition_id) DO UPDATE
            SET penalty_applied = :penalty
        ');
        
        foreach ($blocs_state as $bloc_id => $bloc_data) {
            if (!isset($bloc_data['chosenPropositionIds']) || !is_array($bloc_data['chosenPropositionIds'])) {
                continue;
            }
            
            // Process each chosen proposition
            foreach ($bloc_data['chosenPropositionIds'] as $prop_id) {
                // Skip invalid proposition IDs
                if (!isset($valid_prop_ids_set[$prop_id])) {
                    error_log("Warning: Skipping invalid proposition_id {$prop_id} for attempt {$attempt_id}");
                    continue;
                }
                
                $penalty = null;
                if (isset($bloc_data['appliedPenalties']) && isset($bloc_data['appliedPenalties'][$prop_id])) {
                    $penalty = $bloc_data['appliedPenalties'][$prop_id];
                }
                
                $stmt_insert_answer->execute([
                    'aid' => $attempt_id,
                    'prop_id' => $prop_id,
                    'penalty' => $penalty
                ]);
            }
        }
    }
    
    // 2. Recalculate normalized score based on the official formula
    
    // Get all blocs for this project
    $stmt_blocs = $db->prepare("SELECT * FROM project_blocs WHERE project_id = ?");
    $stmt_blocs->execute([$attempt['project_id']]);
    $blocs = $stmt_blocs->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all propositions for this project
    $stmt_props = $db->prepare("
        SELECT bp.* 
        FROM bloc_propositions bp
        JOIN project_blocs pb ON bp.bloc_id = pb.bloc_id
        WHERE pb.project_id = ?
    ");
    $stmt_props->execute([$attempt['project_id']]);
    $all_props = $stmt_props->fetchAll(PDO::FETCH_ASSOC);
    
    // Group propositions by bloc
    $props_by_bloc = [];
    foreach ($all_props as $prop) {
        $bloc_id = $prop['bloc_id'];
        if (!isset($props_by_bloc[$bloc_id])) {
            $props_by_bloc[$bloc_id] = [];
        }
        $props_by_bloc[$bloc_id][] = $prop;
    }
    
    // Get all answers for this attempt
    $stmt_answers = $db->prepare("
        SELECT aa.*, bp.bloc_id, bp.solution_points
        FROM attempt_answers aa
        JOIN bloc_propositions bp ON aa.proposition_id = bp.proposition_id
        WHERE aa.attempt_id = ?
    ");
    $stmt_answers->execute([$attempt_id]);
    $answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);
    
    // Group answers by bloc
    $answers_by_bloc = [];
    foreach ($answers as $answer) {
        $bloc_id = $answer['bloc_id'];
        if (!isset($answers_by_bloc[$bloc_id])) {
            $answers_by_bloc[$bloc_id] = [];
        }
        $answers_by_bloc[$bloc_id][] = $answer;
    }
    
    // Calculate raw scores for each bloc
    $bloc_raw_scores = [];
    $bloc_is_dead = [];
    foreach ($blocs as $bloc) {
        $bloc_id = $bloc['bloc_id'];
        $raw_score = 0;
        $has_dead = false;
        
        if (isset($answers_by_bloc[$bloc_id])) {
            foreach ($answers_by_bloc[$bloc_id] as $answer) {
                if ($answer['solution_points'] === 'dead' || $answer['penalty_applied'] === 'dead') {
                    $has_dead = true;
                    break; // If any "dead" penalty or solution, score is 0
                }
                
                // Add points from solution
                if ($answer['solution_points'] !== null && $answer['solution_points'] !== 'dead') {
                    $raw_score += (int)$answer['solution_points'];
                }
                
                // Add penalty if applicable
                if ($answer['penalty_applied'] !== null && $answer['penalty_applied'] !== 'dead') {
                    $raw_score += (int)$answer['penalty_applied'];
                }
            }
        } else {
            $has_dead = true;
        }

        
        // If there was a "dead" solution or penalty, set score to 0
        if ($has_dead) {
            $raw_score = 0;
            $bloc_raw_scores[$bloc_id] = $raw_score;
            $bloc_is_dead[$bloc_id] = true;
        } else {
            $bloc_raw_scores[$bloc_id] = $raw_score;
            $bloc_is_dead[$bloc_id] = false;
        }
        
    }
    
    // Calculate min and max possible scores for each bloc
    $bloc_normalized_scores = [];
    
    foreach ($blocs as $bloc) {
        $bloc_id = $bloc['bloc_id'];
        $min_score = 0;
        $max_score = 0;
        
        if (isset($props_by_bloc[$bloc_id])) {
            foreach ($props_by_bloc[$bloc_id] as $prop) {
                if ($prop['solution_points'] === 'dead') {
                    continue; // Skip "dead" propositions in min/max calculation
                }
                
                $points = (int)$prop['solution_points'];
                if ($points < 0) {
                    $min_score += $points;
                } else if ($points >= 0) {
                    $max_score += $points;
                }
            }
        }
        
        // Calculate range: max + |min| (no +1)
        $range = $max_score + abs($min_score);
        
        // Calculate normalized score: (raw_score + |min|) / range * 20
        $raw_score = $bloc_raw_scores[$bloc_id] ?? 0;
        
        // Prevent division by zero
        if ($bloc_is_dead[$bloc_id] == true ) {
            $bloc_normalized_scores[$bloc_id] = 0;
        } else if ($range > 0) {
            $normalized_score = ($raw_score + abs($min_score)) / $range * 20;
            // Round to 2 decimal places
            $bloc_normalized_scores[$bloc_id] = round($normalized_score, 2);
        } else {
            $bloc_normalized_scores[$bloc_id] = 0; // Default to 0 if range is 0
        }
    }
    
    // Calculate total normalized score (average of bloc scores)
    $total_normalized_score = 0;
    if (!empty($bloc_normalized_scores)) {
        $total_normalized_score = round(array_sum($bloc_normalized_scores) / count($bloc_normalized_scores), 2);
    }
    
    // 3. Update attempt with the calculated normalized score
    $stmt_update_attempt = $db->prepare('UPDATE attempts SET locked = TRUE, result = :result, updated_at = NOW() WHERE attempt_id = :aid');
    $stmt_update_attempt->execute([
        'result' => $total_normalized_score,
        'aid' => $attempt_id
    ]);
    
    // Commit transaction
    $db->commit();
    
    // Determine redirect URL based on user type
    if ($attempt['is_guest']) {
        // For guests, redirect to results page with token
        header('Location: attempt-result.php?attempt_id=' . $attempt_id . '&share_token=' . urlencode($share_token));
    } else {
        // For logged in users, redirect to results page
        header('Location: attempt-result.php?attempt_id=' . $attempt_id);
    }
    exit;
    
} catch (Exception $e) {
    // Roll back transaction on error
    $db->rollBack();
    error_log('Error saving attempt: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    die("An error occurred while saving your results: " . htmlspecialchars($e->getMessage()));
}
?>
