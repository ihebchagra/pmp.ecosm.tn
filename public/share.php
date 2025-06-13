<?php
require_once __DIR__ . '/../powertrain/auth.php';
require_login();

$user = $_SESSION['user'];
require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

// Confirm ownership
$stmt = $db->prepare('SELECT * FROM user_projects WHERE project_id = :pid AND user_id = :uid');
$stmt->execute(['pid' => $project_id, 'uid' => $user['email']]);
$project = $stmt->fetch();

if (!$project) {
    header('Location: /dashboard.php');
    exit;
}

// Handle POST to enable/disable share
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shareable = isset($_POST['shareable']) && $_POST['shareable'] === '1';
    if ($shareable) {
        // Create or keep token
        $stmt = $db->prepare('SELECT share_token FROM project_shares WHERE project_id = :pid');
        $stmt->execute(['pid' => $project_id]);
        $row = $stmt->fetch();
        if (!$row) {
            $token = bin2hex(random_bytes(24));
            $stmt = $db->prepare('INSERT INTO project_shares (project_id, share_token) VALUES (:pid, :token)');
            $stmt->execute(['pid' => $project_id, 'token' => $token]);
        }
    } else {
        // Remove share row to disable
        $stmt = $db->prepare('DELETE FROM project_shares WHERE project_id = :pid');
        $stmt->execute(['pid' => $project_id]);
    }
    header('Location: /share.php?project_id=' . $project_id);
    exit;
}

// On GET: get share status and link
$stmt = $db->prepare('SELECT share_token FROM project_shares WHERE project_id = :pid');
$stmt->execute(['pid' => $project_id]);
$row = $stmt->fetch();

$shareable = !!$row;
$share_token = $row['share_token'] ?? null;
$link = $share_token ? "/start-exam.php?share_token=" . urlencode($share_token) : '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <title>Partager PMP</title>
  <?php require_once __DIR__ . '/../powertrain/head.php' ?>
</head>
<body>
<main class="container">
  <nav id="navbar">
      <ul>
          <li><a href="/dashboard.php">Retour à l'Accueil</a></li>
      </ul>
  </nav>
  <h1>Partage PMP : <?php echo htmlspecialchars($project['project_name']); ?></h1>
  <form method="post" action="">
    <fieldset>
      <legend><h4>Ce PMP est-il partageable ?</h4></legend>
      <label>
        <input type="radio" name="shareable" value="1" <?php if ($shareable) echo 'checked'; ?>> Oui (générer un lien public)
      </label>
      <label>
        <input type="radio" name="shareable" value="0" <?php if (!$shareable) echo 'checked'; ?>> Non
      </label>
    </fieldset>
    <button type="submit">Sauvegarder</button>
  </form>
  <?php if ($link): ?>
  <section style="margin-top:2em;">
    <label for="guest-link"><strong>Lien public :</strong></label>
    <input id="guest-link" type="text" value="<?php echo htmlspecialchars('https://pmp.ecosm.tn' . $link); ?>" readonly style="width:100%;">
    <div><small>Donnez ce lien à vos collègues pour leur permettre de faire l’épreuve sans compte. 
    <b>À NE PAS PARTAGER AVEC LES ÉTUDIANTS.</b></small></div>
    <a href="<?php echo htmlspecialchars($link); ?>" target="_blank">Tester le lien</a>
  </section>
  <?php endif; ?>
  <div style="margin-top:2em;">
    <a href="/dashboard.php">&larr; Retour au tableau de bord</a>
  </div>
</main>
</body>
</html>
