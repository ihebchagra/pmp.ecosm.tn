<?php
require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

// Retrieve all POST data
$project_id = $_POST['project_id'] ?? null;
$student_name = trim($_POST['student_name'] ?? '');
$stage = trim($_POST['stage'] ?? '');
$niveau = trim($_POST['niveau'] ?? ''); // This will be one of the select options
$centre_exam = trim($_POST['centre_exam'] ?? '');
$share_token = $_POST['share_token'] ?? null; // Only present for guest attempts

$is_guest = false;

// --- 1. Validate required fields ---
// All new fields are also required as per your last update to start-exam.php
if (!$project_id || empty($student_name) || empty($stage) || empty($niveau) || empty($centre_exam)) {
    // Redirect back to the start form with an error
    // If it was a guest attempt, try to preserve the share_token in the URL
    $redirect_url = $share_token ? '/start-exam.php?share_token=' . urlencode($share_token) : '/start-exam.php?project_id=' . urlencode($project_id);
    header('Location: ' . $redirect_url . '&error=' . urlencode('Veuillez remplir tous les champs requis.'));
    exit;
}

// --- 2. Validate access (Guest or Authenticated) ---
if ($share_token) {
    // Guest attempt: Validate share_token and that it matches the project_id
    $stmt = $db->prepare('SELECT project_id FROM project_shares WHERE share_token = :token AND share_type = \'exam\'');
    $stmt->execute(['token' => $share_token]);
    $share = $stmt->fetch();
    if (!$share || $share['project_id'] != $project_id) {
        // Invalid or mismatched token
        header('Location: /start-exam.php?share_token=' . urlencode($share_token) . '&error=' . urlencode('Lien de partage invalide ou ne correspondant pas au projet.'));
        exit;
    }
    $is_guest = true;
} else {
    // Authenticated user: Must be logged in and own the project
    require_once __DIR__ . '/../powertrain/auth.php';
    require_login(); // Ensures user is logged in
    $user = $_SESSION['user'];

    $stmt = $db->prepare('SELECT project_id FROM user_projects WHERE project_id = :pid AND user_id = :uid');
    $stmt->execute(['pid' => $project_id, 'uid' => $user['email']]);
    $project = $stmt->fetch();
    if (!$project) {
        // User does not own this project or project_id is invalid
        header('Location: /dashboard.php?error=' . urlencode('Accès non autorisé ou projet invalide.'));
        exit;
    }
    // $is_guest remains false
}

// --- 3. Insert the attempt into the database ---
$sql = 'INSERT INTO attempts (project_id, student_name, is_guest, stage, niveau, centre_exam) 
        VALUES (:pid, :sname, :isguest, :stage, :niveau, :centre)';
$stmt = $db->prepare($sql);

$execute_params = [
    'pid' => $project_id,
    'sname' => $student_name,
    'isguest' => $is_guest ? 1 : 0, // Store boolean as 0 or 1
    'stage' => $stage,
    'niveau' => $niveau,
    'centre' => $centre_exam
];

try {
    $stmt->execute($execute_params);
    $attempt_id = $db->lastInsertId(); // Get the ID of the newly created attempt

    if (!$attempt_id) {
        throw new Exception("La création de la tentative a échoué (aucun ID retourné).");
    }
} catch (PDOException $e) {
    // Log error $e->getMessage();
    $error_message = urlencode("Erreur lors de la création de la tentative. Veuillez réessayer.");
    $redirect_url = $share_token ? '/start-exam.php?share_token=' . urlencode($share_token) : '/start-exam.php?project_id=' . urlencode($project_id);
    header('Location: ' . $redirect_url . '&error=' . $error_message);
    exit;
}


// --- 4. Redirect to the exam interface (exam.php) ---
$redirect_exam_url = '/exam.php?attempt_id=' . $attempt_id;
if ($is_guest && $share_token) {
    // For guest, pass the share_token along so exam.php can re-validate if needed
    $redirect_exam_url .= '&share_token=' . urlencode($share_token);
}
// For authenticated users, attempt_id should be enough as exam.php can verify against session.

header('Location: ' . $redirect_exam_url);
exit;
?>
