<?php
require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

session_start();

$attempt_id = isset($_GET['attempt_id']) ? intval($_GET['attempt_id']) : 0;
$share_token = isset($_GET['share_token']) ? $_GET['share_token'] : null;

// Fetch attempt & project
$stmt = $db->prepare('SELECT * FROM attempts WHERE attempt_id = ?');
$stmt->execute([$attempt_id]);
$attempt = $stmt->fetch();

if (!$attempt) {
    header('Location: /dashboard?error=no_attempt');
    exit;
}

$stmt = $db->prepare('SELECT * FROM user_projects WHERE project_id = ?');
$stmt->execute([$attempt['project_id']]);
$project = $stmt->fetch();

if (!$project) {
    header('Location: /dashboard?error=no_project');
    exit;
}

// Access control: guest or owner
if ($attempt['is_guest']) {
    if (!$share_token) {
        header('Location: /dashboard?error=guest_no_token');
        exit;
    }
    $stmt = $db->prepare('SELECT share_token FROM project_shares WHERE project_id = ?');
    $stmt->execute([$attempt['project_id']]);
    $share = $stmt->fetch();
    if (!$share || $share['share_token'] !== $share_token) {
        header('Location: /dashboard?error=invalid_token');
        exit;
    }
} else {
    require_once __DIR__ . '/../powertrain/auth.php';
    require_login();
    $user = $_SESSION['user'];
    if ($project['user_id'] !== $user['email']) {
        header('Location: /dashboard?error=forbidden');
        exit;
    }
}

// Fetch questions
$stmt = $db->prepare('SELECT * FROM project_questions WHERE project_id = ?');
$stmt->execute([$attempt['project_id']]);
$questions = $stmt->fetchAll();
$json_questions = htmlspecialchars(json_encode($questions));

// Ensure the displayed result is never negative
$result = max(0, (int)$attempt['result']);
$student_name = htmlspecialchars($attempt['student_name']);
$project_id = htmlspecialchars($project['project_id']);

?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title>Résultat</title>
    <?php require_once __DIR__ . '/../powertrain/head.php' ?>
</head>
<body>
<nav id="navbar">
  <ul>
      <li><a href="/dashboard.php">Retour à l'Accueil</a></li>
  </ul>
</nav>
<main class="container" x-data="{ 
    questions: <?php echo $json_questions; ?>,
    total: 0,
    calculateTotal() {
        this.total = 0;
        for (let i = 0; i < this.questions.length; i++) {
            if (this.questions[i].solution_points === 'dead' || parseInt(this.questions[i].solution_points) < 0) {
                continue;
            }
            this.total += parseInt(this.questions[i].solution_points);
        }
    } 
}" x-init="calculateTotal()">
    <h1>Résultat : <?php echo $student_name; ?></h1>
    <!-- Link to full result -->
    <p>Votre résultat est de : <?php echo $result; ?> points</p>
    <p>Maximum des Points : <span x-text="total"></span> points</p>
    <form method="get" action="/start-exam.php">
        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
        <?php if ($share_token): ?>
            <input type="hidden" name="share_token" value="<?php echo htmlspecialchars($share_token); ?>">
        <?php endif; ?>
        <button type="submit">Commencer une nouvelle tentative</button>
    </form>
    <form method="get" action="/dashboard.php">
        <button type="submit">Retour à la page d'accueil</button>
    </form>
</main>
</body>
</html>
