<?php
require_once __DIR__ . '/../powertrain/auth.php';
require_login();

$user = $_SESSION['user'];

require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_name = trim($_POST['project_name'] ?? '');
    $problem_text = trim($_POST['problem_text'] ?? '');

    if ($project_name === '') {
        // Redirect back to dashboard with error
        header('Location: /dashboard.php?error=empty_project_name');
        exit;
    }

    try {
        $stmt = $db->prepare('INSERT INTO user_projects (user_id, project_name, problem_text) VALUES (:user_id, :project_name, :problem_text) RETURNING project_id');
        $stmt->execute([
            'user_id' => $user['email'],
            'project_name' => $project_name,
            'problem_text' => $problem_text,
        ]);
        $project_id = $stmt->fetchColumn();
        if ($project_id) {
            // Redirect to the edit page for this project
            header('Location: /edit.php?project_id=' . urlencode($project_id));
            exit;
        } else {
            // Something went wrong, no project_id returned
            header('Location: /dashboard.php?error=project_creation_failed');
            exit;
        }
    } catch (Exception $e) {
        // Encode error message for dashboard
        $error = urlencode($e->getMessage());
        header("Location: /dashboard.php?error=db&message=$error");
        exit;
    }
} else {
    // Disallow GET
    header('Location: /dashboard.php');
    exit;
}
