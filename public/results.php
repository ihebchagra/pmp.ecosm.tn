<?php
require_once __DIR__ . '/../powertrain/auth.php';
require_login();

$user = $_SESSION['user'];
require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

// Verify user has access to project
$stmt = $db->prepare('SELECT * FROM user_projects WHERE project_id = :pid AND user_id = :uid');
$stmt->execute(['pid' => $project_id, 'uid' => $user['email']]);
$project = $stmt->fetch();

if (!$project) {
    header('Location: /dashboard');
    exit;
}

// Get all attempts for this project
$stmt = $db->prepare('SELECT * FROM attempts WHERE project_id = :pid ORDER BY created_at DESC');
$stmt->execute(['pid' => $project_id]);
$attempts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title>Résultats - <?php echo htmlspecialchars($project['project_name']); ?></title>
    <?php require_once __DIR__ . '/../powertrain/head.php' ?>
</head>
<body>
    <nav id="navbar">
        <ul>
            <li><a href="/dashboard.php">Retour à l'Accueil</a></li>
        </ul>
    </nav>
    <main class="container">
        <h1>Résultats - <?php echo htmlspecialchars($project['project_name']); ?></h1>
        <?php if (empty($attempts)): ?>
            <p>Aucune tentative trouvée pour ce PMP.</p>
        <?php else: ?>
        <table role="grid">
            <thead>
                <tr>
                    <th scope="col">Étudiant</th>
                    <th scope="col">Score</th>
                    <th scope="col">Date</th>
                    <th scope="col">Statut</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($attempts as $attempt):
                $date = date('d/m/Y H:i', strtotime($attempt['created_at']));
                $status = $attempt['locked'] ? 'Terminé' : 'En cours';
                $score = $attempt['result'] !== null ? htmlspecialchars($attempt['result']) : '-';
                $student = htmlspecialchars($attempt['student_name']);
                // Show share_token for guests if needed
                $share_token = '';
                if ($attempt['is_guest']) {
                    // Fetch share_token for this project
                    $st = $db->prepare('SELECT share_token FROM project_shares WHERE project_id = ?');
                    $st->execute([$project_id]);
                    if ($row = $st->fetch()) {
                        $share_token = $row['share_token'];
                    }
                }
                // Build the attempt details link
                $details_link = "/attempt-result.php?attempt_id=" . $attempt['attempt_id'];
                if ($share_token) {
                    $details_link .= "&share_token=" . urlencode($share_token);
                }
                // Build the full result link
                $full_result_link = "/full-result.php?attempt_id=" . $attempt['attempt_id'];
                if ($share_token) {
                    $full_result_link .= "&share_token=" . urlencode($share_token);
                }
            ?>
                <tr>
                    <td><?php echo $student; ?></td>
                    <td><?php echo $score; ?></td>
                    <td><?php echo $date; ?></td>
                    <td><?php echo $status; ?></td>
                    <td>
                        <!--<a href="<?php echo $details_link; ?>" class="button">Voir détails</a>-->
                        <a href="<?php echo $full_result_link; ?>" class="button">Voir reconstitution</a>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        <?php endif; ?>
    </main>
    <script>
        document.title = "Résultats - <?php echo addslashes($project['project_name']); ?>";
    </script>
</body>
</html>
