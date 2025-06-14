<?php
require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

$attempt_id = isset($_GET['attempt_id']) ? intval($_GET['attempt_id']) : 0;
$share_token = $_GET['share_token'] ?? null;

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
    // Authenticated user must own the project
    require_once __DIR__ . '/../powertrain/auth.php';
    require_login();
    $user = $_SESSION['user'];
    if ($project['user_id'] !== $user['email']) {
        header('Location: /dashboard');
        exit;
    }
}

// Check if locked
if ($attempt['locked']) {
    header('Location: /dashboard?t=locked');
    exit;
}

// Fetch and shuffle questions
$stmt = $db->prepare('SELECT * FROM project_questions WHERE project_id = ?');
$stmt->execute([$attempt['project_id']]);
$questions = $stmt->fetchAll();
shuffle($questions);

$json_questions = htmlspecialchars(json_encode($questions));
$student_name = htmlspecialchars($attempt['student_name']);
$problem_text = htmlspecialchars($project['problem_text']);
$project_id = htmlspecialchars($attempt['project_id']);
$share_token_input = $attempt['is_guest'] && $share_token
    ? '<input type="hidden" name="share_token" value="' . htmlspecialchars($share_token) . '">'
    : '';

?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title>Tentative - <?php echo $student_name; ?></title>
    <?php require_once __DIR__ . '/../powertrain/head.php' ?>
    <script>
    window.POINTS_LABELS = {
        '2': 'Proposition obligatoire : +2 points',
        '1': 'Proposition utile : +1 point',
        '0': 'Proposition inutile : +0 points',
        '-1': 'Proposition dangereuse : -1 point',
        '-2': 'Proposition dangereuse : -2 points',
        'dead': "Proposition dangereuse, l'épreuve est finie, votre score est 0"
    };
    window.getPointsLabel = function(points) {
        return window.POINTS_LABELS[points] ?? 'erreur';
    }
    </script>
    <style>
      .modal-is-open dialog[open] { display: flex; }
      dialog[open] { animation: fade-in 0.3s; }
      @keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
    </style>
</head>
<body>
<main class="container">
    <h1>Étudiant : <?php echo $student_name; ?></h1>
    <h2>Énoncé : </h2>
    <?php if ($image_url): ?>
        <div class="project-image" style="text-align:center; margin-bottom:1em;">
            <img src="<?php echo $image_url; ?>" alt="Image du projet" style="max-width:100%; width: 36rem; border-radius:8px;">
        </div>
    <?php endif; ?>
    <div style="white-space: pre-line;"><?php echo $problem_text; ?></div>
    <p><b>Quelle est votre conduite à tenir?</b></p>
    <form x-data="{ 
        questions: <?php echo $json_questions; ?>, 
        revealed_questions: $persist([]).as('revealed_questions-<?php echo $attempt_id; ?>').using(sessionStorage),
        total: $persist(0).as('total-<?php echo $attempt_id; ?>').using(sessionStorage), 
        ended: $persist(false).as('ended-<?php echo $attempt_id; ?>').using(sessionStorage),
        timerStarted: $persist(false).as('timer-started-<?php echo $attempt_id; ?>').using(sessionStorage),
        timeLeft: $persist(300).as('time-left-<?php echo $attempt_id; ?>').using(sessionStorage),
        startTimer() {
            this.timerStarted = true;
            const timer = setInterval(() => {
                if (this.timeLeft > 0 && !this.ended) {
                    this.timeLeft--;
                } else {
                    this.ended = true;
                    clearInterval(timer);
                }
            }, 1000);
        },
        formatTime() {
            const minutes = Math.floor(this.timeLeft / 60);
            const seconds = this.timeLeft % 60;
            return `${minutes}:${seconds.toString().padStart(2, '0')}`;
        }
    }" 
    x-init="if (timerStarted) startTimer()"
    @submit.prevent="(ended || timeLeft === 0)
        ? (() => { if (total < 0) total = 0; $el.submit(); })()
        : modal.confirm('Êtes-vous sûr de vouloir terminer cette tentative ?', 'Confirmation')
            .then(result => { if (result) { if (total < 0) total = 0; $el.submit(); } })"
    method="post" action="/save-attempt.php">
        <input type="hidden" name="attempt_id" value="<?php echo $attempt_id; ?>">
        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
        <?php echo $share_token_input; ?>
        <input type="hidden" name="total" x-model="total">
        <template x-for="(revealed_question,index) in revealed_questions" :key="index">
            <input type="hidden" :name="'questions[' + index +']'" x-model="revealed_question">
        </template>

        <div class="timer-section">
            <template x-if="!timerStarted && !ended">
                <div class="centered"><button type="button" @click="startTimer(); requestFullscreen();">Commencer le test</button></div>
            </template>
            <template x-if="timerStarted && !ended">
                <p><b>Temps Restant :</b> <span class="timer" x-text="formatTime()"></span></p>
            </template>
        </div>
        <template x-if="!ended && timerStarted">
            <h2>Propositions : </h2>
        </template>

        <template x-if="timerStarted">
            <div>
                <template x-for="(question, index) in questions" :key="index">
                    <article
                        class="proposition"
                        @click="if (!revealed && !ended && timerStarted) {
                            modal.confirm(question.question_text + '?', 'Confirmation')
                            .then(result => {
                                if (result) {
                                    revealed = true;
                                    revealed_questions.push(question.question_id);
                                    if (question.solution_points == 'dead') {
                                        total = 0;
                                        ended = true;
                                    } else {
                                        total += parseInt(question.solution_points);
                                    }
                                }
                            });
                        }"
                        :class="{ revealed : revealed,
                                    dead : revealed && question.solution_points == 'dead',
                                    correct : revealed && question.solution_points != 'dead' && question.solution_points > 0,
                                    null : revealed && question.solution_points != 'dead' && question.solution_points == 0,
                                    incorrect : revealed && question.solution_points != 'dead' && question.solution_points < 0,
                                    ended : ended
                        }"
                        x-data="{ revealed: $persist(false).as('revealed-' + question.question_id + '-<?php echo $attempt_id; ?>').using(sessionStorage) }"
                    >
                        <div><h5 x-text="question.question_text"></h5></div>
                        <div x-collapse x-show="revealed">
                            <p x-text="question.solution_text"></p>
                            <p class="solution_points" x-text="window.getPointsLabel(question.solution_points)"></p>
                        </div>
                    </article>
                </template>
            </div>
        </template>
        <template x-if="ended">
                <h3>
                    L'épreuve est terminée! Votre score est de <span x-text="total"></span> points.
                </h3>
        </template>
        <div x-show="timerStarted" class="buttons">
            <button type="submit">Terminer la tentative</button>
        </div>
    </form>
</main>
<script>
document.title = "Tentative - <?php echo $student_name; ?>";

function requestFullscreen() {
    const element = document.documentElement;
    if (element.requestFullscreen) {
        element.requestFullscreen();
    } else if (element.mozRequestFullScreen) { // Firefox
        element.mozRequestFullScreen();
    } else if (element.webkitRequestFullscreen) { // Chrome, Safari and Opera
        element.webkitRequestFullscreen();
    } else if (element.msRequestFullscreen) { // IE/Edge
        element.msRequestFullscreen();
    }
}
</script>
<script>
// Pico.css Modal as a singleton
class Modal {
    constructor() {
        this.isOpenClass = "modal-is-open";
        this.openingClass = "modal-is-opening";
        this.closingClass = "modal-is-closing";
        this.animationDuration = 400; // ms
        this.modalElement = null;
        this.resolvePromise = null;
    }

    createModal() {
        const modal = document.createElement('dialog');
        modal.innerHTML = `
            <article>
                <header>
                    <h3>title</h3>
                </header>
                <p>
                    message
                </p>
                <footer>
                    <button role="button" class="confirm-btn" data-target="modal-example">
                        Confirmer</button>
                        <button autofocus class="cancel-btn secondary">
                        Annuler
                    </button>
                </footer>
            </article>
        `;
        document.body.appendChild(modal);
        return modal;
    }

    async confirm(message, title = 'Confirm') {
        if (!this.modalElement) {
            this.modalElement = this.createModal();
            this.setupEventListeners();
        }

        this.modalElement.querySelector('h3').textContent = title;
        this.modalElement.querySelector('p').textContent = message;

        return new Promise((resolve) => {
            this.resolvePromise = resolve;
            this.openModal();
        });
    }

    setupEventListeners() {
        this.modalElement.querySelector('.confirm-btn').addEventListener('click', () => {
            this.closeModal(true);
        });

        this.modalElement.querySelector('.cancel-btn').addEventListener('click', () => {
            this.closeModal(false);
        });

        this.modalElement.addEventListener('click', (event) => {
            const modalContent = this.modalElement.querySelector('article');
            if (!modalContent.contains(event.target)) {
                this.closeModal(false);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && this.modalElement.open) {
                this.closeModal(false);
            }
        });
    }

    openModal() {
        const { documentElement: html } = document;
        html.classList.add(this.isOpenClass, this.openingClass);

        setTimeout(() => {
            html.classList.remove(this.openingClass);
        }, this.animationDuration);

        this.modalElement.showModal();
    }

    closeModal(result) {
        const { documentElement: html } = document;
        html.classList.add(this.closingClass);

        setTimeout(() => {
            html.classList.remove(this.closingClass, this.isOpenClass);
            this.modalElement.close();
            if (this.resolvePromise) {
                this.resolvePromise(result);
                this.resolvePromise = null;
            }
        }, this.animationDuration);
    }
}
const modal = new Modal();
</script>
</body>
</html>
