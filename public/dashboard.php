<?php
require_once __DIR__ . '/../powertrain/auth.php';
require_login();

$user = $_SESSION['user'];

require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

// Fetch user projects from the DB
$projects = [];
$errorMessage = "";
$successMessage = ""; // Initialize success message

// Handle error display from add-project
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'empty_project_name') {
        $errorMessage = "Le nom du PMP ne peut pas être vide.";
    } elseif ($_GET['error'] === 'project_creation_failed') {
        $errorMessage = "Une erreur inconnue est survenue lors de la création du projet.";
    } elseif ($_GET['error'] === 'db') {
        $errorMessage = "Erreur base de données";
        if (isset($_GET['message'])) {
            $errorMessage .= " : " . htmlspecialchars($_GET['message']);
        }
    }
}

if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
    $successMessage = "Le PMP a été supprimé avec succès.";
}

if (isset($_GET['added']) && $_GET['added'] == '1') {
    $successMessage = "Le PMP a été ajouté avec succès.";
}


try {
    // The user_projects table structure for these fields remains the same.
    $stmt = $db->prepare('SELECT project_id, project_name, updated_at FROM user_projects WHERE user_id = :user_id ORDER BY updated_at DESC');
    $stmt->execute(['user_id' => $user['email']]);
    $projects = $stmt->fetchAll();
} catch (Exception $e) {
    $errorMessage = "Erreur lors du chargement des projets : " . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <title>Créateur PMP - Tableau de Bord</title>
    <?php require_once __DIR__ . '/../powertrain/head.php' ?>
</head>
<body>
    <main class="container">
        <h1>Bienvenue Dr&nbsp;<?php echo htmlspecialchars(ucwords(strtolower($user['name']))); ?> </h1>
        <div class="dashboard-content">
            <h2>Vos PMPs</h2>
            <?php if (!empty($successMessage)): ?>
                <p style="color: green;"><?php echo $successMessage; ?></p>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <p style="color: red;"><?php echo $errorMessage; ?></p>
            <?php endif; ?>
            <?php if (count($projects) > 0): ?>
                <?php foreach ($projects as $project): ?>
                    <article>
                        <details>
                            <summary><?php echo htmlspecialchars($project['project_name']); ?> <small>(Mis à jour: <?php echo date('d/m/Y H:i', strtotime($project['updated_at'])); ?>)</small></summary>
                            <div>
                                <form method="get" action="/edit.php" style="display: inline-block; margin-right: 5px;">
                                    <input type="hidden" name="project_id" value="<?php echo $project['project_id']; ?>">
                                    <button type="submit">Modifier</button>
                                </form>
                                <form method="get" action="/start-exam.php" style="display: inline-block; margin-right: 5px;">
                                    <input type="hidden" name="project_id" value="<?php echo $project['project_id']; ?>">
                                    <button type="submit">Lancer Épreuve</button>
                                </form>
                                <form method="get" action="/results.php" style="display: inline-block; margin-right: 5px;">
                                    <input type="hidden" name="project_id" value="<?php echo $project['project_id']; ?>">
                                    <button type="submit">Résultats</button>
                                </form>
                                <form method="get" action="/share.php" style="display: inline-block; margin-right: 5px;">
                                    <input type="hidden" name="project_id" value="<?php echo $project['project_id']; ?>">
                                    <button type="submit">Partager</button>
                                </form>
                                <form method="post" action="/delete-project.php" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce PMP ? Cette action est irréversible et supprimera tous les blocs, questions, images et résultats associés.');" style="display: inline-block;">
                                    <input type="hidden" name="project_id" value="<?php echo $project['project_id']; ?>">
                                    <button class="secondary" type="submit">Supprimer</button>
                                </form>
                            </div>
                        </details>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <?php if (empty($errorMessage)): // Show "Aucun projet" only if there wasn't an error loading them ?>
                    <p>Aucun projet trouvé. Commencez par en créer un !</p>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" action="/add-project.php" style="margin-top: 2em;">
                <label for="project_name"><h3>Ajouter un nouveau PMP :</h3></label>
                <input type="text" id="project_name" name="project_name" placeholder="Entrez le nom du nouveau PMP" required>
                <!-- The hidden problem_text input is removed as it's no longer in user_projects -->
                <button type="submit">Ajouter et Configurer</button>
            </form>
            <h3 style="margin-top: 2em;">Votre Compte :</h3>
            <button onclick="window.location.href='/logout.php'">Se déconnecter</button>
        </div>
    </main>
<?php
    /*
    <footer class="footer">
        <p> <?php echo date('Y'); ?> Créateur PMP est un project open source publié sous la license GPL-3 - <a
                href="https://github.com/ihebchagra/createur-pmp" class="white-link">GitHub</a></p>
    </footer>
    */
?>
</body>
</html>
