<?php
require_once __DIR__ . '/../powertrain/db.php';
require_once __DIR__ . '/../powertrain/auth.php';

$db = get_db();

$project_id_from_url = (int)($_GET['project_id'] ?? 0);
$share_token_from_url = $_GET['share_token'] ?? null;

$project = null;
$is_shared_access = false;
$current_user_email = null;

// --- Determine access type and fetch project ---
if ($share_token_from_url) {
    // Access via share token for results
    $stmt_share = $db->prepare('
        SELECT p.* FROM project_shares s
        JOIN user_projects p ON s.project_id = p.project_id
        WHERE s.share_token = :token AND s.share_type = :type
    ');
    $stmt_share->execute(['token' => $share_token_from_url, 'type' => 'results']);
    $project = $stmt_share->fetch(PDO::FETCH_ASSOC);

    if ($project) {
        $project_id_from_url = (int)$project['project_id'];
        $is_shared_access = true;
    } else {
        die("Token de partage invalide ou ne permettant pas l'accès aux résultats.");
    }
} else {
    // Access for logged-in project owner
    require_login();
    $current_user_email = $_SESSION['user']['email'];

    if (!$project_id_from_url) {
        header('Location: /dashboard.php?error=' . urlencode("ID de projet non spécifié."));
        exit;
    }

    $stmt_project_owner = $db->prepare('SELECT * FROM user_projects WHERE project_id = :pid AND user_id = :uid');
    $stmt_project_owner->execute(['pid' => $project_id_from_url, 'uid' => $current_user_email]);
    $project = $stmt_project_owner->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        header('Location: /dashboard.php?error=' . urlencode("Projet introuvable ou accès non autorisé."));
        exit;
    }
}

// --- Fetch attempts for the identified project ---
$stmt_attempts = $db->prepare('SELECT * FROM attempts WHERE project_id = :pid ORDER BY created_at DESC');
$stmt_attempts->execute(['pid' => $project['project_id']]);
$all_attempts = $stmt_attempts->fetchAll(PDO::FETCH_ASSOC);

// We only display completed (locked) attempts.
$display_attempts = array_filter($all_attempts, function ($a) {
    return $a['locked'];
});

// --- Get all share tokens for this project to build correct links ---
$all_project_share_tokens = [];
if (!$is_shared_access && $project) {
    $stmt_all_shares = $db->prepare('SELECT share_type, share_token FROM project_shares WHERE project_id = :pid');
    $stmt_all_shares->execute(['pid' => $project['project_id']]);
    $all_project_share_tokens = $stmt_all_shares->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Prepare base URL for export links
$export_base_url = "export-results.php?project_id=" . $project['project_id'];
if ($is_shared_access) {
    $export_base_url .= "&share_token=" . urlencode($share_token_from_url);
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title>Résultats - <?= htmlspecialchars($project['project_name']) ?></title>
    <?php require_once __DIR__ . '/../powertrain/head.php'; ?>
    <style>
        .button-group { display: flex; gap: 8px; }
        .button-group .button { margin: 0; }
        .score { font-weight: bold; }
        .project-title { margin-bottom: 0.5em; }
        .actions-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5em; flex-wrap: wrap; gap: 1em; }
        table th, table td { white-space: nowrap; }
        <?php if ($is_shared_access): ?>
        body { padding: 1em; }
        .container { max-width: 1000px; }
        <?php endif; ?>
    </style>
</head>
<body>
<?php if (!$is_shared_access): ?>
    <nav id="navbar">
        <ul>
            <li><a href="/dashboard.php">Retour à l'Accueil</a></li>
        </ul>
    </nav>
<?php endif; ?>

<main class="container">
    <div class="actions-header">
        <h1 class="project-title" style="margin-bottom:0;">Résultats - <?= htmlspecialchars($project['project_name']) ?></h1>
        <div class="button-group">
            <a href="<?= $export_base_url ?>&type=summary" role="button" class="secondary outline">Export Résumé (CSV)</a>
            <a href="<?= $export_base_url ?>&type=detailed" role="button" class="secondary outline">Export Détaillé (CSV)</a>
        </div>
    </div>

    <?php if ($is_shared_access): ?>
        <div role="alert" style="margin-bottom:1.5em;">
            <p>Vous consultez cette page via un lien de partage. Seules les tentatives terminées sont affichées.</p>
        </div>
    <?php endif; ?>

    <?php if (empty($display_attempts)): ?>
        <p>Aucune tentative terminée n'a été trouvée pour ce PMP.</p>
    <?php else: ?>
        <div class="overflow-auto">
            <table role="grid">
                <thead>
                <tr>
                    <th scope="col">Étudiant</th>
                    <th scope="col">Score</th>
                    <th scope="col">Date</th>
                    <th scope="col">Stage</th>
                    <th scope="col">Niveau</th>
                    <th scope="col">Centre d'Examen</th>
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($display_attempts as $attempt):
                    $date = date('d/m/Y H:i', strtotime($attempt['created_at']));
                    $score = $attempt['result'] !== null ? htmlspecialchars($attempt['result']) : '-';
                    $student = htmlspecialchars($attempt['student_name']);
                    $stage = htmlspecialchars($attempt['stage'] ?? '-');
                    $niveau = htmlspecialchars($attempt['niveau'] ?? '-');
                    $centre_exam = htmlspecialchars($attempt['centre_exam'] ?? '-');

                    $params = ['attempt_id' => $attempt['attempt_id']];
                    if ($is_shared_access) {
                        $params['share_token'] = $share_token_from_url;
                    } elseif ($attempt['is_guest'] && isset($all_project_share_tokens['exam'])) {
                        $params['share_token'] = $all_project_share_tokens['exam'];
                    }
                    $attempt_result_link = "/attempt-result.php?" . http_build_query($params);
                ?>
                    <tr>
                        <td><?= $student ?></td>
                        <td class="score"><?= $score !== '-' ? $score . '/20' : '-' ?></td>
                        <td><?= $date ?></td>
                        <td><?= $stage ?></td>
                        <td><?= $niveau ?></td>
                        <td><?= $centre_exam ?></td>
                        <td>
                            <div class="button-group">
                                <a href="<?= $attempt_result_link ?>" class="button primary">Voir Résultat</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
