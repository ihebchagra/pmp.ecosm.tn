<?php
require_once __DIR__ . '/../powertrain/auth.php';
require_login();

$user = $_SESSION['user'];
require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

// Validate and fetch project
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$stmt = $db->prepare('SELECT * FROM user_projects WHERE project_id = :pid AND user_id = :uid');
$stmt->execute(['pid' => $project_id, 'uid' => $user['email']]);
$project = $stmt->fetch();

if (!$project) {
    header('Location: /dashboard.php');
    exit;
}

// Fetch project questions
$stmt = $db->prepare('SELECT * FROM project_questions WHERE project_id = :pid ORDER BY question_id ASC');
$stmt->execute(['pid' => $project_id]);
$questions = $stmt->fetchAll();

// Fetch current image (not soft deleted)
$image_path = '';
$stmt = $db->prepare('SELECT image_path FROM project_images WHERE project_id = :pid AND is_deleted = FALSE ORDER BY created_at DESC LIMIT 1');
$stmt->execute(['pid' => $project_id]);
if ($row = $stmt->fetch()) {
    $image_path = $row['image_path'];
}

$json_questions = htmlspecialchars(json_encode($questions), ENT_QUOTES, 'UTF-8');
$project_id_html = htmlspecialchars($project['project_id']);
$problem_text = htmlspecialchars($project['problem_text']);
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title>Modifier PMP</title>
    <?php require_once __DIR__ . '/../powertrain/head.php' ?>
</head>
<body>
    <nav id="navbar">
        <ul>
            <li><a href="/dashboard.php">Retour à l'Accueil</a></li>
        </ul>
    </nav>
    <main class="container">

    <h1>Modifier PMP</h1>
    <?php if (isset($_GET['error']) && $_GET['error'] === 'invalid_image'): ?>
        <p style="color: red;">L’image n’est pas valide (JPEG, PNG, GIF, WebP uniquement).</p>
    <?php endif; ?>
    <?php if (!empty($_GET['success'])): ?>
        <p style="color: green;">Projet sauvegardé avec succès.</p>
    <?php endif; ?>
    <form x-auto-animate method="post" action="/save-project.php" enctype="multipart/form-data"
        x-data="{ 
        questions: <?php echo $json_questions; ?>,
        total : 0,
        calculateTotal() {
            this.total = 0;
            for (let i = 0; i < this.questions.length; i++) {
                if (this.questions[i].solution_points === 'dead' || parseInt(this.questions[i].solution_points) < 0) {
                    continue;   
                }
                this.total += parseInt(this.questions[i].solution_points);
            }
        }
    }"
    x-init="calculateTotal()">
        <input type="hidden" name="project_id" value="<?php echo $project_id_html; ?>">
        <div>
            <label for="enonce"><h4>Énoncé:</h4></label>
            <textarea rows=5 id="enonce" name="enonce" x-data x-autosize required><?php echo $problem_text; ?></textarea>
        </div>
        <div>
            <label for="project_image"><h4>Image du PMP (optionnelle) :</h4></label>
            <?php if ($image_path): ?>
                <div style="margin: 1em 0;">
                    <img src="<?php echo htmlspecialchars($image_path); ?>" alt="Image du projet" style="max-width:300px;max-height:200px;display:block;">
                    <small>Remplacer l’image en important une nouvelle.</small>
                </div>
            <?php endif; ?>
            <input type="file" name="project_image" id="project_image" accept="image/jpeg,image/png,image/gif,image/webp">
        </div>
        <h4>Propositions</h4>
        <template x-for="(question, index) in questions" :key="question.question_id">
            <article>
                <label :for="'question_' + question.question_id">Proposition <span x-text="index + 1"></span>:</label>
                <input type="text" :id="'question_' + question.question_id" :name="'questions[' + question.question_id + '][text]'" x-model="question.question_text" required>
                <label :for="'solution_' + question.question_id">Réponse:</label>
                <textarea x-autosize x-data :id="'solution_' + question.question_id" :name="'questions[' + question.question_id + '][solution]'" x-model="question.solution_text" required></textarea>
                <label :for="'points_' + question.question_id">Points:</label>
                <select :id="'points_' + question.question_id" :name="'questions[' + question.question_id + '][points]'" x-model="question.solution_points">
                    <option value="-2">-2</option>
                    <option value="-1">-1</option>
                    <option value="0">0</option>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="dead">Finir Épreuve</option>
                </select>
                <div class="centered"><button class="secondary" type="button" @click="questions.splice(index, 1)">Supprimer cette Proposition</button></div>
            </article>
        </template>
        <div class="centered"><button type="button" @click="questions.push({question_id: Date.now(), question_text: '', solution_text: '', solution_points: '0'})">Ajouter une Proposition</button></div>
        <button type="submit">Sauvegarder</button>
    </form>
    </main>
    <script>
        document.title = "Modifier PMP";
    </script>
</body>
</html>
