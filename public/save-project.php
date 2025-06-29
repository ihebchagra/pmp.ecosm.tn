<?php
require_once __DIR__ . '/../powertrain/db.php';
require_once __DIR__ . '/../powertrain/auth.php';

require_login();
$user = $_SESSION['user'];
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /dashboard.php");
    exit;
}

// Upload function for a single file
function upload_image($file, $upload_dir_base = 'uploads/project_images/') {
    // ... (This function remains unchanged)
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    
    $upload_dir = rtrim($upload_dir_base, '/') . '/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $tmp_name = $file['tmp_name'];
    if (!is_uploaded_file($tmp_name)) {
        error_log("Security: Not an uploaded file.");
        return null;
    }
    
    $filename = basename($file['name']);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
    
    if (!in_array($ext, $allowed_extensions)) {
        error_log("Invalid file type: " . $ext);
        return null;
    }
    
    $new_filename = uniqid('img_', true) . '.' . $ext;
    $destination = $upload_dir . $new_filename;
    
    if (move_uploaded_file($tmp_name, $destination)) {
        return 'uploads/project_images/' . $new_filename;
    } else {
        error_log("Failed to move uploaded file to " . $destination);
        return null;
    }
}


$project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$project_name = $_POST['project_name'] ?? 'Projet sans nom';
$blocs = $_POST['blocs'] ?? [];

$db->beginTransaction();

try {
    // 1. Create or update project
    if ($project_id > 0) {
        // ... (Code for updating project is unchanged)
        $stmt = $db->prepare("SELECT user_id FROM user_projects WHERE project_id = :pid");
        $stmt->execute(['pid' => $project_id]);
        $owner_id = $stmt->fetchColumn();
        if ($owner_id !== $user['email']) {
            throw new Exception("Accès non autorisé à ce projet.");
        }
        $stmt = $db->prepare("UPDATE user_projects SET project_name = :name, updated_at = NOW() WHERE project_id = :pid");
        $stmt->execute(['name' => $project_name, 'pid' => $project_id]);
    } else {
        // ... (Code for creating project is unchanged)
        $stmt = $db->prepare("INSERT INTO user_projects (user_id, project_name) VALUES (:uid, :name)");
        $stmt->execute(['uid' => $user['email'], 'name' => $project_name]);
        $project_id = $db->lastInsertId();
    }
    
    $old_to_new_prop_ids = [];  
    $all_bloc_ids = [];         
    $propositions_to_update = []; // For second pass
    
    // 3. Process blocs and their contents
    foreach ($blocs as $bloc_index => $bloc_data) {
        $sequence_number = $bloc_index + 1;
        $bloc_id = !empty($bloc_data['bloc_id']) ? (int)$bloc_data['bloc_id'] : null;
        
        if ($bloc_id) {
            // Update existing bloc
            $stmt = $db->prepare("UPDATE project_blocs SET problem_text = :pt, time_limit_seconds = :tl, sequence_number = :seq, updated_at = NOW() WHERE bloc_id = :bid AND project_id = :pid");
            $stmt->execute([
                'pt' => $bloc_data['problem_text'],
                'tl' => empty($bloc_data['time_limit_seconds']) ? null : (int)$bloc_data['time_limit_seconds'],
                'seq' => $sequence_number,
                'bid' => $bloc_id,
                'pid' => $project_id
            ]);
        } else {
            // Create new bloc
            $stmt = $db->prepare("INSERT INTO project_blocs (project_id, problem_text, time_limit_seconds, sequence_number) VALUES (:pid, :pt, :tl, :seq)");
            $stmt->execute([
                'pid' => $project_id,
                'pt' => $bloc_data['problem_text'],
                'tl' => empty($bloc_data['time_limit_seconds']) ? null : (int)$bloc_data['time_limit_seconds'],
                'seq' => $sequence_number
            ]);
            $bloc_id = $db->lastInsertId();
        }
        $all_bloc_ids[] = $bloc_id;
        
        // 3a. Process bloc images
        // ... (This section remains unchanged)
        if (!empty($bloc_data['existing_images'])) {
            foreach ($bloc_data['existing_images'] as $img_data) {
                $image_id = (int)$img_data['image_id'];
                if (isset($img_data['delete']) && $img_data['delete'] == '1') {
                    $stmt = $db->prepare("UPDATE bloc_images SET is_deleted = TRUE WHERE image_id = :iid AND bloc_id = :bid");
                    $stmt->execute(['iid' => $image_id, 'bid' => $bloc_id]);
                }
            }
        }
        $file_field_name = "bloc_new_images_{$bloc_index}";
        if (isset($_FILES[$file_field_name]) && is_array($_FILES[$file_field_name]['name'])) {
            foreach ($_FILES[$file_field_name]['name'] as $key => $name) {
                if ($_FILES[$file_field_name]['error'][$key] !== UPLOAD_ERR_NO_FILE) {
                    $file_data = [
                        'name' => $_FILES[$file_field_name]['name'][$key],
                        'type' => $_FILES[$file_field_name]['type'][$key],
                        'tmp_name' => $_FILES[$file_field_name]['tmp_name'][$key],
                        'error' => $_FILES[$file_field_name]['error'][$key],
                        'size' => $_FILES[$file_field_name]['size'][$key]
                    ];
                    $image_path = upload_image($file_data);
                    if ($image_path) {
                        $stmt = $db->prepare("INSERT INTO bloc_images (bloc_id, image_path) VALUES (:bid, :path)");
                        $stmt->execute(['bid' => $bloc_id, 'path' => $image_path]);
                    }
                }
            }
        }
        
        // 3b. Process propositions
        $all_prop_ids = [];  // Keep track of proposition IDs for this bloc
        
        if (!empty($bloc_data['propositions'])) {
            // NEW: Note the added $prop_index
            foreach ($bloc_data['propositions'] as $prop_index => $prop_data) {
                $prop_id = !empty($prop_data['proposition_id']) ? (int)$prop_data['proposition_id'] : null;
                $precedent_id = $prop_data['precedent_proposition_for_penalty_id'] !== '' ? $prop_data['precedent_proposition_for_penalty_id'] : null;
                $penalty_value = $prop_data['penalty_value_if_chosen_early'] !== '' ? $prop_data['penalty_value_if_chosen_early'] : null;
                                
                if ($prop_id) {
                    // Update existing proposition
                    $stmt = $db->prepare("UPDATE bloc_propositions SET proposition_text = :pt, solution_text = :st, solution_points = :sp, penalty_value_if_chosen_early = :pen, updated_at = NOW() WHERE proposition_id = :pid AND bloc_id = :bid");
                    $stmt->execute([
                        'pt' => $prop_data['proposition_text'],
                        'st' => $prop_data['solution_text'],
                        'sp' => $prop_data['solution_points'],
                        'pen' => $penalty_value,
                        'pid' => $prop_id,
                        'bid' => $bloc_id
                    ]);
                } else {
                    // Create new proposition
                    $stmt = $db->prepare("INSERT INTO bloc_propositions (bloc_id, proposition_text, solution_text, solution_points, penalty_value_if_chosen_early) VALUES (:bid, :pt, :st, :sp, :pen)");
                    $stmt->execute([
                        'bid' => $bloc_id,
                        'pt' => $prop_data['proposition_text'],
                        'st' => $prop_data['solution_text'],
                        'sp' => $prop_data['solution_points'],
                        'pen' => $penalty_value
                    ]);
                    $prop_id = $db->lastInsertId();
                    
                    if (!empty($precedent_id)) {
                        $old_to_new_prop_ids["temp_{$precedent_id}"] = $prop_id;
                    }
                }
                $all_prop_ids[] = $prop_id;
                
                if ($precedent_id !== null) {
                    $propositions_to_update[] = [
                        'prop_id' => $prop_id,
                        'precedent_id' => $precedent_id
                    ];
                }

                // --- NEW: Process Proposition Images ---
                // Handle existing proposition images
                if (!empty($prop_data['existing_images'])) {
                    foreach ($prop_data['existing_images'] as $img_data) {
                        $image_id = (int)$img_data['image_id'];
                        if (isset($img_data['delete']) && $img_data['delete'] == '1') {
                            // Mark image for deletion
                            $stmt_img_del = $db->prepare("UPDATE proposition_images SET is_deleted = TRUE WHERE image_id = :iid AND proposition_id = :pid");
                            $stmt_img_del->execute(['iid' => $image_id, 'pid' => $prop_id]);
                        }
                    }
                }
                
                // Handle new proposition images
                $prop_file_field_name = "prop_new_images_{$bloc_index}_{$prop_index}";
                if (isset($_FILES[$prop_file_field_name]) && is_array($_FILES[$prop_file_field_name]['name'])) {
                    foreach ($_FILES[$prop_file_field_name]['name'] as $key => $name) {
                        if ($_FILES[$prop_file_field_name]['error'][$key] !== UPLOAD_ERR_NO_FILE) {
                            $file_data = [
                                'name' => $_FILES[$prop_file_field_name]['name'][$key],
                                'type' => $_FILES[$prop_file_field_name]['type'][$key],
                                'tmp_name' => $_FILES[$prop_file_field_name]['tmp_name'][$key],
                                'error' => $_FILES[$prop_file_field_name]['error'][$key],
                                'size' => $_FILES[$prop_file_field_name]['size'][$key]
                            ];
                            
                            $image_path = upload_image($file_data);
                            if ($image_path) {
                                $stmt_img_ins = $db->prepare("INSERT INTO proposition_images (proposition_id, image_path) VALUES (:pid, :path)");
                                $stmt_img_ins->execute(['pid' => $prop_id, 'path' => $image_path]);
                            }
                        }
                    }
                }
                // --- END NEW ---
            }
        }
        
        // ... (Code for deleting propositions is unchanged. ON DELETE CASCADE will handle images.)
        if (!empty($all_prop_ids)) {
            $placeholders = implode(',', array_fill(0, count($all_prop_ids), '?'));
            $stmt = $db->prepare("DELETE FROM bloc_propositions WHERE bloc_id = ? AND proposition_id NOT IN ($placeholders)");
            $stmt->execute(array_merge([$bloc_id], $all_prop_ids));
        } else {
            $stmt = $db->prepare("DELETE FROM bloc_propositions WHERE bloc_id = ?");
            $stmt->execute([$bloc_id]);
        }
    }
    
    // ... (Code for deleting blocs is unchanged)
    if (!empty($all_bloc_ids)) {
        $placeholders = implode(',', array_fill(0, count($all_bloc_ids), '?'));
        $stmt = $db->prepare("DELETE FROM project_blocs WHERE project_id = ? AND bloc_id NOT IN ($placeholders)");
        $stmt->execute(array_merge([$project_id], $all_bloc_ids));
    } else {
        $stmt = $db->prepare("DELETE FROM project_blocs WHERE project_id = ?");
        $stmt->execute([$project_id]);
    }
    
    // ... (Second pass for precedents is unchanged)
    if (!empty($propositions_to_update)) {
        foreach ($propositions_to_update as $update_data) {
            $prop_id = $update_data['prop_id'];
            $precedent_id = $update_data['precedent_id'];
            
            if (isset($old_to_new_prop_ids["temp_{$precedent_id}"])) {
                $precedent_id = $old_to_new_prop_ids["temp_{$precedent_id}"];
            }
            
            $stmt = $db->prepare("UPDATE bloc_propositions SET precedent_proposition_for_penalty_id = :pid WHERE proposition_id = :prop_id");
            $stmt->execute(['pid' => $precedent_id, 'prop_id' => $prop_id]);
        }
    }
    
    $db->commit();
    header("Location: edit.php?project_id={$project_id}&status=success");
    exit;
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("Error in save-project.php: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
    die("Une erreur est survenue lors de la sauvegarde: " . htmlspecialchars($e->getMessage()));
}
?>
