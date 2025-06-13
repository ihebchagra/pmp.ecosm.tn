<?php
require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

$is_guest = false;
$project_id = $_POST['project_id'] ?? null;
$student_name = trim($_POST['student_name'] ?? '');
$share_token = $_POST['share_token'] ?? null;

// Validate required fields
if (!$project_id || !$student_name) {
    header('Location: /dashboard.php?error=missing_fields');
    exit;
}

// Check if guest (share_token) or authenticated
if ($share_token) {
    // Validate share_token and get project_id
    $stmt = $db->prepare('SELECT project_id FROM project_shares WHERE share_token = :token');
    $stmt->execute(['token' => $share_token]);
    $share = $stmt->fetch();
    if (!$share || $share['project_id'] != $project_id) {
        header('Location: /start-exam.php?share_token=' . urlencode($share_token) . '&error=invalid_share');
        exit;
    }
    $is_guest = true;
} else {
    // Authenticated user must own the project
    require_once __DIR__ . '/../powertrain/auth.php';
    require_login();
    $user = $_SESSION['user'];

    $stmt = $db->prepare('SELECT * FROM user_projects WHERE project_id = :pid AND user_id = :uid');
    $stmt->execute(['pid' => $project_id, 'uid' => $user['email']]);
    $project = $stmt->fetch();
    if (!$project) {
        header('Location: /dashboard.php?error=access_denied');
        exit;
    }
}

// Insert attempt
$stmt = $db->prepare('INSERT INTO attempts (project_id, student_name, is_guest) VALUES (:pid, :sname, :isguest) RETURNING attempt_id');
$stmt->execute([
    'pid' => $project_id,
    'sname' => $student_name,
    'isguest' => $is_guest ? 1 : 0
]);
$attempt_id = $stmt->fetchColumn();

if (!$attempt_id) {
    header('Location: /dashboard.php?error=attempt_failed');
    exit;
}

// Redirect to exam.php
if ($is_guest && $share_token) {
    header('Location: /exam.php?attempt_id=' . $attempt_id . '&share_token=' . urlencode($share_token));
} else {
    header('Location: /exam.php?attempt_id=' . $attempt_id);
}
exit;
