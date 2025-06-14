<?php
require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

session_start();

$attempt_id = isset($_GET['attempt_id']) ? intval($_GET['attempt_id']) : 0;
$share_token = isset($_GET['share_token']) ? $_GET['share_token'] : null;

// Fetch attempt
$stmt = $db->prepare('SELECT * FROM attempts WHERE attempt_id = ?');
$stmt->execute([$attempt_id]);
$attempt = $stmt->fetch();

if (!$attempt) {
    header('Location: /dashboard');
    exit;
}

// Fetch project
$stmt = $db->prepare('SELECT * FROM user_projects WHERE project_id = ?');
$stmt->execute([$attempt['project_id']]);
$project = $stmt->fetch();

if (!$project) {
    header('Location: /dashboard');
    exit;
}

// Fetch project image (if any)
$image_url = "";
$stmt = $db->prepare('SELECT image_path FROM project_images WHERE project_id = :pid AND is_deleted = FALSE ORDER BY created_at DESC LIMIT 1');
$stmt->execute(['pid' => $project['project_id']]);
if ($row = $stmt->fetch()) {
    $image_url = htmlspecialchars($row['image_path']);
}

// Validate access
if ($attempt['is_guest']) {
    if (!$share_token) {
        header('Location: /dashboard');
        exit;
    }
    // Validate share_token matches project
    $stmt = $db->prepare('SELECT share_token FROM project_shares WHERE project_id = ?');
    $stmt->execute([$attempt['project_id']]);
    $share = $stmt->fetch();
    if (!$share || $share['share_token'] !== $share_token) {
        header('Location: /dashboard');
        exit;
    }
} else {
    require_once __DIR__ . '/../powertrain/auth.php';
    require_login();
    $user = $_SESSION['user'];
    if ($project['user_id'] !== $user['email']) {
        header('Location: /dashboard');
        exit;
    }
}

// Fetch all questions (preserve order as much as possible)
$stmt = $db->prepare('SELECT * FROM project_questions WHERE project_id = ? ORDER BY question_id ASC');
$stmt->execute([$attempt['project_id']]);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch revealed answers for this attempt
$stmt = $db->prepare('SELECT question_id FROM attempt_answers WHERE attempt_id = ?');
$stmt->execute([$attempt_id]);
$revealed = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $revealed[$row['question_id']] = true;
}

$student_name = htmlspecialchars($attempt['student_name']);
$problem_text = htmlspecialchars($project['problem_text']);
$result = htmlspecialchars($attempt['result']);
$date = date('d/m/Y H:i', strtotime($attempt['created_at']));

?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title>Reconstitution de la tentative - <?php echo $student_name; ?></title>
    <?php require_once __DIR__ . '/../powertrain/head.php' ?>
    <style>
        .proposition {
            border: 1px solid #ccc;
            border-radius: 8px;
            margin-bottom: 1em;
            padding: 1em;
            background: #f9f9f9;
            transition: background 0.2s;
        }
        .proposition.revealed { background: #e0f7fa; }
        .proposition.dead { background: #ffebee; }
        .proposition.correct { background: #e8f5e9; }
        .proposition.incorrect { background: #fff3e0; }
        .solution_points { font-weight: bold; }
    </style>
</head>
<body>
    <nav id="navbar">
        <ul>
            <li><a href="/results.php?project_id=<?php echo $attempt['project_id']; ?>">Retour au Résultats</a></li>
        </ul>
    </nav>
<main class="container">
    <h1>Reconstitution de la tentative</h1>
    <p><b>Étudiant :</b> <?php echo $student_name; ?></p>
    <p><b>Date :</b> <?php echo $date; ?></p>
    <p><b>Score final :</b> <?php echo $result; ?> points</p>
    <h2>Énoncé :</h2>
    <?php if ($image_url): ?>
        <div class="project-image" style="text-align:center; margin-bottom:1em;">
            <img src="<?php echo $image_url; ?>" alt="Image du projet" style="max-width:100%; width: 36rem; border-radius:8px;">
        </div>
    <?php endif; ?>
    <p><?php echo $problem_text; ?></p>
    <h2>Propositions</h2>
    <div>
        <?php
        $total = 0;
        $ended = false;
        foreach ($questions as $q):
            $is_revealed = isset($revealed[$q['question_id']]);
            $class = "proposition";
            $points = $q['solution_points'];
            $show_points = "";
            if ($is_revealed) {
                $class .= " revealed";
                if ($points === 'dead') {
                    $class .= " dead";
                    $show_points = "Proposition dangereuse, l'épreuve est finie, votre score est 0";
                    $ended = true;
                    $total = 0;
                } elseif ($points > 0) {
                    $class .= " correct";
                    $show_points = "Proposition " . ($points == 2 ? "obligatoire" : "utile") . " : +" . $points . " points";
                    $total += intval($points);
                } elseif ($points == 0) {
                    $class .= " null";
                    $show_points = "Proposition inutile : +0 points";
                } else {
                    $class .= " incorrect";
                    $show_points = "Proposition dangereuse : " . $points . " points";
                    $total += intval($points);
                }
            }
        ?>
        <article class="<?php echo $class; ?>">
            <div><h4><?php echo htmlspecialchars($q['question_text']); ?></h4></div>
            <?php if ($is_revealed): ?>
                <div>
                    <p><?php echo htmlspecialchars($q['solution_text']); ?></p>
                    <p class="solution_points"><?php echo $show_points; ?></p>
                </div>
            <?php else: ?>
                <div><em>Non révélée lors de la tentative</em></div>
            <?php endif; ?>
        </article>
        <?php
            if ($ended) break;
        endforeach; ?>
    </div>
</main>
</body>
</html>
