<?php
// background-save.php
// This script receives a JSON payload and makes the database match it.

require_once __DIR__ . '/../powertrain/db.php';
require_once __DIR__ . '/../powertrain/auth.php';

// Only accept POST requests with JSON content
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SERVER['CONTENT_TYPE']) || $_SERVER['CONTENT_TYPE'] !== 'application/json') {
    http_response_code(400); // Bad Request
    exit;
}

header('Content-Type: application/json');
require_login();
$user = $_SESSION['user'];
$db = get_db();

// Get the complete project state from the request body
$json_payload = file_get_contents('php://input');
$project_data = json_decode($json_payload, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON data.']);
    exit;
}

$project_id = (int)($project_data['project_id'] ?? 0);
$project_name = trim($project_data['project_name'] ?? '');

// A project must have a name to be saved.
if (empty($project_name)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Project name cannot be empty.']);
    exit;
}

$db->beginTransaction();

try {
    // 1. Upsert (Update or Insert) the Project itself
    if ($project_id > 0) {
        // Verify ownership before updating
        $stmt = $db->prepare("SELECT user_id FROM user_projects WHERE project_id = :pid");
        $stmt->execute(['pid' => $project_id]);
        if ($stmt->fetchColumn() !== $user['email']) {
            throw new Exception("Authorization error: User does not own this project.");
        }
        $stmt = $db->prepare("UPDATE user_projects SET project_name = :name, updated_at = NOW() WHERE project_id = :pid");
        $stmt->execute(['name' => $project_name, 'pid' => $project_id]);
    } else {
        // Insert new project and get the new ID
        $stmt = $db->prepare("INSERT INTO user_projects (user_id, project_name) VALUES (:uid, :name)");
        $stmt->execute(['uid' => $user['email'], 'name' => $project_name]);
        $project_id = $db->lastInsertId();
    }

    $blocs_from_frontend = $project_data['blocs'] ?? [];
    $final_bloc_ids = [];
    $temp_to_real_prop_ids = [];

    // 2. Reconcile Blocs
    foreach ($blocs_from_frontend as $index => $bloc_data) {
        $bloc_id = (int)($bloc_data['bloc_id'] ?? 0);
        $params = [
            'pid' => $project_id,
            'pt' => $bloc_data['problem_text'] ?? '',
            'tl' => (int)($bloc_data['time_limit_seconds'] ?? 300),
            'seq' => $index + 1
        ];

        if ($bloc_id > 0) {
            $params['bid'] = $bloc_id;
            $stmt = $db->prepare("UPDATE project_blocs SET project_id = :pid, problem_text = :pt, time_limit_seconds = :tl, sequence_number = :seq, updated_at = NOW() WHERE bloc_id = :bid");
        } else {
            $stmt = $db->prepare("INSERT INTO project_blocs (project_id, problem_text, time_limit_seconds, sequence_number) VALUES (:pid, :pt, :tl, :seq)");
        }
        $stmt->execute($params);
        
        if ($bloc_id <= 0) {
            $bloc_id = $db->lastInsertId();
        }
        $final_bloc_ids[] = $bloc_id;

        // 3. Reconcile Propositions for this Bloc
        $propositions_from_frontend = $bloc_data['propositions'] ?? [];
        $final_prop_ids_for_bloc = [];

        foreach ($propositions_from_frontend as $prop_data) {
            $prop_id = (int)($prop_data['proposition_id'] ?? 0);
            $temp_prop_id = $prop_data['temp_id'] ?? null; // Keep track of temporary IDs
            
            $prop_params = [
                'bid' => $bloc_id,
                'pt' => $prop_data['proposition_text'] ?? '',
                'st' => $prop_data['solution_text'] ?? '',
                'sp' => $prop_data['solution_points'] ?? '0',
                'pen' => ($prop_data['penalty_value_if_chosen_early'] !== '') ? $prop_data['penalty_value_if_chosen_early'] : null,
                'precedent' => null, // We'll set this in a second pass
            ];

            if ($prop_id > 0) {
                $prop_params['pid'] = $prop_id;
                $stmt = $db->prepare("UPDATE bloc_propositions SET bloc_id=:bid, proposition_text=:pt, solution_text=:st, solution_points=:sp, penalty_value_if_chosen_early=:pen, precedent_proposition_for_penalty_id=:precedent, updated_at=NOW() WHERE proposition_id = :pid");
            } else {
                $stmt = $db->prepare("INSERT INTO bloc_propositions (bloc_id, proposition_text, solution_text, solution_points, penalty_value_if_chosen_early, precedent_proposition_for_penalty_id) VALUES (:bid, :pt, :st, :sp, :pen, :precedent)");
            }
            $stmt->execute($prop_params);

            if ($prop_id <= 0) {
                $prop_id = $db->lastInsertId();
                if ($temp_prop_id) {
                    $temp_to_real_prop_ids[$temp_prop_id] = $prop_id; // Map temp ID to new real ID
                }
            }
            $final_prop_ids_for_bloc[] = $prop_id;
        }

        // Delete any propositions in the DB that weren't in the frontend list for this bloc
        if (empty($final_prop_ids_for_bloc)) {
            $stmt = $db->prepare("DELETE FROM bloc_propositions WHERE bloc_id = ?");
            $stmt->execute([$bloc_id]);
        } else {
            $placeholders = implode(',', array_fill(0, count($final_prop_ids_for_bloc), '?'));
            $stmt = $db->prepare("DELETE FROM bloc_propositions WHERE bloc_id = ? AND proposition_id NOT IN ($placeholders)");
            $stmt->execute(array_merge([$bloc_id], $final_prop_ids_for_bloc));
        }
    }
    
    // Delete any blocs in the DB that weren't in the frontend list for this project
    if (empty($final_bloc_ids)) {
        $stmt = $db->prepare("DELETE FROM project_blocs WHERE project_id = ?");
        $stmt->execute([$project_id]);
    } else {
        $placeholders = implode(',', array_fill(0, count($final_bloc_ids), '?'));
        $stmt = $db->prepare("DELETE FROM project_blocs WHERE project_id = ? AND bloc_id NOT IN ($placeholders)");
        $stmt->execute(array_merge([$project_id], $final_bloc_ids));
    }
    
    // 4. Second Pass: Update Precedent Proposition IDs
    // This must be done after all propositions have been created and we have their real IDs.
    $stmt_precedent = $db->prepare("UPDATE bloc_propositions SET precedent_proposition_for_penalty_id = :precedent_id WHERE proposition_id = :prop_id");
    foreach ($blocs_from_frontend as $bloc_data) {
        foreach ($bloc_data['propositions'] as $prop_data) {
            $prop_id = (int)($prop_data['proposition_id'] ?? 0);
            $precedent_id = $prop_data['precedent_proposition_for_penalty_id'] ?? null;
            
            // If the prop_id was temporary, find its real ID
            if ($prop_id <= 0 && isset($prop_data['temp_id'])) {
                $prop_id = $temp_to_real_prop_ids[$prop_data['temp_id']] ?? 0;
            }
            
            // If the precedent_id was temporary, find its real ID
            if ($precedent_id && !is_numeric($precedent_id)) {
                 $precedent_id = $temp_to_real_prop_ids[$precedent_id] ?? null;
            }

            if ($prop_id > 0) {
                $stmt_precedent->execute(['precedent_id' => $precedent_id, 'prop_id' => $prop_id]);
            }
        }
    }


    $db->commit();

    // Send back a success response with the master project ID
    echo json_encode(['status' => 'success', 'project_id' => $project_id]);

} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    error_log("Background save failed: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
