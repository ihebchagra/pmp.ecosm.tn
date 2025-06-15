<?php
// Current Date and Time (UTC): 2025-06-15 09:53:29
// Current User: ihebchagra
require_once __DIR__ . '/../powertrain/db.php';
require_once __DIR__ . '/../powertrain/auth.php';

require_login();
$user = $_SESSION['user'];
$db = get_db();

$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$project_data = ['project_name' => '', 'blocs' => []];

if ($project_id > 0) {
    // Fetch existing project
    $stmt = $db->prepare("SELECT * FROM user_projects WHERE project_id = :pid AND user_id = :uid");
    $stmt->execute(['pid' => $project_id, 'uid' => $user['email']]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        die("Projet non trouvé ou accès non autorisé.");
    }
    $project_data['project_name'] = $project['project_name'];
    $project_data['project_id'] = $project['project_id'];

    // Fetch blocs for the project
    $stmt_blocs = $db->prepare("SELECT * FROM project_blocs WHERE project_id = :pid ORDER BY sequence_number ASC");
    $stmt_blocs->execute(['pid' => $project_id]);
    $blocs = $stmt_blocs->fetchAll(PDO::FETCH_ASSOC);

    foreach ($blocs as $bloc) {
        $bloc_data = [
            'bloc_id' => $bloc['bloc_id'],
            'problem_text' => $bloc['problem_text'],
            'time_limit_seconds' => $bloc['time_limit_seconds'],
            'sequence_number' => $bloc['sequence_number'],
            'propositions' => [],
            'images' => []
        ];

        // Fetch propositions for this bloc
        $stmt_props = $db->prepare("
            SELECT p.*, 
                   prec.proposition_text as precedent_text,
                   prec.proposition_id as precedent_id
            FROM bloc_propositions p
            LEFT JOIN bloc_propositions prec ON p.precedent_proposition_for_penalty_id = prec.proposition_id
            WHERE p.bloc_id = :bid 
            ORDER BY p.proposition_id ASC");
        $stmt_props->execute(['bid' => $bloc['bloc_id']]);
        $propositions = $stmt_props->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($propositions as $prop) {
            $bloc_data['propositions'][] = [
                'proposition_id' => $prop['proposition_id'],
                'proposition_text' => $prop['proposition_text'],
                'solution_text' => $prop['solution_text'],
                'solution_points' => $prop['solution_points'],
                'precedent_proposition_for_penalty_id' => $prop['precedent_proposition_for_penalty_id'],
                'precedent_text' => $prop['precedent_text'], // Added text of precedent proposition
                'precedent_id' => $prop['precedent_id'],     // Added ID of precedent proposition
                'modify_precedent' => false, // Flag to show modify UI
                'penalty_value_if_chosen_early' => $prop['penalty_value_if_chosen_early']
            ];
        }

        // Fetch images for this bloc
        $stmt_images = $db->prepare("SELECT * FROM bloc_images WHERE bloc_id = :bid AND is_deleted = FALSE ORDER BY image_id ASC");
        $stmt_images->execute(['bid' => $bloc['bloc_id']]);
        $images = $stmt_images->fetchAll(PDO::FETCH_ASSOC);
        foreach ($images as $img) {
            $bloc_data['images'][] = [
                'image_id' => $img['image_id'],
                'image_path' => $img['image_path']
            ];
        }
        
        $project_data['blocs'][] = $bloc_data;
    }
}

$solution_points_options = [
    ['value' => '2', 'label' => 'Choix essentiel: +2 points'],
    ['value' => '1', 'label' => 'Choix utile: +1 point'],
    ['value' => '0', 'label' => 'Choix indifférent : +0 points'],
    ['value' => '-1', 'label' => 'Choix non dangereux mais inefficace : -1 point'],
    ['value' => '-2', 'label' => 'Choix dangereux ou inutilement coûteux : -2 points'],
    ['value' => 'dead', 'label' => 'Finir l\'épreuve']
];
$penalty_options = [
    ['value' => '', 'label' => 'Pas de sanction'],
    ['value' => 'dead', 'label' => 'Finir l\'épreuve'],
    ['value' => '-2', 'label' => '-2 points'],
    ['value' => '-1', 'label' => '-1 point']
];

?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $project_id > 0 ? 'Modifier' : 'Créer'; ?> un Projet</title>
    <?php require_once __DIR__ . '/../powertrain/head.php'; ?>
    <style>
        .proposition { border: 1px dashed #ddd; padding: 1em; margin-top: 1em; margin-bottom:1em; background-color: #fff; }
        label { font-weight: bold; margin-top: 0.5em; display: block; }
        textarea, input[type="text"], input[type="number"], select { width: 100%; margin-bottom: 0.5em; }
        .delete-btn { background-color: #e53935; color:white; }
        .add-btn { background-color: #43a047; color: white; }
        .image-preview { max-width: 100px; max-height: 100px; margin: 5px; }
        .precedent-info {
            background-color: #f0f0f0;
            padding: 8px;
            border-radius: 4px;
            margin-bottom: 8px;
        }
    </style>
</head>
<body x-data="projectEditor(<?php echo htmlspecialchars(json_encode($project_data)); ?>)">
    <nav id="navbar">
        <ul>
            <li><a href="/dashboard.php">Retour à l'Accueil</a></li>
        </ul>
    </nav>
    <main class="container">
        <h1><?php echo $project_id > 0 ? 'Modifier' : 'Créer'; ?> un Projet</h1>
        <p x-text="project.project_name || 'Nouveau Projet'"></p>

        <form method="POST" action="save-project.php" enctype="multipart/form-data">
            <?php if ($project_id > 0): ?>
                <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
            <?php endif; ?>

            <label for="project_name">Nom du Projet:</label>
            <input type="text" id="project_name" name="project_name" x-model="project.project_name" required>

            <h3>Blocs d'Énoncé</h3>
            <div id="blocsContainer">
                <template x-for="(bloc, blocIndex) in project.blocs" :key="bloc.bloc_id || blocIndex">
                    <article class="bloc">
                        <input type="hidden" :name="`blocs[${blocIndex}][bloc_id]`" x-model="bloc.bloc_id">
                        
                        <div style="display: flex; justify-content: space-between; margin-bottom: 1em;">
                            <h4>
                                Bloc <span x-text="blocIndex + 1"></span>
                            </h4>
                            <button type="button" class="delete-btn" @click="removeBloc(blocIndex)">Supprimer Bloc</button>
                        </div>
                        
                        <label>Texte de l'énoncé:</label>
                        <textarea :name="`blocs[${blocIndex}][problem_text]`" x-model="bloc.problem_text" rows="3" required></textarea>
                        <label>Temps limite (secondes):</label>
                        <input type="number" :name="`blocs[${blocIndex}][time_limit_seconds]`" x-model.number="bloc.time_limit_seconds" min="0">

                        <h5>Images</h5>
                        <div>
                            <!-- Existing Images -->
                            <template x-for="(image, imgIndex) in bloc.images" :key="image.image_id">
                                <div style="display: inline-block; margin: 5px; position: relative;">
                                    <img :src="'/' + image.image_path" class="image-preview">
                                    <input type="hidden" :name="`blocs[${blocIndex}][existing_images][${imgIndex}][image_id]`" :value="image.image_id">
                                    <input type="hidden" :name="`blocs[${blocIndex}][existing_images][${imgIndex}][image_path]`" :value="image.image_path">
                                    <div>
                                        <input type="checkbox" :id="`delete_img_${blocIndex}_${imgIndex}`" :name="`blocs[${blocIndex}][existing_images][${imgIndex}][delete]`" value="1">
                                        <label :for="`delete_img_${blocIndex}_${imgIndex}`" style="display: inline;">Supprimer</label>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div style="margin-top: 1em;">
                            <label>Ajouter nouvelles images:</label>
                            <input type="file" :name="`bloc_new_images_${blocIndex}[]`" multiple accept="image/*">
                        </div>

                        <h5 style="margin-top: 1em;">Propositions</h5>
                        <template x-for="(prop, propIndex) in bloc.propositions" :key="prop.proposition_id || propIndex">
                            <section class="proposition">
                                <input type="hidden" :name="`blocs[${blocIndex}][propositions][${propIndex}][proposition_id]`" x-model="prop.proposition_id">
                                
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5em;">
                                    <h6>
                                        Proposition <span x-text="propIndex + 1"></span>
                                    </h6>
                                    <button type="button" class="delete-btn" @click="removeProposition(blocIndex, propIndex)">Supprimer Proposition</button>
                                </div>

                                <label>Texte de la proposition:</label>
                                <textarea :name="`blocs[${blocIndex}][propositions][${propIndex}][proposition_text]`" x-model="prop.proposition_text" rows="2" required></textarea>
                                <label>Texte de la solution:</label>
                                <textarea :name="`blocs[${blocIndex}][propositions][${propIndex}][solution_text]`" x-model="prop.solution_text" rows="2" required></textarea>
                                <label>Points:</label>
                                <select :name="`blocs[${blocIndex}][propositions][${propIndex}][solution_points]`" x-model="prop.solution_points" required>
                                    <?php foreach ($solution_points_options as $option): ?>
                                    <option value="<?php echo $option['value']; ?>"><?php echo htmlspecialchars($option['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <!-- NEW APPROACH: Show precedent as text with modify option -->
                                <label>Proposition précédente pour pénalité:</label>
                                <div>
                                    <!-- Static display mode -->
                                    <div x-show="!prop.modify_precedent" class="precedent-info">
                                        <span x-show="prop.precedent_text">
                                            <strong>Actuellement:</strong> <span x-text="prop.precedent_text"></span>
                                            <input type="hidden" :name="`blocs[${blocIndex}][propositions][${propIndex}][precedent_proposition_for_penalty_id]`" :value="prop.precedent_proposition_for_penalty_id">
                                        </span>
                                        <span x-show="!prop.precedent_text">
                                            <strong>Actuellement:</strong> Aucune proposition précédente
                                            <input type="hidden" :name="`blocs[${blocIndex}][propositions][${propIndex}][precedent_proposition_for_penalty_id]`" value="">
                                        </span>
                                        <button type="button" @click="prop.modify_precedent = true" class="secondary" style="margin-left: 1em; padding: 0.2em 0.5em;">Modifier</button>
                                    </div>
                                    
                                    <!-- Edit mode -->
                                    <div x-show="prop.modify_precedent">
                                        <select :name="`blocs[${blocIndex}][propositions][${propIndex}][precedent_proposition_for_penalty_id]`" x-model="prop.precedent_proposition_for_penalty_id">
                                            <option value="">Aucune</option>
                                            <template x-for="otherProp in bloc.propositions.filter(p => p !== prop)" :key="otherProp.proposition_id || 'new_' + propIndex">
                                                <option :value="otherProp.proposition_id" 
                                                        x-text="`Prop ${bloc.propositions.indexOf(otherProp) + 1}${otherProp.proposition_id ? ' (ID: ' + otherProp.proposition_id + ')' : ''} - ${otherProp.proposition_text ? otherProp.proposition_text.substring(0,20) + '...' : 'Nouvelle proposition'}`"></option>
                                            </template>
                                        </select>
                                        <button type="button" @click="prop.modify_precedent = false" class="secondary" style="padding: 0.2em 0.5em;">Annuler</button>
                                    </div>
                                </div>

                                <label>Pénalité si choisie avant:</label>
                                <select :name="`blocs[${blocIndex}][propositions][${propIndex}][penalty_value_if_chosen_early]`" x-model="prop.penalty_value_if_chosen_early">
                                    <?php foreach ($penalty_options as $opt): ?>
                                    <option value="<?php echo $opt['value']; ?>"><?php echo htmlspecialchars($opt['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </section>
                        </template>
                        <button type="button" class="add-btn" @click="addProposition(blocIndex)">Ajouter Proposition</button>
                    </article>
                </template>
            </div>
            <button type="button" class="add-btn" style="margin-top:1em;" @click="addBloc">Ajouter Bloc</button>
            <hr>
            <button type="submit" class="contrast">Sauvegarder Projet</button>
            <a href="/dashboard.php" role="button" class="secondary">Annuler</a>
        </form>
    </main>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('projectEditor', (initialData) => ({
        project: {
            project_name: initialData.project_name || '',
            project_id: initialData.project_id || null,
            blocs: initialData.blocs || []
        },
        
        init() {
            // Convert null penalty values to empty string for the selects
            this.project.blocs.forEach(bloc => {
                bloc.propositions.forEach(prop => {
                    if (prop.penalty_value_if_chosen_early === null) {
                        prop.penalty_value_if_chosen_early = '';
                    }
                    // Add modify_precedent flag if not already present
                    if (typeof prop.modify_precedent === 'undefined') {
                        prop.modify_precedent = false;
                    }
                });
            });
            
            console.log("Editor initialized with data:", this.project);
        },
        
        addBloc() {
            this.project.blocs.push({
                bloc_id: null,
                problem_text: '',
                time_limit_seconds: null,
                propositions: [],
                images: []
            });
        },
        
        removeBloc(index) {
            if (confirm('Êtes-vous sûr de vouloir supprimer ce bloc?')) {
                this.project.blocs.splice(index, 1);
            }
        },
        
        addProposition(blocIndex) {
            const bloc = this.project.blocs[blocIndex];
            bloc.propositions.push({
                proposition_id: null,
                proposition_text: '',
                solution_text: '',
                solution_points: '0',
                precedent_proposition_for_penalty_id: '',
                precedent_text: null,
                modify_precedent: true, // New propositions start in edit mode
                penalty_value_if_chosen_early: ''
            });
        },
        
        removeProposition(blocIndex, propIndex) {
            if (confirm('Êtes-vous sûr de vouloir supprimer cette proposition?')) {
                this.project.blocs[blocIndex].propositions.splice(propIndex, 1);
            }
        }
    }));
});
</script>
</body>
</html>
