<?php
// Current Date and Time (UTC): 2025-06-15 17:42:21
// Current User's Login: ihebchagra
require_once __DIR__ . '/../powertrain/db.php'; // For DB connection
$db = get_db();

// --- Configuration: Application Base Path ---
// This assumes copy-project.php is in a subdirectory (e.g., /actions_php/)
// of your main application web root.
// If copy-project.php is at the web root, this should be __DIR__
// If uploads/ is outside the web root, this path needs to be set carefully.
$app_base_path = dirname(__DIR__) . '/public/'; // Example: if script is /var/www/html/app/actions/copy.php, this is /var/www/html/app

$share_token_from_url = isset($_GET['share_token']) ? $_GET['share_token'] : null;
$project_id_from_url_for_owner_copy = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

$source_project = null;
$is_shared_access_by_token = false;
$current_user_email_from_session = null;

// Start session if not already started to check for logged-in user
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['user']['email'])) {
    $current_user_email_from_session = $_SESSION['user']['email'];
}

// --- Determine access type and fetch source project ---
if ($share_token_from_url) {
    $stmt_token = $db->prepare('SELECT p.* FROM project_shares s JOIN user_projects p ON s.project_id = p.project_id WHERE s.share_token = :token AND s.share_type = :type');
    $stmt_token->execute(['token' => $share_token_from_url, 'type' => 'copy']);
    $source_project = $stmt_token->fetch(PDO::FETCH_ASSOC);
    if ($source_project) {
        $is_shared_access_by_token = true;
    } else {
        die("Token de partage invalide ou ne permettant pas la copie de ce projet.");
    }
} elseif ($project_id_from_url_for_owner_copy > 0) {
    if (!$current_user_email_from_session) { // User must be logged in for this route
        require_once __DIR__ . '/../powertrain/auth.php'; require_login();
        $current_user_email_from_session = $_SESSION['user']['email']; // Re-assign after require_login
    }
    $stmt_project_owner = $db->prepare('SELECT * FROM user_projects WHERE project_id = :pid AND user_id = :uid');
    $stmt_project_owner->execute(['pid' => $project_id_from_url_for_owner_copy, 'uid' => $current_user_email_from_session]);
    $source_project = $stmt_project_owner->fetch(PDO::FETCH_ASSOC);
    if (!$source_project) {
        die("Projet introuvable ou vous n'êtes pas autorisé à le copier (ID: " . $project_id_from_url_for_owner_copy . ").");
    }
} else {
    die("Paramètres insuffisants pour identifier le projet à copier (token ou project_id requis).");
}

$error_message = null;
$success_message = null;
$file_operation_errors = []; // To collect errors during file copy

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_copy']) && $source_project) {
    $target_user_email = null;
    if ($is_shared_access_by_token) {
        $target_user_email = isset($_POST['target_user_email']) ? trim($_POST['target_user_email']) : null;
        if (!filter_var($target_user_email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Veuillez fournir une adresse e-mail valide pour le compte destinataire.";
        }
    } elseif ($current_user_email_from_session && !$is_shared_access_by_token) {
        $target_user_email = $current_user_email_from_session;
    } else {
        $error_message = "Impossible de déterminer l'utilisateur destinataire.";
    }

    if (!$error_message && $target_user_email) {
        $db->beginTransaction();
        try {
            $new_project_name = isset($_POST['new_project_name']) ? trim($_POST['new_project_name']) : ($source_project['project_name'] . ' (Copie)');
            if (empty($new_project_name)) $new_project_name = $source_project['project_name'] . ' (Copie)';

            $stmt_new_project = $db->prepare('INSERT INTO user_projects (user_id, project_name) VALUES (:uid, :name)');
            $stmt_new_project->execute(['uid' => $target_user_email, 'name' => $new_project_name]);
            $new_project_id = $db->lastInsertId();

            $stmt_source_blocs = $db->prepare('SELECT * FROM project_blocs WHERE project_id = :pid ORDER BY sequence_number');
            $stmt_source_blocs->execute(['pid' => $source_project['project_id']]);
            $source_blocs_data = $stmt_source_blocs->fetchAll(PDO::FETCH_ASSOC);
            
            $bloc_id_map = []; $prop_id_map = [];

            foreach ($source_blocs_data as $source_bloc) {
                $stmt_insert_bloc = $db->prepare('INSERT INTO project_blocs (project_id, problem_text, sequence_number, time_limit_seconds) VALUES (:pid, :text, :seq, :time)');
                $stmt_insert_bloc->execute(['pid' => $new_project_id, 'text' => $source_bloc['problem_text'], 'seq' => $source_bloc['sequence_number'], 'time' => $source_bloc['time_limit_seconds']]);
                $new_bloc_id = $db->lastInsertId();
                $bloc_id_map[$source_bloc['bloc_id']] = $new_bloc_id;

                $stmt_source_images = $db->prepare('SELECT * FROM bloc_images WHERE bloc_id = :bid AND is_deleted = FALSE');
                $stmt_source_images->execute(['bid' => $source_bloc['bloc_id']]);
                $source_images_data = $stmt_source_images->fetchAll(PDO::FETCH_ASSOC);

                foreach ($source_images_data as $image) {
                    $source_image_db_path = $image['image_path']; // Path as stored in DB, e.g., "uploads/bloc_images/..."
                    $source_image_server_path = $app_base_path . '/' . ltrim($source_image_db_path, '/');

                    if (!file_exists($source_image_server_path)) {
                        $file_operation_errors[] = "Image source introuvable: " . htmlspecialchars($source_image_db_path);
                        error_log("COPY_PROJECT Image Error: Source image not found at server path: " . $source_image_server_path . " (DB path: " . $source_image_db_path . ")");
                        continue; 
                    }

                    $original_filename = basename($source_image_db_path);
                    $new_image_subfolder_db = 'uploads/bloc_images/' . date('Y/m/'); // Relative path for DB
                    $new_image_dir_server = $app_base_path . '/' . $new_image_subfolder_db; // Absolute server path for mkdir

                    if (!is_dir($new_image_dir_server)) {
                        if (!mkdir($new_image_dir_server, 0775, true)) {
                            $file_operation_errors[] = "Impossible de créer le dossier pour les nouvelles images: " . htmlspecialchars($new_image_subfolder_db);
                            error_log("COPY_PROJECT Image Error: Failed to create directory: " . $new_image_dir_server);
                            continue; 
                        }
                    }

                    $new_image_filename = uniqid('img_copy_') . '_' . $original_filename;
                    $new_image_db_path = $new_image_subfolder_db . $new_image_filename; // Path for DB
                    $new_image_server_path = $new_image_dir_server . $new_image_filename; // Full server path for copy destination

                    if (copy($source_image_server_path, $new_image_server_path)) {
                        $stmt_insert_image = $db->prepare('INSERT INTO bloc_images (bloc_id, image_path) VALUES (:bid, :path)');
                        $stmt_insert_image->execute(['bid' => $new_bloc_id, 'path' => $new_image_db_path]);
                    } else {
                        $file_operation_errors[] = "Échec de la copie de l'image: " . htmlspecialchars($original_filename);
                        error_log("COPY_PROJECT Image Error: Failed to copy image from '" . $source_image_server_path . "' to '" . $new_image_server_path . "'");
                    }
                }
            }
            
            if (!empty($file_operation_errors)) {
                // If there were file errors, we should not commit the DB changes.
                throw new Exception("Des erreurs se sont produites lors de la copie des fichiers images.");
            }

            $stmt_all_source_props = $db->prepare('SELECT p.* FROM bloc_propositions p JOIN project_blocs b ON p.bloc_id = b.bloc_id WHERE b.project_id = :pid');
            $stmt_all_source_props->execute(['pid' => $source_project['project_id']]);
            $all_source_props_data = $stmt_all_source_props->fetchAll(PDO::FETCH_ASSOC);

            foreach ($all_source_props_data as $source_prop) {
                if (!isset($bloc_id_map[$source_prop['bloc_id']])) continue;
                $stmt_insert_prop = $db->prepare('INSERT INTO bloc_propositions (bloc_id, proposition_text, solution_text, solution_points) VALUES (:bid, :ptext, :stext, :spoints)');
                $stmt_insert_prop->execute(['bid' => $bloc_id_map[$source_prop['bloc_id']], 'ptext' => $source_prop['proposition_text'], 'stext' => $source_prop['solution_text'], 'spoints' => $source_prop['solution_points']]);
                $new_prop_id = $db->lastInsertId();
                $prop_id_map[$source_prop['proposition_id']] = $new_prop_id;
            }

            foreach ($all_source_props_data as $source_prop) {
                if ($source_prop['precedent_proposition_for_penalty_id'] !== null && isset($prop_id_map[$source_prop['proposition_id']]) && isset($prop_id_map[$source_prop['precedent_proposition_for_penalty_id']])) {
                    $stmt_update_penalty = $db->prepare('UPDATE bloc_propositions SET precedent_proposition_for_penalty_id = :precedent_id, penalty_value_if_chosen_early = :penalty_val WHERE proposition_id = :prop_id');
                    $stmt_update_penalty->execute(['precedent_id' => $prop_id_map[$source_prop['precedent_proposition_for_penalty_id']], 'penalty_val' => $source_prop['penalty_value_if_chosen_early'], 'prop_id' => $prop_id_map[$source_prop['proposition_id']]]);
                }
            }
            $db->commit();
            $success_message = "Le projet a été copié avec succès vers le compte '" . htmlspecialchars($target_user_email) . "' sous le nom : \"" . htmlspecialchars($new_project_name) . "\".";
            
            if (!$is_shared_access_by_token && $target_user_email === $current_user_email_from_session) {
                 header('Location: /edit-project.php?project_id=' . $new_project_id . '&message=' . urlencode('Projet dupliqué avec succès! Vous pouvez maintenant le modifier.'));
                 exit;
            }

        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Une erreur technique est survenue lors de la copie du projet. ";
            if (!empty($file_operation_errors)) {
                 $error_message .= "Erreurs spécifiques aux fichiers: <br> - " . implode("<br> - ", $file_operation_errors);
            } else {
                $error_message .= $e->getMessage();
            }
            error_log("COPY_PROJECT EXCEPTION: " . $e->getMessage() . ($file_operation_errors ? " File errors: " . implode("; ", $file_operation_errors) : "") . "\nTRACE:\n" . $e->getTraceAsString());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title>Copier le Projet</title>
    <?php
        require_once __DIR__ . '/../powertrain/head.php';
    ?>
    <style>
        .confirmation-card { background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 1.5em; margin: 2em auto; max-width: 700px; }
        .project-details dt { font-weight: bold; margin-bottom: 0.3em; }
        .project-details dd { margin-left: 1em; margin-bottom: 1em; }
        .alert-error { background-color: #f8d7da; color: #721c24; padding: 1em; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 1em;}
        .alert-success { background-color: #d4edda; color: #155724; padding: 1em; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 1em;}
        <?php if ($is_shared_access_by_token): ?>
        body { padding: 1em; } .container { max-width: 700px; margin: auto; }
        <?php endif; ?>
    </style>
</head>
<body>
    <?php if (!$is_shared_access_by_token && $current_user_email_from_session): ?>
    <nav id="navbar"><ul><li><a href="/dashboard.php">Tableau de Bord</a></li></ul></nav>
    <?php endif; ?>
    <main class="container">
        <h1>Copier le Projet</h1>
        <?php if ($error_message): ?><div class="alert-error" role="alert"><p><?php echo $error_message; /* Allow HTML for <br> */ ?></p></div><?php endif; ?>
        <?php if ($success_message): ?><div class="alert-success" role="alert"><p><?php echo htmlspecialchars($success_message); ?></p></div>
            <?php if ($is_shared_access_by_token): ?><p><a href="/">Retour à l'accueil</a>.</p>
            <?php elseif ($current_user_email_from_session && !$is_shared_access_by_token && !headers_sent()): /* Redirected already if successful self-copy */ ?>
                 <p><a href="/dashboard.php">Retour au tableau de bord</a>.</p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$success_message && $source_project): ?>
        <div class="confirmation-card">
            <h3><?php echo $is_shared_access_by_token ? 'Copier le projet partagé :' : 'Dupliquer votre projet :'; ?></h3>
            <dl class="project-details">
                <dt>Nom du projet original :</dt><dd><?php echo htmlspecialchars($source_project['project_name']); ?></dd>
                <dt>Propriétaire original :</dt><dd><?php echo htmlspecialchars($source_project['user_id']); ?></dd>
            </dl>
            <form method="post">
                <?php if ($is_shared_access_by_token): ?>
                <label for="target_user_email">E-mail du compte destinataire :
                    <input type="email" id="target_user_email" name="target_user_email" placeholder="exemple@domaine.com" value="<?php echo htmlspecialchars($current_user_email_from_session ?? ''); ?>" required>
                </label>
                <?php endif; ?>
                <label for="new_project_name">Nom pour le projet copié :
                    <input type="text" id="new_project_name" name="new_project_name" value="<?php echo htmlspecialchars($source_project['project_name'] . ($is_shared_access_by_token ? ' (Copie Partagée)' : ' (Duplication)')); ?>" required>
                </label>
                <input type="hidden" name="confirm_copy" value="1">
                <button type="submit" class="contrast" style="margin-top:1em;"><?php echo $is_shared_access_by_token ? 'Confirmer la Copie' : 'Dupliquer dans Mon Compte'; ?></button>
                <a href="<?php echo $is_shared_access_by_token ? '/' : '/dashboard.php'; ?>" role="button" class="secondary" style="margin-top:1em;">Annuler</a>
            </form>
        </div>
        <?php elseif (!$source_project): ?>
             <p class="alert-error">Le projet source n'a pas pu être chargé.</p>
        <?php endif; ?>
    </main>
</body>
</html>
