<?php
// Current Date and Time (UTC): 2025-06-15 18:05:00
// Script to handle CSV export of project results.

require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$share_token = isset($_GET['share_token']) ? $_GET['share_token'] : null;
$export_type = isset($_GET['type']) ? $_GET['type'] : 'summary'; // 'summary' or 'detailed'

// --- Security Check: Replicate access control from results.php ---
// It is CRITICAL to validate access here again. Do not trust that the user came from a valid page.
$project = null;
if ($share_token) {
    $stmt_share = $db->prepare('SELECT p.* FROM project_shares s JOIN user_projects p ON s.project_id = p.project_id WHERE s.project_id = :pid AND s.share_token = :token AND s.share_type = :type');
    $stmt_share->execute(['pid' => $project_id, 'token' => $share_token, 'type' => 'results']);
    $project = $stmt_share->fetch(PDO::FETCH_ASSOC);
} else {
    // If no token, user must be logged-in owner
    require_once __DIR__ . '/../powertrain/auth.php';
    require_login();
    $current_user_email = $_SESSION['user']['email'];
    $stmt_project_owner = $db->prepare('SELECT * FROM user_projects WHERE project_id = :pid AND user_id = :uid');
    $stmt_project_owner->execute(['pid' => $project_id, 'uid' => $current_user_email]);
    $project = $stmt_project_owner->fetch(PDO::FETCH_ASSOC);
}

if (!$project) {
    http_response_code(403);
    die("Accès non autorisé ou projet introuvable.");
}

// --- Data Fetching and CSV Generation ---

// Sanitize project name for the filename
$safe_project_name = preg_replace('/[^a-zA-Z0-9-_\.]/', '_', $project['project_name']);
$filename = "{$export_type}_results_{$safe_project_name}_" . date('Y-m-d') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Use php://output to stream the file directly to the browser
$output = fopen('php://output', 'w');

// Add a UTF-8 BOM to prevent issues with special characters in Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

if ($export_type === 'summary') {
    // --- Summary Export ---
    fputcsv($output, [
        'ID Tentative', 'Nom Etudiant', 'Score /20', 'Date Tentative', 
        'Stage', 'Niveau', 'Centre Examen'
    ]);

    $stmt = $db->prepare("SELECT * FROM attempts WHERE project_id = :pid AND locked = TRUE ORDER BY created_at DESC");
    $stmt->execute(['pid' => $project_id]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['attempt_id'],
            $row['student_name'],
            $row['result'],
            $row['created_at'],
            $row['stage'],
            $row['niveau'],
            $row['centre_exam']
        ]);
    }

} elseif ($export_type === 'detailed') {
    // --- Detailed Export ---
    fputcsv($output, [
        'ID Tentative', 'Nom Etudiant', 'Score Final /20', 'Date Tentative',
        'ID Bloc', 'Texte Enonce',
        'ID Proposition', 'Texte Proposition', 'Points Proposition',
        'Penalite Appliquee', 'Date du Choix'
    ]);

    // This is a large query to get all data in one go
    $stmt = $db->prepare("
        SELECT
            att.attempt_id,
            att.student_name,
            att.result as final_score,
            att.created_at as attempt_date,
            b.bloc_id,
            b.problem_text,
            p.proposition_id,
            p.proposition_text,
            p.solution_points,
            ans.penalty_applied,
            ans.chosen_at
        FROM attempts att
        JOIN attempt_answers ans ON att.attempt_id = ans.attempt_id
        JOIN bloc_propositions p ON ans.proposition_id = p.proposition_id
        JOIN project_blocs b ON p.bloc_id = b.bloc_id
        WHERE att.project_id = :pid AND att.locked = TRUE
        ORDER BY att.attempt_id, b.sequence_number, ans.chosen_at
    ");
    $stmt->execute(['pid' => $project_id]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['attempt_id'],
            $row['student_name'],
            $row['final_score'],
            $row['attempt_date'],
            $row['bloc_id'],
            $row['problem_text'],
            $row['proposition_id'],
            $row['proposition_text'],
            $row['solution_points'],
            $row['penalty_applied'],
            $row['chosen_at']
        ]);
    }
} else {
    // Handle invalid type
    fputcsv($output, ['Error: Invalid export type specified.']);
}

fclose($output);
exit;
