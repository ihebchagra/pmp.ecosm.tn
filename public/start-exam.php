<?php
require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

$project = null;
$project_id = null;
$is_guest = false;
$student_name = '';

// Guest access: via share_token
if (isset($_GET['share_token'])) {
    $token = $_GET['share_token'];
    $stmt = $db->prepare('SELECT project_id FROM project_shares WHERE share_token = :token');
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();
    if ($row) {
        $project_id = $row['project_id'];
        $is_guest = true;
    } else {
        http_response_code(404);
        echo "<main class='container'><h1>Lien invalide ou non partagé.</h1></main>";
        exit;
    }
}

// Authenticated access: via project_id and session
if (!$is_guest) {
    require_once __DIR__ . '/../powertrain/auth.php';
    require_login();
    $user = $_SESSION['user'];
    $project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

    $stmt = $db->prepare('SELECT * FROM user_projects WHERE project_id = :pid AND user_id = :uid');
    $stmt->execute(['pid' => $project_id, 'uid' => $user['email']]);
    $project = $stmt->fetch();

    if (!$project) {
        header('Location: /dashboard.php');
        exit;
    }
} else {
    // Guest: fetch project for display
    $stmt = $db->prepare('SELECT * FROM user_projects WHERE project_id = :pid');
    $stmt->execute(['pid' => $project_id]);
    $project = $stmt->fetch();
    if (!$project) {
        http_response_code(404);
        echo "<main class='container'><h1>Projet introuvable.</h1></main>";
        exit;
    }
}

$project_id_html = htmlspecialchars($project['project_id']);
$share_token_input = $is_guest ? '<input type="hidden" name="share_token" value="' . htmlspecialchars($token) . '">' : '';
$student_name_input = <<<HTML
    <div>
        <label for="student_name">Nom de l'étudiant:</label>
        <input type="text" id="student_name" name="student_name" required>
    </div>
HTML;

// For authenticated users, you might want to autofill the student name with their display name, or not ask at all.
// If you want to skip the name input for authenticated users, comment out $student_name_input in that branch.

?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title>Nouvelle Tentative</title>
    <?php require_once __DIR__ . '/../powertrain/head.php' ?>
</head>
<body>
<main class="container">
<h1>Nouvelle Tentative PMP : <?php echo htmlspecialchars($project['project_name']); ?></h1>
<form method="post" action="/create-attempt.php">
    <input type="hidden" name="project_id" value="<?php echo $project_id_html; ?>">
    <?php echo $share_token_input; ?>
    <?php echo $student_name_input; ?>
    <button type="submit">Commencer Tentative</button>
</form>
</main>
<script>
    document.title = "Nouvelle Tentative";
</script>
</body>
</html>
