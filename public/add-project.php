<?php
require_once __DIR__ . '/../powertrain/auth.php';
require_login();

$user = $_SESSION['user'];

require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_name = trim($_POST['project_name'] ?? '');

    if ($project_name === '') {
        // Redirect back to dashboard with error for empty project name
        header('Location: /dashboard.php?error=empty_project_name');
        exit;
    }

    try {
        // problem_text is no longer part of user_projects table
        $stmt = $db->prepare('INSERT INTO user_projects (user_id, project_name) VALUES (:user_id, :project_name) RETURNING project_id');
        $stmt->execute([
            'user_id' => $user['email'],
            'project_name' => $project_name,
        ]);
        $project_id = $stmt->fetchColumn();

        if ($project_id) {
            // Redirect to the edit page for this new project to add blocs and content
            // Or, you could redirect to dashboard with a success message:
            // header('Location: /dashboard.php?added=1&new_project_id=' . urlencode($project_id));
            header('Location: /edit.php?project_id=' . urlencode($project_id) . '&new=1'); // Added new=1 to indicate it's a fresh project
            exit;
        } else {
            // Something went wrong, no project_id returned
            header('Location: /dashboard.php?error=project_creation_failed');
            exit;
        }
    } catch (Exception $e) {
        // Encode error message for dashboard display
        // Consider logging the full error for debugging: error_log($e->getMessage());
        $errorMessage = "Erreur de base de données lors de la création du projet.";
        if (getenv('APP_ENV') === 'development') { // Only show detailed error in development
            $errorMessage .= " Détails: " . htmlspecialchars($e->getMessage());
        }
        header("Location: /dashboard.php?error=db&message=" . urlencode($errorMessage));
        exit;
    }
} else {
    // If not a POST request, redirect to dashboard
    // This helps prevent direct access or incorrect usage
    header('Location: /dashboard.php');
    exit;
}
?>
