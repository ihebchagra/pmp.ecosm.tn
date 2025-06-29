<?php
// Current Date and Time (UTC): 2025-06-15 18:01:25
// Current User's Login: ihebchagra
require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

$project_id_from_url = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$share_token_from_url = isset($_GET['share_token']) ? $_GET['share_token'] : null;

$project = null;
$is_shared_access = false;
$current_user_email = null; 

// --- Determine access type and fetch project ---
// ... (This entire section remains unchanged)
if ($share_token_from_url) {
    $stmt_share = $db->prepare('SELECT p.* FROM project_shares s JOIN user_projects p ON s.project_id = p.project_id WHERE s.share_token = :token AND s.share_type = :type');
    $stmt_share->execute(['token' => $share_token_from_url, 'type' => 'results']);
    $project = $stmt_share->fetch(PDO::FETCH_ASSOC);

    if ($project) {
        $project_id_from_url = $project['project_id']; 
        $is_shared_access = true;
    } else {
        die("Token de partage invalide ou ne permettant pas l'accès aux résultats.");
    }
} else {
    require_once __DIR__ . '/../powertrain/auth.php';
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
$attempts = $stmt_attempts->fetchAll(PDO::FETCH_ASSOC);

// --- Get all share tokens for this project ---
// ... (This section remains unchanged)
$all_project_share_tokens = [];
if ($project) {
    $stmt_all_shares = $db->prepare('SELECT share_type, share_token FROM project_shares WHERE project_id = :pid');
    $stmt_all_shares->execute(['pid' => $project['project_id']]);
    while ($row = $stmt_all_shares->fetch(PDO::FETCH_ASSOC)) {
        $all_project_share_tokens[$row['share_type']] = $row['share_token'];
    }
}

// NEW: Prepare base URL for export links
$export_base_url = "export-results.php?project_id=" . $project['project_id'];
if ($is_shared_access) {
    $export_base_url .= "&share_token=" . urlencode($share_token_from_url);
}

?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title>Résultats - <?php echo htmlspecialchars($project['project_name']); ?></title>
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
    <?php if (!$is_shared_access && $current_user_email): ?>
    <nav id="navbar">
        <ul>
            <li><a href="/dashboard.php">Retour à l'Accueil</a></li>
        </ul>
    </nav>
    <?php endif; ?>
    
    <main class="container">
        <!-- NEW: Header with Export Buttons -->
        <div class="actions-header">
            <h1 class="project-title" style="margin-bottom:0;">Résultats - <?php echo htmlspecialchars($project['project_name']); ?></h1>
            <div class="button-group">
                <a href="<?php echo $export_base_url; ?>&type=summary" role="button" class="secondary outline">Export Résumé (CSV)</a>
                <a href="<?php echo $export_base_url; ?>&type=detailed" role="button" class="secondary outline">Export Détaillé (CSV)</a>
            </div>
        </div>
        
        <?php if ($is_shared_access): ?>
        <div role="alert" style="margin-bottom:1.5em;">
            <p>Vous consultez cette page via un lien de partage des résultats. Seules les tentatives terminées sont affichées.</p>
        </div>
        <?php endif; ?>
        
        <?php 
        $display_attempts = array_filter($attempts, function($a) { return $a['locked']; });
        if (empty($display_attempts)): 
        ?>
            <p>Aucune tentative <?php echo $is_shared_access ? 'terminée ' : ''; ?>trouvée pour ce PMP.</p>
        <?php else: ?>
        <div style="overflow-x: auto;"> 
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
                // ... (This loop remains unchanged)
                $date = date('d/m/Y H:i', strtotime($attempt['created_at']));
                $score = $attempt['result'] !== null ? htmlspecialchars($attempt['result']) : '-';
                $student = htmlspecialchars($attempt['student_name']);
                $stage = htmlspecialchars($attempt['stage'] ?? '-');
                $niveau = htmlspecialchars($attempt['niveau'] ?? '-');
                $centre_exam = htmlspecialchars($attempt['centre_exam'] ?? '-');
                
                $attempt_result_link = "/attempt-result.php?attempt_id=" . $attempt['attempt_id'];
                if ($is_shared_access) {
                    $attempt_result_link .= "&share_token=" . urlencode($share_token_from_url);
                } elseif ($attempt['is_guest'] && isset($all_project_share_tokens['exam'])) {
                    $attempt_result_link .= "&share_token=" . urlencode($all_project_share_tokens['exam']);
                }
            ?>
                <tr>
                    <td><?php echo $student; ?></td>
                    <td class="score"><?php echo $score !== '-' ? $score . '/20' : '-'; ?></td>
                    <td><?php echo $date; ?></td>
                    <td><?php echo $stage; ?></td>
                    <td><?php echo $niveau; ?></td>
                    <td><?php echo $centre_exam; ?></td>
                    <td>
                        <div class="button-group">
                            <?php if ($attempt['locked']): ?>
                                <a href="<?php echo $attempt_result_link; ?>" class="button primary">Voir Résultat</a>
                            <?php elseif (!$is_shared_access): ?>
                                <a href="exam.php?attempt_id=<?php echo $attempt['attempt_id']; ?>" class="button">Continuer</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </main>
    <script>
        document.title = "Résultats - <?php echo addslashes(htmlspecialchars($project['project_name'])); ?>";
    </script>
</body>
</html>
