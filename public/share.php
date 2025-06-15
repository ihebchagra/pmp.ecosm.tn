<?php
// Current Date and Time (UTC): 2025-06-15 17:06:04
// Current User: ihebchagrause
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

// Handle POST to enable/disable share types
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Begin transaction for multiple operations
    $db->beginTransaction();
    
    try {
        // Process each share type
        $share_types = ['exam', 'results', 'copy'];
        
        foreach ($share_types as $share_type) {
            $enabled = isset($_POST[$share_type . '_share']) && $_POST[$share_type . '_share'] === '1';
            
            if ($enabled) {
                // Check if token already exists
                $stmt = $db->prepare('SELECT share_token FROM project_shares WHERE project_id = :pid AND share_type = :type');
                $stmt->execute(['pid' => $project_id, 'type' => $share_type]);
                $row = $stmt->fetch();
                
                if (!$row) {
                    // Generate new token
                    $token = bin2hex(random_bytes(24));
                    $stmt = $db->prepare('INSERT INTO project_shares (project_id, share_token, share_type) VALUES (:pid, :token, :type)');
                    $stmt->execute(['pid' => $project_id, 'token' => $token, 'type' => $share_type]);
                }
            } else {
                // Delete share token for this type
                $stmt = $db->prepare('DELETE FROM project_shares WHERE project_id = :pid AND share_type = :type');
                $stmt->execute(['pid' => $project_id, 'type' => $share_type]);
            }
        }
        
        $db->commit();
        // Redirect to refresh page with updated data
        header('Location: /share.php?project_id=' . $project_id . '&success=1');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        die("Une erreur s'est produite lors de la mise à jour des paramètres de partage: " . $e->getMessage());
    }
}

// On GET: get share status and links for each type
$stmt = $db->prepare('SELECT share_token, share_type FROM project_shares WHERE project_id = :pid');
$stmt->execute(['pid' => $project_id]);
$shares = $stmt->fetchAll();

// Initialize share status
$share_tokens = [
    'exam' => null,
    'results' => null,
    'copy' => null
];

foreach ($shares as $share) {
    $share_type = $share['share_type'];
    $share_tokens[$share_type] = $share['share_token'];
}

// Base URL for full links
$base_url = 'https://pmp.ecosm.tn';

// Build links - using existing files
$exam_link_path = $share_tokens['exam'] ? "/start-exam.php?share_token=" . urlencode($share_tokens['exam']) : '';
$results_link_path = $share_tokens['results'] ? "/results.php?share_token=" . urlencode($share_tokens['results']) : '';
$copy_link_path = $share_tokens['copy'] ? "/copy-project.php?share_token=" . urlencode($share_tokens['copy']) : '';

$exam_full_link = $exam_link_path ? $base_url . $exam_link_path : '';
$results_full_link = $results_link_path ? $base_url . $results_link_path : '';
$copy_full_link = $copy_link_path ? $base_url . $copy_link_path : '';

// Share type explanations
$share_explanations = [
    'exam' => "Ce lien permet aux étudiants de passer l'examen sans avoir besoin d'un compte.",
    'results' => "Ce lien permet de consulter uniquement les résultats des examens complétés sans pouvoir passer l'examen.",
    'copy' => "Ce lien permet à d'autres enseignants de copier ce PMP dans leur compte pour l'utiliser comme modèle."
];

?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
  <meta charset="UTF-8">
  <title>Partager PMP</title>
  <?php require_once __DIR__ . '/../powertrain/head.php' ?>
  <style>
    .share-section {
      margin-bottom: 1.5em;
      padding: 1em;
      border: 1px solid #ccc;
      border-radius: 8px;
      background-color: #f9f9f9;
    }
    .share-link {
      margin-top: 0.5em;
    }
    .copy-button {
      margin-left: 0.5em;
    }
    .explanation {
      font-size: 0.9em;
      color: #666;
      margin-top: 0.5em;
    }
    .grid {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 0.5em;
      align-items: center;
    }
    .success-message {
      padding: 0.5em;
      background-color: #d1e7dd;
      color: #0a3622;
      border-radius: 4px;
      margin: 1em 0;
      font-size: 0.9em;
      text-align: center;
    }
    .qrcode-container {
      margin-top: 0.75em;
      display: flex; /* Use flex to center if needed or manage layout */
      justify-content: flex-start; /* Align QR code to the left */
    }
    .qrcode-container img {
        border: 1px solid #eee; /* Optional: adds a light border around the QR code */
        max-width: 20rem;
        width: 100%
    }
  </style>
</head>
<body>
<main class="container">
  <nav id="navbar">
      <ul>
          <li><a href="/dashboard.php">Retour à l'Accueil</a></li>
      </ul>
  </nav>
  <h1>Partage PMP : <?php echo htmlspecialchars($project['project_name']); ?></h1>
  
  <?php if (isset($_GET['success'])): ?>
  <div class="success-message">
      Les paramètres de partage ont été mis à jour avec succès.
  </div>
  <?php endif; ?>
  
  <form method="post" action="">
    <fieldset>
      <legend><h4>Options de partage</h4></legend>
      
      <!-- Exam Share Option -->
      <div class="share-section">
        <label>
          <input type="checkbox" name="exam_share" value="1" <?php if ($share_tokens['exam']) echo 'checked'; ?>>
          <strong>Permettre l'accès à l'examen</strong>
        </label>
        <p class="explanation"><?php echo $share_explanations['exam']; ?></p>
        
        <?php if ($share_tokens['exam']): ?>
        <div class="share-link">
          <label for="exam-link"><strong>Lien d'examen :</strong></label>
          <div class="grid">
            <input id="exam-link" type="text" value="<?php echo htmlspecialchars($exam_full_link); ?>" readonly>
            <button type="button" class="copy-button secondary" onclick="copyToClipboard('exam-link')">Copier</button>
          </div>
          <div id="exam-qrcode-container" class="qrcode-container"></div>
          <div style="margin-top: 0.5em;">
            <a href="<?php echo htmlspecialchars($exam_link_path); ?>" target="_blank" class="secondary">Tester le lien</a>
          </div>
        </div>
        <?php endif; ?>
      </div>
      
      <!-- Results Share Option -->
      <div class="share-section">
        <label>
          <input type="checkbox" name="results_share" value="1" <?php if ($share_tokens['results']) echo 'checked'; ?>>
          <strong>Permettre l'accès aux résultats uniquement</strong>
        </label>
        <p class="explanation"><?php echo $share_explanations['results']; ?></p>
        
        <?php if ($share_tokens['results']): ?>
        <div class="share-link">
          <label for="results-link"><strong>Lien des résultats :</strong></label>
          <div class="grid">
            <input id="results-link" type="text" value="<?php echo htmlspecialchars($results_full_link); ?>" readonly>
            <button type="button" class="copy-button secondary" onclick="copyToClipboard('results-link')">Copier</button>
          </div>
          <div id="results-qrcode-container" class="qrcode-container"></div>
          <div style="margin-top: 0.5em;">
            <a href="<?php echo htmlspecialchars($results_link_path); ?>" target="_blank" class="secondary">Tester le lien</a>
          </div>
        </div>
        <?php endif; ?>
      </div>
      
      <!-- Copy Share Option -->
      <div class="share-section">
        <label>
          <input type="checkbox" name="copy_share" value="1" <?php if ($share_tokens['copy']) echo 'checked'; ?>>
          <strong>Permettre la copie du PMP</strong>
        </label>
        <p class="explanation"><?php echo $share_explanations['copy']; ?></p>
        
        <?php if ($share_tokens['copy']): ?>
        <div class="share-link">
          <label for="copy-link"><strong>Lien de copie :</strong></label>
          <div class="grid">
            <input id="copy-link" type="text" value="<?php echo htmlspecialchars($copy_full_link); ?>" readonly>
            <button type="button" class="copy-button secondary" onclick="copyToClipboard('copy-link')">Copier</button>
          </div>
          <div id="copy-qrcode-container" class="qrcode-container"></div>
          <div style="margin-top: 0.5em;">
            <a href="<?php echo htmlspecialchars($copy_link_path); ?>" target="_blank" class="secondary">Tester le lien</a>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </fieldset>
    
    <button type="submit" class="contrast">Sauvegarder les paramètres de partage</button>
  </form>

</main>

<script>
function copyToClipboard(elementId) {
  const element = document.getElementById(elementId);
  element.select();
  document.execCommand('copy');
  
  const button = element.nextElementSibling;
  const originalText = button.textContent;
  button.textContent = "Copié !";
  
  button.classList.add("primary");
  button.classList.remove("secondary");
  
  setTimeout(() => {
    button.textContent = originalText;
    button.classList.remove("primary");
    button.classList.add("secondary");
  }, 1500);
}

function generateQRCode(containerId, linkText, size = 128) {
  const container = document.getElementById(containerId);
  if (container && linkText) {
    // Clear previous QR code if any
    container.innerHTML = ''; 
    new QRCode(container, {
      text: linkText,
      width: size,
      height: size,
      colorDark : "#000000",
      colorLight : "#ffffff",
      correctLevel : QRCode.CorrectLevel.H
    });
  }
}

document.addEventListener('DOMContentLoaded', function() {
  <?php if ($share_tokens['exam'] && $exam_full_link): ?>
  generateQRCode('exam-qrcode-container', '<?php echo htmlspecialchars($exam_full_link, ENT_QUOTES, 'UTF-8'); ?>');
  <?php endif; ?>
  
  <?php if ($share_tokens['results'] && $results_full_link): ?>
  generateQRCode('results-qrcode-container', '<?php echo htmlspecialchars($results_full_link, ENT_QUOTES, 'UTF-8'); ?>');
  <?php endif; ?>
  
  <?php if ($share_tokens['copy'] && $copy_full_link): ?>
  generateQRCode('copy-qrcode-container', '<?php echo htmlspecialchars($copy_full_link, ENT_QUOTES, 'UTF-8'); ?>');
  <?php endif; ?>
});
</script>
</body>
</html>
