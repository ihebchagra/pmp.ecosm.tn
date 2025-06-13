<?php
require_once __DIR__ . '/../powertrain/auth.php';
require_login();

$user = $_SESSION['user'];
require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /dashboard.php');
    exit;
}

$project_id = intval($_POST['project_id'] ?? 0);
$problem_text = trim($_POST['enonce'] ?? '');

// 1. Validate project ownership
$stmt = $db->prepare('SELECT * FROM user_projects WHERE project_id = :pid AND user_id = :uid');
$stmt->execute(['pid' => $project_id, 'uid' => $user['email']]);
$project = $stmt->fetch();
if (!$project) {
    header('Location: /dashboard.php?error=not_allowed');
    exit;
}

// 2. Update statement
$stmt = $db->prepare('UPDATE user_projects SET problem_text = :problem_text, updated_at = CURRENT_TIMESTAMP WHERE project_id = :pid');
$stmt->execute(['problem_text' => $problem_text, 'pid' => $project_id]);

// 3. Handle Questions
$questions = $_POST['questions'] ?? [];
$existing_questions = [];
$stmt = $db->prepare('SELECT question_id FROM project_questions WHERE project_id = :pid');
$stmt->execute(['pid' => $project_id]);
foreach ($stmt->fetchAll() as $row) {
    $existing_questions[$row['question_id']] = true;
}

$received_ids = [];
foreach ($questions as $qid => $qdata) {
    $question_text = trim($qdata['text'] ?? '');
    $solution_text = trim($qdata['solution'] ?? '');
    $solution_points = $qdata['points'] ?? '0';
    $received_ids[] = intval($qid);

    if (isset($existing_questions[$qid])) {
        // Update
        $stmt = $db->prepare(
            'UPDATE project_questions SET question_text = :qt, solution_text = :st, solution_points = :sp, updated_at = CURRENT_TIMESTAMP WHERE question_id = :qid AND project_id = :pid'
        );
        $stmt->execute([
            'qt' => $question_text,
            'st' => $solution_text,
            'sp' => $solution_points,
            'qid' => $qid,
            'pid' => $project_id
        ]);
    } else {
        // Insert (ignore if empty)
        if ($question_text !== '' && $solution_text !== '') {
            $stmt = $db->prepare(
                'INSERT INTO project_questions (project_id, question_text, solution_text, solution_points) VALUES (:pid, :qt, :st, :sp)'
            );
            $stmt->execute([
                'pid' => $project_id,
                'qt' => $question_text,
                'st' => $solution_text,
                'sp' => $solution_points
            ]);
        }
    }
}

// Delete removed questions
if (!empty($existing_questions)) {
    $keep_ids = array_map('intval', array_keys($questions));
    $placeholders = implode(',', array_fill(0, count($keep_ids), '?'));
    if ($placeholders) {
        $stmt = $db->prepare("DELETE FROM project_questions WHERE project_id = ? AND question_id NOT IN ($placeholders)");
        $stmt->execute(array_merge([$project_id], $keep_ids));
    } else {
        // All questions removed
        $stmt = $db->prepare("DELETE FROM project_questions WHERE project_id = ?");
        $stmt->execute([$project_id]);
    }
}

// 4. Handle singular image upload
if (isset($_FILES['project_image']) && $_FILES['project_image']['error'] !== UPLOAD_ERR_NO_FILE) {
    $img = $_FILES['project_image'];

    if ($img['error'] === UPLOAD_ERR_OK && $img['size'] > 0) {
        // Validate image type
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mime = mime_content_type($img['tmp_name']);
        if (!in_array($mime, $allowed_types)) {
            header('Location: /edit.php?project_id=' . $project_id . '&error=invalid_image');
            exit;
        }

        // Directory for uploads
        $upload_dir = __DIR__ . '/uploads/projects/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0775, true);
        }
        $ext = pathinfo($img['name'], PATHINFO_EXTENSION);
        $filename = 'project_' . $project_id . '_' . time() . '.' . $ext;
        $dest = $upload_dir . $filename;
        move_uploaded_file($img['tmp_name'], $dest);

        // Soft-delete previous images
        $stmt = $db->prepare('UPDATE project_images SET is_deleted = TRUE WHERE project_id = :pid AND is_deleted = FALSE');
        $stmt->execute(['pid' => $project_id]);

        // Insert new image
        $stmt = $db->prepare('INSERT INTO project_images (project_id, image_path) VALUES (:pid, :path)');
        $stmt->execute([
            'pid' => $project_id,
            'path' => '/uploads/projects/' . $filename
        ]);
    }
}

header('Location: /edit.php?project_id=' . $project_id . '&success=1');
exit;
