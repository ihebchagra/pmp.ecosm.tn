<?php
require_once __DIR__ . '/../powertrain/db.php';
require_once __DIR__ . '/../powertrain/auth.php';

session_start();
require_login();

$db = get_db();
$user = $_SESSION['user'];

// Get and validate project_id
$project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;

if (!$project_id) {
    header('Location: /dashboard?error=no_project_id');
    exit;
}

// Check ownership
$stmt = $db->prepare('SELECT * FROM user_projects WHERE project_id = ? AND user_id = ?');
$stmt->execute([$project_id, $user['email']]);
$project = $stmt->fetch();

if (!$project) {
    header('Location: /dashboard?error=forbidden');
    exit;
}

// Delete project (CASCADE will remove all related entries)
$stmt = $db->prepare('DELETE FROM user_projects WHERE project_id = ?');
$stmt->execute([$project_id]);

header('Location: /dashboard.php?deleted=1');
exit;
