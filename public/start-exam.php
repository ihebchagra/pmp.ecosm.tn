<?php
// Current Date and Time (UTC): 2025-06-15 17:13:11
// Current User's Login: ihebchagra
require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

$share_token_from_url = isset($_GET['share_token']) ? $_GET['share_token'] : null;
$project_id_from_url = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0; // For logged-in user starting for their project

$project = null;
$is_shared_access = false;
$current_user_email = null; // Will be set if user is logged in

if ($share_token_from_url) {
    // Access via share token (for guests)
    $stmt_share = $db->prepare('
        SELECT p.* 
        FROM project_shares s 
        JOIN user_projects p ON s.project_id = p.project_id 
        WHERE s.share_token = :token AND s.share_type = :type
    ');
    $stmt_share->execute(['token' => $share_token_from_url, 'type' => 'exam']);
    $project = $stmt_share->fetch(PDO::FETCH_ASSOC);

    if ($project) {
        $is_shared_access = true;
    } else {
        die("Token de partage invalide ou ne permettant pas de démarrer cet examen.");
    }
} elseif ($project_id_from_url) {
    // Logged-in user starting an exam for one of their projects
    require_once __DIR__ . '/../powertrain/auth.php';
    require_login(); // Login required if no token and project_id is specified
    $current_user_email = $_SESSION['user']['email'];

    $stmt_project_owner = $db->prepare('SELECT * FROM user_projects WHERE project_id = :pid AND user_id = :uid');
    $stmt_project_owner->execute(['pid' => $project_id_from_url, 'uid' => $current_user_email]);
    $project = $stmt_project_owner->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        die("Projet introuvable ou vous n'êtes pas autorisé à démarrer un examen pour ce projet.");
    }
} else {
    die("Informations manquantes pour démarrer l'examen (token de partage ou ID de projet requis).");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $project) {
    $student_name = trim($_POST['student_name'] ?? 'Participant Anonyme');
    if (empty($student_name)) $student_name = 'Participant Anonyme';
    
    $stage = trim($_POST['stage'] ?? '');
    $niveau = trim($_POST['niveau'] ?? '');
    $centre_exam = trim($_POST['centre_exam'] ?? '');

    $db->beginTransaction();
    try {
        $stmt_insert_attempt = $db->prepare('
            INSERT INTO attempts (project_id, student_name, is_guest, stage, niveau, centre_exam) 
            VALUES (:pid, :sname, :is_guest, :stage, :niveau, :centre_exam)
        ');
        $stmt_insert_attempt->execute([
            'pid' => $project['project_id'],
            'sname' => $student_name,
            'is_guest' => $is_shared_access ? 1 : 0,
            'stage' => $stage,
            'niveau' => $niveau,
            'centre_exam' => $centre_exam
        ]);
        $attempt_id = $db->lastInsertId();
        $db->commit();

        $redirect_url = "exam.php?attempt_id=" . $attempt_id;
        if ($is_shared_access && $share_token_from_url) {
            $redirect_url .= "&share_token=" . urlencode($share_token_from_url);
        }
        header("Location: " . $redirect_url);
        exit;

    } catch (Exception $e) {
        $db->rollBack();
        die("Erreur lors de la création de la tentative: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title>Démarrer l'Examen - <?php echo htmlspecialchars($project['project_name']); ?></title>
    <?php
        require_once __DIR__ . '/../powertrain/head.php';
    ?>
     <style>
        <?php if ($is_shared_access): ?>
        body { padding: 1em; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .container { max-width: 500px; }
        <?php endif; ?>
    </style>
</head>
<body>
    <main class="container">
        <article>
            <header>
                <h1><?php echo htmlspecialchars($project['project_name']); ?></h1>
            </header>
            <p>Vous êtes sur le point de commencer une nouvelle tentative d'examen.</p>
            <?php if ($is_shared_access): ?>
            <p>Cet examen est accessible via un lien de partage.</p>
            <?php endif; ?>

            <form method="post">
                <label for="student_name">
                    Votre Nom/Prénom (ou identifiant) :
                    <input type="text" id="student_name" name="student_name" placeholder="Ex: Jean Dupont" required>
                </label>
                <label for="stage">
                    Stage (optionnel) :
                    <input type="text" id="stage" name="stage" placeholder="Ex: Stage Été 2025">
                </label>
                <label for="niveau">
                    Niveau (optionnel) :
                    <input type="text" id="niveau" name="niveau" placeholder="Ex: Débutant">
                </label>
                <label for="centre_exam">
                    Centre d'examen (optionnel) :
                    <input type="text" id="centre_exam" name="centre_exam" placeholder="Ex: Centre Ville">
                </label>
                <button type="submit" class="contrast">Commencer l'Examen</button>
            </form>
            <?php if (!$is_shared_access && $current_user_email): ?>
            <footer style="margin-top:1em;">
                <a href="/dashboard.php" role="button" class="secondary">Annuler et retourner au tableau de bord</a>
            </footer>
            <?php endif; ?>
        </article>
    </main>
</body>
</html>
