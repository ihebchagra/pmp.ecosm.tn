<?php
require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

session_start();

$attempt_id = isset($_POST['attempt_id']) ? intval($_POST['attempt_id']) : 0;
$project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
$total = isset($_POST['total']) ? intval($_POST['total']) : 0;
$revealed_questions = isset($_POST['questions']) ? $_POST['questions'] : [];
$share_token = isset($_POST['share_token']) ? $_POST['share_token'] : null;

// Fetch attempt to check access/lock
$stmt = $db->prepare('SELECT * FROM attempts WHERE attempt_id = ?');
$stmt->execute([$attempt_id]);
$attempt = $stmt->fetch();

if (!$attempt) {
    header('Location: /dashboard?error=no_attempt');
    exit;
}

// Fetch project
$stmt = $db->prepare('SELECT * FROM user_projects WHERE project_id = ?');
$stmt->execute([$attempt['project_id']]);
$project = $stmt->fetch();

if (!$project) {
    header('Location: /dashboard?error=no_project');
    exit;
}

// Validate access
if ($attempt['is_guest']) {
    // Guest: validate share token
    if (!$share_token) {
        header('Location: /dashboard?error=guest_no_token');
        exit;
    }
    $stmt = $db->prepare('SELECT share_token FROM project_shares WHERE project_id = ?');
    $stmt->execute([$attempt['project_id']]);
    $share = $stmt->fetch();
    if (!$share || $share['share_token'] !== $share_token) {
        header('Location: /dashboard?error=guest_token_invalid');
        exit;
    }
} else {
    // Authenticated user must own the project
    require_once __DIR__ . '/../powertrain/auth.php';
    require_login();
    $user = $_SESSION['user'];
    if ($project['user_id'] !== $user['email']) {
        header('Location: /dashboard?error=forbidden');
        exit;
    }
}

// Check lock status
if ($attempt['locked']) {
    header('Location: /dashboard?t=locked');
    exit;
}

// Save revealed answers
if (is_array($revealed_questions)) {
    // Remove existing answers for this attempt (in case of resubmission)
    $stmt = $db->prepare('DELETE FROM attempt_answers WHERE attempt_id = ?');
    $stmt->execute([$attempt_id]);

    foreach ($revealed_questions as $index => $question_id) {
        $question_id = intval($question_id);
        // Store empty answer since this is a click-reveal (not text input)
        $stmt = $db->prepare('INSERT INTO attempt_answers (attempt_id, question_id, answer) VALUES (?, ?, ?)');
        $stmt->execute([$attempt_id, $question_id, '']);
    }
}

// Lock the attempt, save score
$stmt = $db->prepare('UPDATE attempts SET locked = TRUE, result = ? WHERE attempt_id = ?');
$stmt->execute([$total, $attempt_id]);

// Show a results page or redirect
if ($attempt['is_guest'] && $share_token) {
    header('Location: /attempt-result.php?attempt_id=' . $attempt_id . '&share_token=' . urlencode($share_token));
} else {
    header('Location: /attempt-result.php?attempt_id=' . $attempt_id);
}
exit;
