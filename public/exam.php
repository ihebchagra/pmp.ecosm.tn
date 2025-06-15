<?php
// Current Date and Time (UTC): 2025-06-15 16:11:32
// Current User: ihebchagra
require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

$attempt_id = isset($_GET['attempt_id']) ? intval($_GET['attempt_id']) : 0;
$share_token_from_url = $_GET['share_token'] ?? null;

// --- 1. Fetch Attempt ---
if (!$attempt_id) {
    // No attempt_id, check if this is a shared exam access
    if ($share_token_from_url) {
        // Validate share token is for exam
        $stmt_share = $db->prepare('SELECT * FROM project_shares WHERE share_token = :token AND share_type = \'exam\'');
        $stmt_share->execute(['token' => $share_token_from_url]);
        $share = $stmt_share->fetch(PDO::FETCH_ASSOC);

        if (!$share) {
            die("Token de partage invalide ou ne permettant pas l'accès à l'examen.");
        }

        // This is a valid shared exam access, redirect to start-exam.php
        header('Location: /start-exam.php?share_token=' . urlencode($share_token_from_url));
        exit;
    } else {
        die("ID de tentative manquant ou invalide.");
    }
}

$stmt_attempt = $db->prepare('SELECT * FROM attempts WHERE attempt_id = :aid');
$stmt_attempt->execute(['aid' => $attempt_id]);
$attempt = $stmt_attempt->fetch(PDO::FETCH_ASSOC);
if (!$attempt) { die("Tentative introuvable."); }

// --- 2. Fetch Project ---
$stmt_project = $db->prepare('SELECT * FROM user_projects WHERE project_id = :pid');
$stmt_project->execute(['pid' => $attempt['project_id']]);
$project = $stmt_project->fetch(PDO::FETCH_ASSOC);
if (!$project) { die("Projet associé à la tentative introuvable."); }

// --- 3. Validate Access ---
if ($attempt['is_guest']) {
    if (!$share_token_from_url) { die("Accès non autorisé (token manquant pour l'invité)."); }
    $stmt_share = $db->prepare('SELECT share_token FROM project_shares WHERE project_id = :pid AND share_token = :token AND share_type = \'exam\'');
    $stmt_share->execute(['pid' => $attempt['project_id'], 'token' => $share_token_from_url]);
    if (!$stmt_share->fetch()) { die("Accès non autorisé (token invalide ou ne correspondant pas à cet examen)."); }
} else {
    require_once __DIR__ . '/../powertrain/auth.php';
    require_login();
    if ($project['user_id'] !== $_SESSION['user']['email']) {
        header('Location: /dashboard.php?error=' . urlencode('Accès interdit à cette tentative.'));
        exit;
    }
}

// --- 4. Check if Attempt is Locked ---
if ($attempt['locked']) {
    header('Location: /attempt-result.php?attempt_id=' . $attempt_id .
          ($share_token_from_url ? '&share_token=' . urlencode($share_token_from_url) : ''));
    exit;
}

// --- 5. Fetch Exam Structure ---
$stmt_blocs = $db->prepare('SELECT * FROM project_blocs WHERE project_id = :pid ORDER BY sequence_number ASC');
$stmt_blocs->execute(['pid' => $project['project_id']]);
$blocs_data = $stmt_blocs->fetchAll(PDO::FETCH_ASSOC);
if (empty($blocs_data)) { die("Ce projet ne contient aucun bloc d'énoncé."); }

$stmt_propositions = $db->prepare('SELECT * FROM bloc_propositions WHERE bloc_id = :bid ORDER BY proposition_id ASC');
$stmt_images = $db->prepare('SELECT image_path FROM bloc_images WHERE bloc_id = :bid AND is_deleted = FALSE ORDER BY image_id ASC');

$exam_structure = [];
foreach ($blocs_data as $bloc_row) {
    $current_bloc_data = $bloc_row;
    $stmt_propositions->execute(['bid' => $bloc_row['bloc_id']]);
    $propositions_for_bloc = $stmt_propositions->fetchAll(PDO::FETCH_ASSOC);
    shuffle($propositions_for_bloc);
    $current_bloc_data['propositions'] = $propositions_for_bloc;

    $stmt_images->execute(['bid' => $bloc_row['bloc_id']]);
    $current_bloc_data['images'] = $stmt_images->fetchAll(PDO::FETCH_ASSOC);
    $exam_structure[] = $current_bloc_data;
}

$student_name_html = htmlspecialchars($attempt['student_name']);
$project_name_html = htmlspecialchars($project['project_name']);
$attempt_id_html = htmlspecialchars($attempt['attempt_id']);
$exam_structure_json = htmlspecialchars(json_encode($exam_structure), ENT_QUOTES, 'UTF-8');
$share_token_html = htmlspecialchars($share_token_from_url, ENT_QUOTES, 'UTF-8');

$points_labels_array = [
    '2' => 'Choix essentiel: +2 points', '1' => 'Choix utile: +1 point',
    '0' => 'Choix indifférent : +0 points', '-1' => 'Choix non dangereux mais inefficace : -1 point',
    '-2' => 'Choix dangereux ou inutilement coûteux : -2 points',
    'dead' => "Choix mettant en danger le pronostic vital: votre score est mis à 0 points, bloc/épreuve fini"
];
$points_labels_json = htmlspecialchars(json_encode($points_labels_array), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title>Examen en Cours - <?php echo $student_name_html; ?></title>
    <?php
            require_once __DIR__ . '/../powertrain/head.php';
    ?>
    <style>
        .bloc-item { border: 1px solid #ccc; padding: 1em; margin-bottom: 1em; border-radius: 8px; }
        .problem-text { white-space: pre-line; margin-bottom: 1em;}
        .propositions-list { margin-top: 1em; }
        .proposition-item {
            margin-bottom: 0.75em; padding: 0.75em; background-color: #f9f9f9;
            border: 1px solid #eee; border-radius: 4px; cursor: pointer;
            transition: background-color 0.3s, border-color 0.3s;
        }
        .proposition-item:hover:not(.chosen):not(.disabled) { background-color: #e9e9e9; border-color: #ccc; }
        .proposition-item.chosen { background-color: #d1e7dd; border-color: #a3cfbb; cursor: default; }
        .proposition-item.disabled { cursor: not-allowed; opacity: 0.7; }
        .proposition-item.dead-chosen { background-color: #f8d7da; border-color: #f5c6cb;}
        .solution-text { margin-top: 0.5em; padding: 0.5em; background-color: #fff; border: 1px dashed #ddd; font-size:0.9em;}
        .penalty-info { color: #c57500; font-size: 0.85em; margin-top: 0.3em;}
        .penalty-info.dead { color: #dc3545; font-weight: bold; }
        .bloc-images img { max-width: 36rem; max-height:auto; width: 100%;  display: block; margin: 0 auto; align-self: center; border: 1px solid #ddd; border-radius:4px;}
        [x-cloak] { display: none !important; }
        .timer { font-weight: bold; color: var(--pico-primary); }
        .modal-is-open dialog[open] { display: flex; }
        dialog[open] { animation: fade-in 0.3s; }
        @keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
        .saving-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        .saving-message {
            background: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.3);
        }
    </style>
</head>
<body x-data="examController(<?php echo $exam_structure_json; ?>, <?php echo $attempt_id_html; ?>, <?php echo $points_labels_json; ?>)" x-cloak>
<main class="container">
    <header>
        <h1>Tentative PMP : <?php echo $project_name_html; ?></h1>
        <p>Étudiant: <?php echo $student_name_html; ?></p>
        <!--<p>ID de Tentative: <?php echo $attempt_id_html; ?>-->
        <span x-show="examFlow.examStarted && !examFlow.examFinished && currentBloc()">
            Bloc Actuel: <span x-text="examFlow.currentBlocIndex + 1"></span> / <?php echo count($exam_structure); ?>
        </span>
        </p>
        <p>Stage: <?php echo htmlspecialchars($attempt['stage']); ?>, Niveau: <?php echo htmlspecialchars($attempt['niveau']); ?>, Centre d'examen: <?php echo htmlspecialchars($attempt['centre_exam']); ?></p>
    </header>
    <hr>

    <section x-show="!examFlow.examStarted" id="instructionsSection">
        <h2>Instructions</h2>
        <p>Bienvenue dans votre PMP. Veuillez lire attentivement les instructions avant de commencer.</p>
        <p>Un cas clinique vous sera présenté, accompagné de plusieurs propositions.</p>
        <p>Faites vos choix parmi les options suivantes, en les sélectionnant <b>dans l'ordre qui vous semble le plus approprié</b>.</p>
        <p>L'échelle de cotation est la suivante :</p>
        <ul>
            <li>(-2) choix dangereux ou inutilement coûteux</li>
            <li>(-1) choix non dangereux mais inefficace</li>
            <li>(0) choix neutre</li>
            <li>(+1) choix utile</li>
            <li>(+2) choix essentiel</li>
        </ul>
        <p><b>Tout choix mettant en danger le pronostic vital de la patiente entraînera l'arrêt immédiat de l'épreuve.</b></p>
        <p>Cliquez sur "Commencer l'Épreuve" pour démarrer l'épreuve.</p>
        <div class="centered" style="margin-top:2em; margin-bottom:2em;">
            <button @click="startExam()" class="contrast">Commencer l'Épreuve</button>
        </div>
    </section>

    <section x-show="examFlow.examStarted && !examFlow.examFinished" id="examContentSection">
        <template x-if="currentBloc()">
            <div :key="currentBloc().bloc_id">
                <h3>Bloc <span x-text="examFlow.currentBlocIndex + 1"></span></h3>


                <div x-show="currentBloc().images && currentBloc().images.length > 0" class="bloc-images">
                     <template x-for="image in currentBloc().images" :key="image.image_path">
                        <img :src="'/' + image.image_path" :alt="'Image du bloc ' + (examFlow.currentBlocIndex + 1)">
                    </template>
                </div>
                <h4 style="margin-top: 1rem">Énoncé :</h4>
                <div class="problem-text" x-html="currentBloc().problem_text ? currentBloc().problem_text.replace(/\n/g, '<br>') : ''"></div>
                <p x-show="currentBlocState() && currentBlocState().timeLeft !== null">
                    <b>Temps Restant pour ce Bloc</b>:
                    <span class="timer" x-text="currentBlocState() ? formatTime(currentBlocState().timeLeft) : 'N/A'"></span>
                </p>
                <!--<p x-show="currentBlocState()">
                    Score actuel:
                    <strong x-text="currentBlocState() ? currentBlocState().score : 0"></strong>
                </p>-->

                <div x-show="currentBloc().propositions && currentBloc().propositions.length > 0">
                    <h5>Propositions :</h5>
                    <div class="propositions-list">
                        <template x-for="proposition in currentBloc().propositions" :key="proposition.proposition_id">
                            <div class="proposition-item"
                                 @click="!isPropositionDisabled(proposition.proposition_id) && selectProposition(proposition)"
                                 :class="{
                                     'chosen': isPropositionChosen(proposition.proposition_id),
                                     'dead-chosen': isPropositionChosen(proposition.proposition_id) && (proposition.solution_points < 0 || proposition.solution_points === 'dead' || getPenaltyTypeForProposition(proposition.proposition_id) === 'dead'),
                                     'disabled': isPropositionDisabled(proposition.proposition_id)
                                 }">
                                <p><strong x-text="proposition.proposition_text"></strong></p>
                                <div x-show="isPropositionChosen(proposition.proposition_id)" class="solution-text" x-transition>
                                    <p x-text="getPointsLabel(proposition.solution_points)"></p>
                                    <p x-html="proposition.solution_text ? proposition.solution_text.replace(/\n/g, '<br>') : ''"></p>
                                    <template x-if="getAppliedPenaltyForProposition(proposition.proposition_id)">
                                        <p :class="{'penalty-info': true, 'dead': getPenaltyTypeForProposition(proposition.proposition_id) === 'dead'}">
                                            <strong>Sanction appliquée:</strong>
                                            <span x-text="getPenaltyTypeForProposition(proposition.proposition_id) === 'dead' ?
                                                'Mortelle (score du bloc mis à 0)' :
                                                getAppliedPenaltyForProposition(proposition.proposition_id) + ' points'"></span>
                                            pour choix prématuré.
                                        </p>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="navigation-section" x-show="examFlow.examStarted && !examFlow.examFinished && currentBloc()">
                    <div x-show="currentBlocState() && currentBlocState().isEnded">
                        <h4>Ce bloc est terminé.</h4>
                    </div>
                     <button style="width: 100%" @click="moveToNextBlocOrFinish()" class="primary" :disabled="!currentBlocState()">
                        <span x-text="examFlow.currentBlocIndex < examStructure.length - 1 ? 'Passer au Bloc Suivant' : 'Terminer l\'Examen'"></span>
                    </button>
                </div>
            </div>
        </template>
    </section>

    <!-- Hidden form inputs for share token -->
    <?php if ($share_token_from_url): ?>
        <input type="hidden" name="share_token" value="<?php echo $share_token_html; ?>">
    <?php endif; ?>

    <hr>
</main>

<!-- Saving overlay -->
<div class="saving-overlay" x-show="isSaving" style="display:none;">
    <div class="saving-message">
        <h3>Enregistrement en cours...</h3>
        <p>Veuillez patienter pendant que nous enregistrons vos résultats.</p>
    </div>
</div>

<script>
class Modal {
    constructor() {
        this.isOpenClass = "modal-is-open"; this.openingClass = "modal-is-opening";
        this.closingClass = "modal-is-closing"; this.animationDuration = 400;
        this.modalElement = null; this.resolvePromise = null; this._init();
    }
    _init() {
        if (document.getElementById('pico-modal-container')) return;
        const modalContainer = document.createElement('div');
        modalContainer.id = 'pico-modal-container';
        modalContainer.innerHTML = `
            <dialog id="pico-modal"> <article> <header>
            <button aria-label="Close" rel="prev" class="pico-modal-close"></button>
            <h3 class="pico-modal-title">Confirmation</h3> </header>
            <p class="pico-modal-message">Êtes-vous sûr?</p> <footer>
            <button class="pico-modal-confirm">Confirmer</button>
            <button class="secondary pico-modal-cancel" autofocus>Annuler</button>
            </footer> </article> </dialog> `;
        document.body.appendChild(modalContainer);
        this.modalElement = document.getElementById('pico-modal');
        this.setupEventListeners();
    }
    setupEventListeners() {
        this.modalElement.querySelector('.pico-modal-confirm').addEventListener('click', () => this.closeModal(true));
        this.modalElement.querySelector('.pico-modal-cancel').addEventListener('click', () => this.closeModal(false));
        this.modalElement.querySelector('.pico-modal-close').addEventListener('click', () => this.closeModal(false));
        this.modalElement.addEventListener('click', (event) => {
            if (event.target === this.modalElement) this.closeModal(false);
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && this.modalElement && this.modalElement.open) this.closeModal(false);
        });
    }
    async confirm(message = 'Êtes-vous sûr?', title = 'Confirmation') {
        if (!this.modalElement) this._init();
        this.modalElement.querySelector('.pico-modal-title').textContent = title;
        this.modalElement.querySelector('.pico-modal-message').textContent = message;
        return new Promise((resolve) => { this.resolvePromise = resolve; this.openModal(); });
    }
    openModal() {
        if (!this.modalElement) return;
        const { documentElement: html } = document;
        html.classList.add(this.isOpenClass, this.openingClass);
        this.modalElement.showModal();
        setTimeout(() => html.classList.remove(this.openingClass), this.animationDuration);
    }
    closeModal(result) {
        if (!this.modalElement || !this.modalElement.open) {
          if (this.resolvePromise) { this.resolvePromise(result === undefined ? false : result); this.resolvePromise = null; }
          return;
        }
        const { documentElement: html } = document;
        html.classList.add(this.closingClass);
        setTimeout(() => {
            html.classList.remove(this.closingClass, this.isOpenClass);
            if (this.modalElement) this.modalElement.close();
            if (this.resolvePromise) { this.resolvePromise(result); this.resolvePromise = null; }
        }, this.animationDuration);
    }
}
const modal = new Modal();

function examController(examData, attemptId, pointsLabels) {
    return {
        examStructure: examData,
        attemptId: attemptId,
        pointsLabels: pointsLabels,
        activeTimers: {},
        isSaving: false,

        examFlow: {
            _examStarted: Alpine.$persist(false).as(`examStarted-${attemptId}`),
            _currentBlocIndex: Alpine.$persist(-1).as(`currentBlocIndex-${attemptId}`),
            _overallScore: Alpine.$persist(0).as(`overallScore-${attemptId}`),
            _examFinished: Alpine.$persist(false).as(`examFinished-${attemptId}`),
            get examStarted() { return this._examStarted; }, set examStarted(v) { this._examStarted = v; },
            get currentBlocIndex() { return this._currentBlocIndex; }, set currentBlocIndex(v) { this._currentBlocIndex = v; },
            get overallScore() { return this._overallScore; }, set overallScore(v) { this._overallScore = v; },
            get examFinished() { return this._examFinished; }, set examFinished(v) { this._examFinished = v; },
        },
        // blocsState will store { bloc_id: {score, timeLeft, isEnded, chosenPropositionIds, appliedPenalties: {proposition_id: penalty_value} } }
        blocsState: Alpine.$persist({}).as(`blocsState-${attemptId}`),

        currentBloc() {
            if (this.examFlow.examStarted && this.examFlow.currentBlocIndex >= 0 && this.examFlow.currentBlocIndex < this.examStructure.length) {
                return this.examStructure[this.examFlow.currentBlocIndex];
            }
            return null;
        },
        currentBlocState() {
            const cb = this.currentBloc();
            if (!cb) return null;
            if (!this.blocsState[cb.bloc_id]) {
                this.blocsState[cb.bloc_id] = {
                    score: 0,
                    timeLeft: cb.time_limit_seconds !== null ? parseInt(cb.time_limit_seconds) : null,
                    isEnded: false,
                    chosenPropositionIds: [],
                    appliedPenalties: {} // To store penalties applied for specific propositions
                };
            }
            return this.blocsState[cb.bloc_id];
        },

        init() {
            console.log(`INIT: Attempt: ${this.attemptId}, Started: ${this.examFlow.examStarted}, Finished: ${this.examFlow.examFinished}, Index: ${this.examFlow.currentBlocIndex}`);
            if (this.examFlow.examStarted && !this.examFlow.examFinished && this.currentBloc()) {
                const blocId = this.currentBloc().bloc_id;
                const state = this.currentBlocState();
                if (state) {
                    if (!state.appliedPenalties) { state.appliedPenalties = {}; } // Ensure appliedPenalties object exists on older persisted states
                    console.log(`INIT: Bloc ${blocId} state: isEnded=${state.isEnded}, timeLeft=${state.timeLeft}`);
                    if (!state.isEnded && state.timeLeft !== null && state.timeLeft > 0 && !this.activeTimers[blocId]) {
                        console.log(`INIT: Conditions met for RESTARTING timer for bloc ${blocId}.`);
                        this.startBlocTimer();
                    } else {
                        console.log(`INIT: Timer for bloc ${blocId} NOT restarted. Ended: ${state.isEnded}, TimeLeft: ${state.timeLeft}, ActiveTimer: ${!!this.activeTimers[blocId]}`);
                    }
                }
            }
        },
        startExam() {
            if (!this.examFlow.examStarted) {
                this.examFlow.examStarted = true;
                this.examFlow.currentBlocIndex = 0;
                this.examFlow.examFinished = false;
                this.examFlow.overallScore = 0;
                Object.keys(this.blocsState).forEach(key => delete this.blocsState[key]);
                this.activeTimers = {};
                this.$nextTick(() => {
                    const initState = this.currentBlocState();
                    if (initState) this.startBlocTimer();
                });
                console.log("Épreuve commencée, bloc:", this.examFlow.currentBlocIndex + 1);
            }
        },
        startBlocTimer() {
            const cb = this.currentBloc();
            if (!cb) { console.error("StartBlocTimer: No current bloc."); return; }
            const blocId = cb.bloc_id;
            const state = this.currentBlocState();
            if (!state) { console.error(`StartBlocTimer: No state for bloc ${blocId}.`); return; }
            if (state.isEnded || state.timeLeft === null || state.timeLeft <= 0 || this.activeTimers[blocId]) {
                return;
            }
            const intervalId = setInterval(() => {
                const currentTimerState = this.blocsState[blocId];
                if (!currentTimerState) {
                    clearInterval(this.activeTimers[blocId]); delete this.activeTimers[blocId]; return;
                }
                if (currentTimerState.timeLeft > 0 && !currentTimerState.isEnded) {
                    currentTimerState.timeLeft--;
                } else {
                    if (!currentTimerState.isEnded) this.endCurrentBlocDueToTimer();
                    else this.stopBlocTimer();
                }
            }, 1000);
            this.activeTimers[blocId] = intervalId;
            console.log(`Timer STARTED for bloc ${blocId}. timeLeft: ${state.timeLeft}`);
        },
        stopBlocTimer() {
            const cb = this.currentBloc();
            if (!cb) { return; }
            const blocId = cb.bloc_id;
            if (this.activeTimers[blocId]) {
                clearInterval(this.activeTimers[blocId]);
                delete this.activeTimers[blocId];
                console.log(`Timer STOPPED for bloc ${blocId}`);
            }
        },
        endCurrentBlocDueToTimer() {
            const cb = this.currentBloc();
            if (!cb) return;
            const state = this.currentBlocState();
            if (state && !state.isEnded) {
                console.log(`Bloc ${cb.bloc_id} ended due to timer.`);
                this.stopBlocTimer();
                state.isEnded = true;
            }
        },

        async selectProposition(proposition) {
            const state = this.currentBlocState();
            if (!state || state.isEnded || this.isPropositionChosen(proposition.proposition_id)) return;

            const confirmed = await modal.confirm(`Confirmez-vous le choix : "${proposition.proposition_text}" ?`, 'Confirmation');
            if (confirmed) {
                // --- PENALTY LOGIC ---
                if (proposition.precedent_proposition_for_penalty_id !== null &&
                    proposition.penalty_value_if_chosen_early !== null) {

                    const requiredPrecedentId = proposition.precedent_proposition_for_penalty_id;
                    console.log(requiredPrecedentId);
                    if (!state.chosenPropositionIds.includes(requiredPrecedentId)) {
                        // Precedent NOT chosen yet, apply penalty
                        if (proposition.penalty_value_if_chosen_early === 'dead') {
                            // Handle 'dead' penalty - set score to 0 and end the bloc
                            state.score = 0;
                            state.appliedPenalties[proposition.proposition_id] = 'dead';
                            state.isEnded = true;
                            this.stopBlocTimer();
                            console.log(`DEAD PENALTY APPLIED for prop ${proposition.proposition_id}. Score reset to 0. Bloc ended.`);
                        } else {
                            // Handle numeric penalty (-1, -2)
                            const penaltyValue = parseInt(proposition.penalty_value_if_chosen_early) || 0;
                            state.score += penaltyValue;
                            state.appliedPenalties[proposition.proposition_id] = penaltyValue;
                            console.log(`NUMERIC PENALTY APPLIED for prop ${proposition.proposition_id}: ${penaltyValue}. New score: ${state.score}`);
                        }
                    }
                }
                // --- END PENALTY LOGIC ---

                state.chosenPropositionIds.push(proposition.proposition_id);

                if (proposition.solution_points === 'dead') {
                    state.score = 0; // DEAD sets score to 0, overriding previous points/penalties for the bloc
                    this.stopBlocTimer();
                    state.isEnded = true;
                    console.log(`DEAD solution_points for prop ${proposition.proposition_id} chosen. Bloc score is 0. Bloc ended.`);
                } else {
                    const regularPoints = parseInt(proposition.solution_points) || 0;
                    state.score += regularPoints;
                    console.log(`Regular points for prop ${proposition.proposition_id}: ${regularPoints}. New score: ${state.score}`);
                }
            } else {
                console.log("Proposition selection cancelled.");
            }
        },

        getAppliedPenaltyForProposition(propositionId) {
            const state = this.currentBlocState();
            if (state && state.appliedPenalties && state.appliedPenalties[propositionId] !== undefined) {
                return state.appliedPenalties[propositionId];
            }
            return null;
        },

        // Helper function to determine the type of penalty (dead or numeric)
        getPenaltyTypeForProposition(propositionId) {
            const penalty = this.getAppliedPenaltyForProposition(propositionId);
            if (penalty === 'dead') {
                return 'dead';
            }
            return 'numeric';
        },

        isPropositionChosen(propositionId) {
            const state = this.currentBlocState();
            return state && state.chosenPropositionIds.includes(propositionId);
        },
        isPropositionDisabled(propositionId) {
            const state = this.currentBlocState();
            return (state && state.isEnded) || this.isPropositionChosen(propositionId);
        },
        getPointsLabel(pointsValue) { return this.pointsLabels[pointsValue] || 'Points non définis'; },
        formatTime(totalSeconds) {
            if (totalSeconds === null || totalSeconds < 0) return "N/A";
            const minutes = Math.floor(totalSeconds / 60);
            const seconds = totalSeconds % 60;
            return `${minutes}:${seconds.toString().padStart(2, '0')}`;
        },
        async moveToNextBlocOrFinish() {
            const cbState = this.currentBlocState();
            if (!cbState) { console.error("Cannot move: no current bloc state."); return; }
            if (!cbState.isEnded) {
                const confirmMessage = this.examFlow.currentBlocIndex < this.examStructure.length - 1 ?
                    "Passer au bloc suivant ? Votre score actuel pour ce bloc sera enregistré." :
                    "Terminer l'examen ? Votre score actuel pour ce bloc sera enregistré.";
                const userConfirmedSkip = await modal.confirm(confirmMessage, "Confirmation");
                if (!userConfirmedSkip) return;
                this.stopBlocTimer();
                cbState.isEnded = true;
            }
            this.stopBlocTimer();

            // Check if this is the last bloc
            if (this.examFlow.currentBlocIndex < this.examStructure.length - 1) {
                this.examFlow.currentBlocIndex++;
                this.$nextTick(() => {
                    const initState = this.currentBlocState();
                    if (initState) this.startBlocTimer();
                });
            } else {
                // This is the last bloc - finalize and automatically submit
                this.finishExam();
                // Submit results immediately instead of showing the finished screen
                this.submitAttemptResults();
            }
        },
        finishExam() {
            this.stopBlocTimer();
            let calculatedOverallScore = 0;
            for (const blocId in this.blocsState) {
                if (this.blocsState.hasOwnProperty(blocId) && this.blocsState[blocId].score !== undefined) {
                    calculatedOverallScore += this.blocsState[blocId].score;
                }
            }
            this.examFlow.overallScore = calculatedOverallScore;
            this.examFlow.examFinished = true;
            console.log("Examen terminé. Score total:", this.examFlow.overallScore);
        },
        submitAttemptResults() {
            this.isSaving = true;
            console.log("Soumission des résultats... Score:", this.examFlow.overallScore, "Détails:", JSON.parse(JSON.stringify(this.blocsState)));

            // Create form and submit data to save-attempt.php
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'save-attempt.php';

            const attemptIdInput = document.createElement('input');
            attemptIdInput.type = 'hidden';
            attemptIdInput.name = 'attempt_id';
            attemptIdInput.value = this.attemptId;
            form.appendChild(attemptIdInput);

            const scoreInput = document.createElement('input');
            scoreInput.type = 'hidden';
            scoreInput.name = 'overall_score';
            scoreInput.value = this.examFlow.overallScore;
            form.appendChild(scoreInput);

            const blocsStateInput = document.createElement('input');
            blocsStateInput.type = 'hidden';
            blocsStateInput.name = 'blocs_state';
            blocsStateInput.value = JSON.stringify(this.blocsState);
            form.appendChild(blocsStateInput);

            // Add share token if it exists in the page
            const shareTokenElement = document.querySelector('input[name="share_token"]');
            if (shareTokenElement) {
                const shareTokenInput = document.createElement('input');
                shareTokenInput.type = 'hidden';
                shareTokenInput.name = 'share_token';
                shareTokenInput.value = shareTokenElement.value;
                form.appendChild(shareTokenInput);
            }

            // Clear persisted storage once submitted
            this.clearPersistedExamState();

            document.body.appendChild(form);
            form.submit();
        },
        clearPersistedExamState() {
            console.warn(`Clearing ALL persisted exam state for attempt ${this.attemptId}`);
            localStorage.removeItem(`examStarted-${this.attemptId}`);
            localStorage.removeItem(`currentBlocIndex-${this.attemptId}`);
            localStorage.removeItem(`overallScore-${this.attemptId}`);
            localStorage.removeItem(`examFinished-${this.attemptId}`);
            localStorage.removeItem(`blocsState-${this.attemptId}`);
            this.activeTimers = {};
        }
    }
}
</script>
</body>
</html>
