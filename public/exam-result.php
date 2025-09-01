<?php
// Current Date and Time (UTC): 2025-06-15 10:13:29
// Current User: ihebchagra
require_once __DIR__ . '/../powertrain/db.php';
require_once __DIR__ . '/../powertrain/auth.php';

$db = get_db();

$attempt_id = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;
$share_token_from_url = $_GET['share_token'] ?? null;

if (!$attempt_id) {
    die("ID de tentative manquant ou invalide.");
}

// Fetch attempt data
$stmt_attempt = $db->prepare('SELECT a.*, p.project_name, p.user_id 
                              FROM attempts a 
                              JOIN user_projects p ON a.project_id = p.project_id 
                              WHERE a.attempt_id = :aid');
$stmt_attempt->execute(['aid' => $attempt_id]);
$attempt = $stmt_attempt->fetch(PDO::FETCH_ASSOC);

if (!$attempt) {
    die("Tentative introuvable.");
}

// Validate access
if ($attempt['is_guest']) {
    if (!$share_token_from_url) {
        die("Accès non autorisé (token manquant pour l'invité).");
    }
    
    $stmt_share = $db->prepare('SELECT share_token FROM project_shares WHERE project_id = :pid AND share_token = :token AND (share_type = \'exam\' OR share_type = \'results\')');
    $stmt_share->execute(['pid' => $attempt['project_id'], 'token' => $share_token_from_url]);
    
    if (!$stmt_share->fetch()) {
        die("Accès non autorisé (token invalide ou ne correspondant pas à cet examen ou ses résultats).");
    }
} else {
    // Check if logged in user is allowed to view results
    if (!isset($_SESSION['user']) || ($_SESSION['user']['email'] !== $attempt['user_id'])) {
        header('Location: /dashboard.php?error=' . urlencode('Accès interdit à ces résultats.'));
        exit;
    }
}

// Fetch blocs for this project
$stmt_blocs = $db->prepare('SELECT * FROM project_blocs WHERE project_id = :pid ORDER BY sequence_number ASC');
$stmt_blocs->execute(['pid' => $attempt['project_id']]);
$blocs = $stmt_blocs->fetchAll(PDO::FETCH_ASSOC);

// Fetch answers for this attempt
$stmt_answers = $db->prepare('
    SELECT aa.*, bp.proposition_text, bp.solution_text, bp.solution_points, bp.bloc_id 
    FROM attempt_answers aa
    JOIN bloc_propositions bp ON aa.proposition_id = bp.proposition_id
    WHERE aa.attempt_id = :aid
');
$stmt_answers->execute(['aid' => $attempt_id]);
$answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

// Group answers by bloc for easier display
$answers_by_bloc = [];
foreach ($answers as $answer) {
    if (!isset($answers_by_bloc[$answer['bloc_id']])) {
        $answers_by_bloc[$answer['bloc_id']] = [];
    }
    $answers_by_bloc[$answer['bloc_id']][] = $answer;
}

// Calculate points for each bloc
$bloc_scores = [];
foreach ($blocs as $bloc) {
    $bloc_id = $bloc['bloc_id'];
    $bloc_score = 0;
    
    if (isset($answers_by_bloc[$bloc_id])) {
        foreach ($answers_by_bloc[$bloc_id] as $answer) {
            $penalty_applied = $answer['penalty_applied'];
            $solution_points = $answer['solution_points'];
            
            if ($penalty_applied === 'dead' || $solution_points === 'dead') {
                $bloc_score = 0;
                break; // Dead answer sets bloc score to 0 and stops calculation
            }
            
            // Add solution points
            if ($solution_points !== null && $solution_points !== 'dead') {
                $bloc_score += (int)$solution_points;
            }
            
            // Subtract penalty (if not dead, which was handled above)
            if ($penalty_applied !== null && $penalty_applied !== 'dead') {
                $bloc_score += (int)$penalty_applied; // Penalties are already negative numbers in the DB
            }
        }
    }
    
    $bloc_scores[$bloc_id] = $bloc_score;
}

?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title>Résultats d'Examen - <?php echo htmlspecialchars($attempt['student_name']); ?></title>
    <?php
        if (function_exists('require_login') || !$attempt['is_guest']) {
            require_once __DIR__ . '/../powertrain/head.php';
        } else {
            echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
            echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@latest/css/pico.min.css">';
        }
    ?>
    <style>
        .bloc-item { border: 1px solid #ccc; padding: 1em; margin-bottom: 1em; border-radius: 8px; }
        .chosen-proposition { 
            margin-bottom: 0.75em; 
            padding: 0.75em; 
            background-color: #d1e7dd; 
            border: 1px solid #a3cfbb; 
            border-radius: 4px; 
        }
        .chosen-proposition.dead { background-color: #f8d7da; border-color: #f5c6cb; }
        .proposition-text { font-weight: bold; }
        .solution-text { margin-top: 0.5em; padding: 0.5em; background-color: #fff; border: 1px dashed #ddd; font-size:0.9em;}
        .penalty-info { color: #c57500; font-size: 0.85em; margin-top: 0.3em;}
        .penalty-info.dead { color: #dc3545; font-weight: bold; }
        .points-badge {
            display: inline-block;
            padding: 0.25em 0.5em;
            border-radius: 0.25em;
            font-weight: bold;
            margin-right: 0.5em;
        }
        .points-positive { background-color: #d1e7dd; color: #0a3622; }
        .points-negative { background-color: #f8d7da; color: #842029; }
        .points-neutral { background-color: #e2e3e5; color: #41464b; }
        .points-dead { background-color: #842029; color: white; }
    </style>
</head>
<body>
<main class="container">
    <header>
        <h1>Résultats d'Examen</h1>
        <p>Projet: <?php echo htmlspecialchars($attempt['project_name']); ?></p>
        <p>Étudiant: <?php echo htmlspecialchars($attempt['student_name']); ?></p>
        <p>Date: <?php echo date('d/m/Y H:i', strtotime($attempt['created_at'])); ?></p>
        <h2>Score total: <?php echo $attempt['result']; ?> points</h2>
    </header>
    <hr>

    <section>
        <h3>Détails par Bloc</h3>
        <?php foreach ($blocs as $bloc): ?>
            <article class="bloc-item">
                <h4>
                    Bloc <?php echo $bloc['sequence_number']; ?>
                    <span class="points-badge <?php 
                        $score = $bloc_scores[$bloc['bloc_id']] ?? 0;
                        if ($score > 0) echo 'points-positive';
                        elseif ($score < 0) echo 'points-negative';
                        else echo 'points-neutral';
                    ?>"><?php echo $score; ?> points</span>
                </h4>
                <div class="problem-text"><?php echo nl2br(htmlspecialchars($bloc['problem_text'])); ?></div>

                <h5>Propositions choisies:</h5>
                <?php if (isset($answers_by_bloc[$bloc['bloc_id']])): ?>
                    <?php foreach ($answers_by_bloc[$bloc['bloc_id']] as $answer): ?>
                        <?php 
                            $isDead = $answer['penalty_applied'] === 'dead' || $answer['solution_points'] === 'dead';
                            $pointClass = '';
                            if ($isDead) {
                                $pointClass = 'points-dead';
                            } elseif ((int)$answer['solution_points'] > 0) {
                                $pointClass = 'points-positive';
                            } elseif ((int)$answer['solution_points'] < 0) {
                                $pointClass = 'points-negative';
                            } else {
                                $pointClass = 'points-neutral';
                            }
                        ?>
                        <div class="chosen-proposition <?php echo $isDead ? 'dead' : ''; ?>">
                            <p class="proposition-text"><?php echo htmlspecialchars($answer['proposition_text']); ?></p>
                            <div class="solution-text">
                                <p>
                                    <span class="points-badge <?php echo $pointClass; ?>">
                                        <?php 
                                            if ($answer['solution_points'] === 'dead') {
                                                echo 'Mortel';
                                            } else {
                                                echo $answer['solution_points'] . ' points';
                                            }
                                        ?>
                                    </span>
                                    <?php echo nl2br(htmlspecialchars($answer['solution_text'])); ?>
                                </p>
                                <?php if ($answer['penalty_applied']): ?>
                                    <p class="penalty-info <?php echo $answer['penalty_applied'] === 'dead' ? 'dead' : ''; ?>">
                                        <strong>Pénalité appliquée:</strong> 
                                        <?php if ($answer['penalty_applied'] === 'dead'): ?>
                                            Mortelle (Fin d'épreuve et score mis à 0)
                                        <?php else: ?>
                                            <?php echo $answer['penalty_applied']; ?> points
                                        <?php endif; ?>
                                        pour choix prématuré.
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>Aucune proposition choisie pour ce bloc.</p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>

    <hr>
    <div style="margin-top: 2em; margin-bottom: 2em;">
        <a href="/dashboard.php" role="button">Retour au Tableau de Bord</a>
        <?php if ($attempt['is_guest'] && $share_token_from_url): ?>
            <a href="/exam.php?share_token=<?php echo urlencode($share_token_from_url); ?>" role="button" class="contrast">Commencer une Nouvelle Tentative</a>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
