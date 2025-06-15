<?php
// Current Date and Time (UTC): 2025-06-15 17:20:49
// Current User's Login: ihebchagra
require_once __DIR__ . '/../powertrain/db.php';
$db = get_db();

$attempt_id = isset($_GET['attempt_id']) ? intval($_GET['attempt_id']) : 0;
$share_token_from_url = isset($_GET['share_token']) ? $_GET['share_token'] : null;

if (!$attempt_id) {
    die("ID de tentative manquant ou invalide.");
}

// --- 1. Fetch Attempt ---
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
$current_user_email = null; 
$is_guest_with_valid_token_for_results = false;

if ($attempt['is_guest']) {
    if (!$share_token_from_url) {
        // If it's a guest attempt, a share token is mandatory to view results.
        // This prevents direct URL guessing for guest attempt results.
        die("Accès non autorisé. Token de partage manquant pour afficher les résultats de cette tentative d'invité.");
    }
    // Validate the share token: must be for 'exam' OR 'results' and match the project_id of the attempt.
    $stmt_share_validation = $db->prepare('
        SELECT s.share_type 
        FROM project_shares s 
        WHERE s.share_token = :token AND s.project_id = :pid AND s.share_type IN (\'exam\', \'results\')
    ');
    $stmt_share_validation->execute([
        'token' => $share_token_from_url,
        'pid' => $attempt['project_id'] // Ensure token is for the correct project
    ]);
    $valid_share = $stmt_share_validation->fetch(PDO::FETCH_ASSOC);
    if ($valid_share) {
        // Token is valid for this project and is either an 'exam' or 'results' token.
        $is_guest_with_valid_token_for_results = true;
    } else {
        die("Accès non autorisé. Token de partage invalide, ne correspond pas à ce projet, ou ne permet pas de voir les résultats.");
    }
} else {
    // Not a guest attempt, so it must be an attempt by a registered user. Login is required.
    require_once __DIR__ . '/../powertrain/auth.php';
    require_login();
    $current_user_email = $_SESSION['user']['email'];
    if ($project['user_id'] !== $current_user_email) {
        // Logged in, but does not own the project this attempt belongs to.
        header('Location: /dashboard.php?error=' . urlencode('Accès interdit à cette tentative.'));
        exit;
    }
}

// --- 4. Ensure attempt is locked to view results ---
// (This is crucial, especially for guest attempts, to prevent viewing ongoing exam results)
if (!$attempt['locked']) {
    if (!$is_guest_with_valid_token_for_results && $current_user_email) {
        // Owner trying to view their own unlocked attempt: redirect to continue exam.
        header('Location: /exam.php?attempt_id=' . $attempt_id);
        exit;
    } else {
        // Guest trying to view an unlocked attempt result (even with a token) or some other edge case.
        // This indicates the exam isn't finished.
        die("Les résultats ne sont pas encore disponibles pour cette tentative. L'examen doit être terminé.");
    }
}

// --- 5. Fetch Blocs, Propositions, Answers, Images, and Calculate Scores ---
// (The scoring and data fetching logic remains the same as your previous correct version)
$stmt_blocs_for_project = $db->prepare("SELECT * FROM project_blocs WHERE project_id = ? ORDER BY sequence_number ASC");
$stmt_blocs_for_project->execute([$attempt['project_id']]);
$blocs_for_project = $stmt_blocs_for_project->fetchAll(PDO::FETCH_ASSOC);

$stmt_all_props_for_project = $db->prepare("
    SELECT bp.*, pb.sequence_number as bloc_sequence 
    FROM bloc_propositions bp
    JOIN project_blocs pb ON bp.bloc_id = pb.bloc_id
    WHERE pb.project_id = ?
    ORDER BY pb.sequence_number, bp.proposition_id
");
$stmt_all_props_for_project->execute([$attempt['project_id']]);
$all_propositions_data = $stmt_all_props_for_project->fetchAll(PDO::FETCH_ASSOC);

$propositions_by_bloc_id = [];
foreach ($all_propositions_data as $prop_item) {
    $propositions_by_bloc_id[$prop_item['bloc_id']][] = $prop_item;
}

$stmt_answers_for_attempt = $db->prepare("
    SELECT aa.*, bp.proposition_text, bp.solution_text, bp.solution_points, bp.bloc_id 
    FROM attempt_answers aa
    JOIN bloc_propositions bp ON aa.proposition_id = bp.proposition_id
    WHERE aa.attempt_id = ?
");
$stmt_answers_for_attempt->execute([$attempt_id]);
$answers_data = $stmt_answers_for_attempt->fetchAll(PDO::FETCH_ASSOC);

$answers_by_bloc_id = [];
$bloc_raw_scores_map = [];

foreach ($answers_data as $answer_item) {
    $bloc_id_for_answer = $answer_item['bloc_id'];
    if (!isset($answers_by_bloc_id[$bloc_id_for_answer])) {
        $answers_by_bloc_id[$bloc_id_for_answer] = [];
        $bloc_raw_scores_map[$bloc_id_for_answer] = 0; // Initialize raw score for the bloc
    }
    $answers_by_bloc_id[$bloc_id_for_answer][] = $answer_item;

    // Recalculate bloc's raw score based on ALL its answers so far
    // This ensures 'dead' propositions correctly zero out the score for the bloc
    $current_bloc_has_dead = false;
    $current_bloc_temp_score = 0;
    foreach($answers_by_bloc_id[$bloc_id_for_answer] as $ans_in_bloc) {
        if ($ans_in_bloc['solution_points'] === 'dead' || $ans_in_bloc['penalty_applied'] === 'dead') {
            $current_bloc_has_dead = true;
            break; 
        }
        // Only add points if not dead
        if ($ans_in_bloc['solution_points'] !== null) { // 'dead' is already checked
            $current_bloc_temp_score += (int)$ans_in_bloc['solution_points'];
        }
        if ($ans_in_bloc['penalty_applied'] !== null) { // 'dead' is already checked
             $current_bloc_temp_score += (int)$ans_in_bloc['penalty_applied'];
        }
    }
    $bloc_raw_scores_map[$bloc_id_for_answer] = $current_bloc_has_dead ? 0 : $current_bloc_temp_score;
}


$bloc_min_max_scores = []; // Stores min, max, range for each bloc_id
$bloc_normalized_scores_map = []; // Stores final normalized score /20 for each bloc_id

foreach ($blocs_for_project as $bloc_item) {
    $bloc_id_current = $bloc_item['bloc_id'];
    $min_s = 0; $max_s = 0;
    if (isset($propositions_by_bloc_id[$bloc_id_current])) {
        foreach ($propositions_by_bloc_id[$bloc_id_current] as $prop_for_minmax) {
            if ($prop_for_minmax['solution_points'] === 'dead') continue; // Dead props don't count for min/max range
            $pts = (int)$prop_for_minmax['solution_points'];
            if ($pts < 0) $min_s += $pts;
            else if ($pts > 0) $max_s += $pts;
        }
    }
    $current_range = $max_s + abs($min_s);
    $bloc_min_max_scores[$bloc_id_current] = ['min' => $min_s, 'max' => $max_s, 'range' => $current_range];
    
    $raw_s = $bloc_raw_scores_map[$bloc_id_current] ?? 0;
    
    if ($current_range > 0) {
        $norm_s = round((($raw_s + abs($min_s)) / $current_range) * 20, 2);
    } else { 
        // If range is 0 (e.g., all propositions have 0 points, no negatives)
        // If raw score is also 0, it means student got all 0-point items right (or didn't pick any) -> perfect score for this type of bloc.
        // If raw score is not 0 (shouldn't happen if range is 0 and no dead), then 0.
        $norm_s = ($raw_s == 0) ? 20 : 0; 
    }
    $bloc_normalized_scores_map[$bloc_id_current] = $norm_s;
}

$total_normalized_score_final_recalc = 0; // Recalculated total score based on normalized bloc scores
if (count($bloc_normalized_scores_map) > 0) {
    $total_normalized_score_final_recalc = round(array_sum($bloc_normalized_scores_map) / count($bloc_normalized_scores_map), 2);
}
// The $attempt['result'] should ideally match this $total_normalized_score_final_recalc.
// If not, it might indicate that scoring rules changed after the attempt was saved, or there was an issue during saving.

$stmt_images_for_project = $db->prepare("
    SELECT bi.* 
    FROM bloc_images bi
    JOIN project_blocs pb ON bi.bloc_id = pb.bloc_id
    WHERE pb.project_id = ? AND bi.is_deleted = FALSE
");
$stmt_images_for_project->execute([$attempt['project_id']]);
$all_images_data = $stmt_images_for_project->fetchAll(PDO::FETCH_ASSOC);
$images_by_bloc_id = [];
foreach ($all_images_data as $img_item) {
    $images_by_bloc_id[$img_item['bloc_id']][] = $img_item;
}

// Get 'exam' share token if it exists, for "start new attempt" link for this project
$exam_share_token_for_project = null;
if ($project) { // Project should always be defined at this point
    $stmt_exam_token = $db->prepare("SELECT share_token FROM project_shares WHERE project_id = :pid AND share_type = 'exam'");
    $stmt_exam_token->execute(['pid' => $project['project_id']]);
    $exam_share_row = $stmt_exam_token->fetch(PDO::FETCH_ASSOC);
    if ($exam_share_row) {
        $exam_share_token_for_project = $exam_share_row['share_token'];
    }
}

$student_name_html = htmlspecialchars($attempt['student_name']);
$project_name_html = htmlspecialchars($project['project_name']);
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title>Résultats - <?php echo $student_name_html; ?></title>
    <?php
    require_once __DIR__ . '/../powertrain/head.php';
    ?>
    <style>
        /* Styles from your previous version, ensure they are suitable for both views */
        .bloc { border: 1px solid #ccc; padding: 1em; margin-bottom: 1em; border-radius: 8px; background-color: #f9f9f9; }
        .chosen-proposition { margin-bottom: 0.75em; padding: 0.75em; background-color: #d1e7dd; border: 1px solid #a3cfbb; border-radius: 4px; }
        .chosen-proposition.dead { background-color: #f8d7da; border-color: #f5c6cb; }
        .solution-text { margin-top: 0.5em; padding: 0.5em; background-color: #fff; border: 1px dashed #ddd; }
        .penalty-info { color: #c57500; font-size: 0.85em; margin-top: 0.3em; }
        .penalty-info.dead { color: #dc3545; font-weight: bold; }
        .points-badge { display: inline-block; padding: 0.25em 0.5em; border-radius: 0.25em; font-weight: bold; margin-left: 0.5em; }
        .points-positive { background-color: #d1e7dd; color: #0a3622; }
        .points-negative { background-color: #f8d7da; color: #842029; }
        .points-neutral { background-color: #e2e3e5; color: #41464b; }
        .points-dead { background-color: #842029; color: white; }
        .bloc-images { margin: 1em 0; }
        .bloc-images img { max-width: 200px; max-height: 150px; margin-right: 10px; border: 1px solid #ddd; border-radius: 4px; }
        .score-details { background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 0.75em; margin-top: 1em; }
        .score-row { display: flex; justify-content: space-between; margin-bottom: 0.5em; }
        .score-label { font-weight: bold; }
        .final-score { font-size: 1.25em; font-weight: bold; margin-top: 0.5em; padding-top: 0.5em; border-top: 1px solid #dee2e6; }
        .action-buttons { margin: 2em 0; display: flex; gap: 1em; justify-content: center; }
         <?php if ($is_guest_with_valid_token_for_results): ?>
        body { padding: 1em; }
        .container { max-width: 900px; margin: auto;} /* Center content for guest view */
        <?php endif; ?>
    </style>
</head>
<body>
    <?php if (!$is_guest_with_valid_token_for_results && $current_user_email): // Show navbar only for logged-in owners ?>
    <nav id="navbar">
        <ul>
            <li><a href="/dashboard.php">Tableau de Bord</a></li>
            <li><a href="/results.php?project_id=<?php echo $project['project_id']; ?>">Tous les résultats de "<?php echo $project_name_html; ?>"</a></li>
        </ul>
    </nav>
    <?php endif; ?>

    <main class="container">
        <header style="text-align: center;">
            <h1>Résultats d'Examen</h1>
            <p><strong>Projet:</strong> <?php echo $project_name_html; ?></p>
            <p><strong>Étudiant:</strong> <?php echo $student_name_html; ?></p>
            <p><strong>Date:</strong> <?php echo date('d/m/Y H:i', strtotime($attempt['created_at'])); ?></p>
            <?php if ($attempt['stage'] || $attempt['niveau'] || $attempt['centre_exam']): ?>
                <p>
                    <?php if ($attempt['stage']): ?><strong>Stage:</strong> <?php echo htmlspecialchars($attempt['stage']); ?> <?php endif; ?>
                    <?php if ($attempt['niveau']): ?><strong>Niveau:</strong> <?php echo htmlspecialchars($attempt['niveau']); ?> <?php endif; ?>
                    <?php if ($attempt['centre_exam']): ?><strong>Centre:</strong> <?php echo htmlspecialchars($attempt['centre_exam']); ?> <?php endif; ?>
                </p>
            <?php endif; ?>
            <h2>Score total enregistré: <?php echo htmlspecialchars($attempt['result']); ?>/20</h2>
            <?php if (abs($attempt['result'] - $total_normalized_score_final_recalc) > 0.01) : ?>
                <p style="color:orange; font-size:0.9em;">(Score recalculé sur cette page: <?php echo $total_normalized_score_final_recalc; ?>/20. Une différence peut indiquer une mise à jour du barème depuis l'enregistrement.)</p>
            <?php endif; ?>
        </header>
        <hr>

        <?php foreach ($blocs_for_project as $bloc_display_item):
            $bloc_id_disp = $bloc_display_item['bloc_id'];
            $raw_s_disp = $bloc_raw_scores_map[$bloc_id_disp] ?? 0;
            $norm_s_disp = $bloc_normalized_scores_map[$bloc_id_disp] ?? 0;
            $min_s_disp = $bloc_min_max_scores[$bloc_id_disp]['min'] ?? 0;
            $max_s_disp = $bloc_min_max_scores[$bloc_id_disp]['max'] ?? 0;
            $range_s_disp = $bloc_min_max_scores[$bloc_id_disp]['range'] ?? 1;
            if ($range_s_disp == 0) $range_s_disp = 1; // Avoid division by zero in display if range was 0
            
            $score_class_disp = ($raw_s_disp > 0) ? 'points-positive' : (($raw_s_disp < 0) ? 'points-negative' : 'points-neutral');
            if ($bloc_raw_scores_map[$bloc_id_disp] === 0 && $bloc_normalized_scores_map[$bloc_id_disp] == 20 && $bloc_min_max_scores[$bloc_id_disp]['range'] == 0){
                // Special case: bloc with only 0-point propositions, student got 0 raw, means perfect 20
                $score_class_disp = 'points-positive';
            }

        ?>
        <article class="bloc">
            <h3>
                Bloc <?php echo htmlspecialchars($bloc_display_item['sequence_number']); ?>
                <span class="points-badge <?php echo $score_class_disp; ?>">
                    <?php echo $norm_s_disp; ?>/20
                </span>
            </h3>
            <div class="score-details">
                <div class="score-row"><span class="score-label">Score brut obtenu:</span> <span><?php echo $raw_s_disp; ?> points</span></div>
                <div class="score-row"><span class="score-label">Score minimum possible (hors mortel):</span> <span><?php echo $min_s_disp; ?> points</span></div>
                <div class="score-row"><span class="score-label">Score maximum possible (hors mortel):</span> <span><?php echo $max_s_disp; ?> points</span></div>
                <div class="score-row"><span class="score-label">Échelle de conversion (Max + |Min|):</span> <span><?php echo $bloc_min_max_scores[$bloc_id_disp]['range']; ?></span></div>
                <div class="score-row final-score"><span>Score normalisé pour ce bloc:</span> <span><?php echo $norm_s_disp; ?>/20</span></div>
            </div>
            <div class="bloc-text" style="margin-top:1em; white-space: pre-line;"><?php echo htmlspecialchars($bloc_display_item['problem_text']); ?></div>
            
            <?php if (!empty($images_by_bloc_id[$bloc_id_disp])): ?>
            <div class="bloc-images">
                <?php foreach ($images_by_bloc_id[$bloc_id_disp] as $image_item): ?>
                    <img src="/<?php echo htmlspecialchars($image_item['image_path']); ?>" alt="Image du bloc">
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <h4>Propositions choisies par l'étudiant:</h4>
            <?php if (!empty($answers_by_bloc_id[$bloc_id_disp])):
                foreach ($answers_by_bloc_id[$bloc_id_disp] as $answer_display_item):
                    $is_dead_disp = ($answer_display_item['solution_points'] === 'dead' || $answer_display_item['penalty_applied'] === 'dead');
            ?>
            <div class="chosen-proposition <?php echo $is_dead_disp ? 'dead' : ''; ?>">
                <p><strong><?php echo htmlspecialchars($answer_display_item['proposition_text']); ?></strong></p>
                <div class="solution-text">
                    <p>
                        <?php if ($answer_display_item['solution_points'] === 'dead'): ?> <span class="points-badge points-dead">Mortel</span>
                        <?php elseif ((int)$answer_display_item['solution_points'] > 0): ?> <span class="points-badge points-positive"><?php echo $answer_display_item['solution_points']; ?> points</span>
                        <?php elseif ((int)$answer_display_item['solution_points'] < 0): ?> <span class="points-badge points-negative"><?php echo $answer_display_item['solution_points']; ?> points</span>
                        <?php else: ?> <span class="points-badge points-neutral"><?php echo $answer_display_item['solution_points']; ?> points</span>
                        <?php endif; ?>
                        <?php echo nl2br(htmlspecialchars($answer_display_item['solution_text'])); ?>
                    </p>
                    <?php if ($answer_display_item['penalty_applied']): ?>
                    <p class="penalty-info <?php echo $answer_display_item['penalty_applied'] === 'dead' ? 'dead' : ''; ?>">
                        <strong>Pénalité appliquée:</strong>
                        <?php echo $answer_display_item['penalty_applied'] === 'dead' ? 'Mortelle (score du bloc mis à 0)' : ($answer_display_item['penalty_applied'] . ' points'); ?>
                        pour choix prématuré.
                    </p>
                    <?php endif; ?>
                </div>
            </div>
            <?php   endforeach;
                  else: ?>
            <p>Aucune proposition n'a été choisie pour ce bloc.</p>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
        
        <div class="action-buttons">
            <?php if (!$is_guest_with_valid_token_for_results && $current_user_email): // Owner links ?>
                <a href="/results.php?project_id=<?php echo $project['project_id']; ?>" role="button" class="secondary">Voir tous les résultats de ce projet</a>
                <a href="/dashboard.php" role="button" class="secondary">Retour au Tableau de Bord</a>
            <?php endif; ?>
            <?php if ($exam_share_token_for_project): // Link to start new attempt if 'exam' share token exists for this project ?>
                <a href="/start-exam.php?share_token=<?php echo urlencode($exam_share_token_for_project); ?>" role="button" class="contrast">
                    <?php echo ($is_guest_with_valid_token_for_results || !$current_user_email) ? 'Faire (ou refaire) une tentative' : 'Démarrer une nouvelle tentative (Invité)'; ?>
                </a>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
