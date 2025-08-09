<?php
require_once __DIR__ . '/../powertrain/db.php';
require_once __DIR__ . '/../powertrain/auth.php';

require_login();
$user = $_SESSION['user'];
$db = get_db();

$scroll_pos = isset($_POST['scroll_position']) ? (int)$_POST['scroll_position'] : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /dashboard.php");
    exit;
}

/**
 * Handles the upload of a single file.
 * @param array $file The file array from $_FILES.
 * @param string $upload_dir_base The base directory for uploads.
 * @return string|null The path to the uploaded file or null on failure.
 */
function upload_image($file, $upload_dir_base = 'uploads/project_images/')
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $upload_dir = rtrim($upload_dir_base, '/') . '/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        error_log("Security: Not an uploaded file.");
        return null;
    }

    $ext = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

    if (!in_array($ext, $allowed_extensions)) {
        error_log("Invalid file type: " . $ext);
        return null;
    }

    $new_filename = uniqid('img_', true) . '.' . $ext;
    $destination = $upload_dir . $new_filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return 'uploads/project_images/' . $new_filename;
    }

    error_log("Failed to move uploaded file to " . $destination);
    return null;
}

/**
 * Processes multiple new image uploads for a given parent entity (bloc or proposition).
 * @param string $file_field_name The name of the file input field.
 * @param int $parent_id The ID of the parent entity.
 * @param PDO $db The database connection.
 * @param string $insert_sql The SQL for inserting the image record.
 * @param string $parent_id_param_name The name of the parent ID parameter in the SQL.
 */
function process_new_images($file_field_name, $parent_id, $db, $insert_sql, $parent_id_param_name)
{
    if (!isset($_FILES[$file_field_name]) || !is_array($_FILES[$file_field_name]['name'])) {
        return;
    }

    foreach ($_FILES[$file_field_name]['name'] as $key => $name) {
        if ($_FILES[$file_field_name]['error'][$key] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $file_data = [
            'name' => $_FILES[$file_field_name]['name'][$key],
            'type' => $_FILES[$file_field_name]['type'][$key],
            'tmp_name' => $_FILES[$file_field_name]['tmp_name'][$key],
            'error' => $_FILES[$file_field_name]['error'][$key],
            'size' => $_FILES[$file_field_name]['size'][$key]
        ];

        if ($image_path = upload_image($file_data)) {
            $stmt = $db->prepare($insert_sql);
            $stmt->execute([$parent_id_param_name => $parent_id, 'path' => $image_path]);
        }
    }
}

$project_id = (int)($_POST['project_id'] ?? 0);
$project_name = trim($_POST['project_name'] ?? 'Projet sans nom');
$blocs = $_POST['blocs'] ?? [];

$db->beginTransaction();

try {
    // 1. Create or Update Project
    if ($project_id > 0) {
        $stmt = $db->prepare("SELECT user_id FROM user_projects WHERE project_id = :pid");
        $stmt->execute(['pid' => $project_id]);
        if ($stmt->fetchColumn() !== $user['email']) {
            throw new Exception("Accès non autorisé à ce projet.");
        }
        $stmt = $db->prepare("UPDATE user_projects SET project_name = :name, updated_at = NOW() WHERE project_id = :pid");
        $stmt->execute(['name' => $project_name, 'pid' => $project_id]);
    } else {
        $stmt = $db->prepare("INSERT INTO user_projects (user_id, project_name) VALUES (:uid, :name)");
        $stmt->execute(['uid' => $user['email'], 'name' => $project_name]);
        $project_id = $db->lastInsertId();
    }

    $temp_to_new_prop_ids = [];
    $all_bloc_ids = [];
    $propositions_to_update_precedent = [];

    // 2. Process Blocs and their contents
    foreach ($blocs as $bloc_index => $bloc_data) {
        $bloc_id = !empty($bloc_data['bloc_id']) ? (int)$bloc_data['bloc_id'] : null;

        $bloc_params = [
            'pt' => $bloc_data['problem_text'],
            'tl' => empty($bloc_data['time_limit_seconds']) ? null : (int)$bloc_data['time_limit_seconds'],
            'seq' => $bloc_index + 1,
            'pid' => $project_id
        ];

        if ($bloc_id) {
            $bloc_params['bid'] = $bloc_id;
            $stmt = $db->prepare("UPDATE project_blocs SET problem_text = :pt, time_limit_seconds = :tl, sequence_number = :seq, updated_at = NOW() WHERE bloc_id = :bid AND project_id = :pid");
            $stmt->execute($bloc_params);
        } else {
            $stmt = $db->prepare("INSERT INTO project_blocs (project_id, problem_text, time_limit_seconds, sequence_number) VALUES (:pid, :pt, :tl, :seq)");
            $stmt->execute($bloc_params);
            $bloc_id = $db->lastInsertId();
        }
        $all_bloc_ids[] = $bloc_id;

        // 2a. Process Bloc Images
        if (!empty($bloc_data['existing_images'])) {
            foreach ($bloc_data['existing_images'] as $img_data) {
                if (!empty($img_data['delete'])) {
                    $stmt = $db->prepare("UPDATE bloc_images SET is_deleted = TRUE WHERE image_id = :iid AND bloc_id = :bid");
                    $stmt->execute(['iid' => (int)$img_data['image_id'], 'bid' => $bloc_id]);
                }
            }
        }
        process_new_images("bloc_new_images_{$bloc_index}", $bloc_id, $db, "INSERT INTO bloc_images (bloc_id, image_path) VALUES (:bid, :path)", 'bid');

        // 2b. Process Propositions
        $all_prop_ids_for_bloc = [];
        if (!empty($bloc_data['propositions'])) {
            foreach ($bloc_data['propositions'] as $prop_index => $prop_data) {
                $temp_prop_id = $prop_data['proposition_id'] ?? null;
                $prop_id = is_numeric($temp_prop_id) ? (int)$temp_prop_id : null;

                $prop_params = [
                    'pt' => $prop_data['proposition_text'],
                    'st' => $prop_data['solution_text'],
                    'sp' => $prop_data['solution_points'],
                    'pen' => ($prop_data['penalty_value_if_chosen_early'] !== '') ? $prop_data['penalty_value_if_chosen_early'] : null,
                    'bid' => $bloc_id
                ];

                if ($prop_id) {
                    $prop_params['pid'] = $prop_id;
                    $stmt = $db->prepare("UPDATE bloc_propositions SET proposition_text = :pt, solution_text = :st, solution_points = :sp, penalty_value_if_chosen_early = :pen, updated_at = NOW() WHERE proposition_id = :pid AND bloc_id = :bid");
                    $stmt->execute($prop_params);
                } else {
                    $stmt = $db->prepare("INSERT INTO bloc_propositions (bloc_id, proposition_text, solution_text, solution_points, penalty_value_if_chosen_early) VALUES (:bid, :pt, :st, :sp, :pen)");
                    $stmt->execute($prop_params);
                    $prop_id = $db->lastInsertId();
                    if ($temp_prop_id) {
                        $temp_to_new_prop_ids[$temp_prop_id] = $prop_id;
                    }
                }
                $all_prop_ids_for_bloc[] = $prop_id;

                if (!empty($prop_data['precedent_proposition_for_penalty_id'])) {
                    $propositions_to_update_precedent[] = ['prop_id' => $prop_id, 'precedent_id' => $prop_data['precedent_proposition_for_penalty_id']];
                }

                // Process Proposition Images
                if (!empty($prop_data['existing_images'])) {
                    foreach ($prop_data['existing_images'] as $img_data) {
                        if (!empty($img_data['delete'])) {
                            $stmt_img_del = $db->prepare("UPDATE proposition_images SET is_deleted = TRUE WHERE image_id = :iid AND proposition_id = :pid");
                            $stmt_img_del->execute(['iid' => (int)$img_data['image_id'], 'pid' => $prop_id]);
                        }
                    }
                }
                process_new_images("prop_new_images_{$bloc_index}_{$prop_index}", $prop_id, $db, "INSERT INTO proposition_images (proposition_id, image_path) VALUES (:pid, :path)", 'pid');
            }
        }

        // 2c. Delete removed propositions from the bloc
        if (!empty($all_prop_ids_for_bloc)) {
            $placeholders = implode(',', array_fill(0, count($all_prop_ids_for_bloc), '?'));
            $stmt = $db->prepare("DELETE FROM bloc_propositions WHERE bloc_id = ? AND proposition_id NOT IN ($placeholders)");
            $stmt->execute(array_merge([$bloc_id], $all_prop_ids_for_bloc));
        } else {
            $stmt = $db->prepare("DELETE FROM bloc_propositions WHERE bloc_id = ?");
            $stmt->execute([$bloc_id]);
        }
    }

    // 3. Delete removed blocs from the project
    if (!empty($all_bloc_ids)) {
        $placeholders = implode(',', array_fill(0, count($all_bloc_ids), '?'));
        $stmt = $db->prepare("DELETE FROM project_blocs WHERE project_id = ? AND bloc_id NOT IN ($placeholders)");
        $stmt->execute(array_merge([$project_id], $all_bloc_ids));
    } else {
        $stmt = $db->prepare("DELETE FROM project_blocs WHERE project_id = ?");
        $stmt->execute([$project_id]);
    }

    // 4. Second pass: Update precedent IDs
    if (!empty($propositions_to_update_precedent)) {
        $stmt = $db->prepare("UPDATE bloc_propositions SET precedent_proposition_for_penalty_id = :precedent_id WHERE proposition_id = :prop_id");
        foreach ($propositions_to_update_precedent as $update_data) {
            $precedent_id = $update_data['precedent_id'];
            // If the precedent was a new proposition, find its new DB ID
            if (isset($temp_to_new_prop_ids[$precedent_id])) {
                $precedent_id = $temp_to_new_prop_ids[$precedent_id];
            }
            $stmt->execute(['precedent_id' => $precedent_id, 'prop_id' => $update_data['prop_id']]);
        }
    }

    $db->commit();
    header("Location: edit.php?project_id={$project_id}&status=success&scroll_position={$scroll_pos}");
    exit;

} catch (Exception $e) {
    $db->rollBack();
    error_log("Error in save-project.php: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
    die("Une erreur est survenue lors de la sauvegarde: " . htmlspecialchars($e->getMessage()));
}
