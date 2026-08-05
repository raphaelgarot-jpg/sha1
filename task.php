<?php
// 2. task.php (Version complète avec refactoring des classes CSS et icône ⌛)
$tasks_file = __DIR__ . '/data/tasks.json';
$daily_cache_file = __DIR__ . '/data/daily_tasks_cache.json';
$today = date('Y-m-d');

// --- ROUTEUR AJAX INTEGRE (Autonome) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $tasks = file_exists($tasks_file) ? json_decode(file_get_contents($tasks_file), true) : [];
    
    $action = $_POST['action'];
    $room = $_POST['room'] ?? '';
    
    // FIX : Utilisation de !empty() pour contrer la chaîne vide "" envoyée par le formulaire JS
    $id = !empty($_POST['id']) ? $_POST['id'] : uniqid();

    if ($action === 'add' || ($action === 'edit' && isset($tasks[$room][$id]))) {
        $raw_freq = $_POST['freq'] ?? 1;
        $f_val = isset($_POST['freq_value']) ? max(1, (int)$_POST['freq_value']) : max(1, (int)$raw_freq);
        $f_unit = in_array($_POST['freq_unit'] ?? 'd', ['d', 'w', 'm']) ? $_POST['freq_unit'] : 'd';
        
        $f_days = $f_val;
        if ($f_unit === 'w') $f_days *= 7;
        if ($f_unit === 'm') $f_days *= 30;

        $last_done_ts = !empty($_POST['last_done']) ? strtotime($_POST['last_done']) : time();

        if ($action === 'add') {
            $tasks[$room][$id] = [
                'label' => trim($_POST['label']),
                'effort' => max(1, min(5, (int)$_POST['effort'])),
                'freq_value' => $f_val,
                'freq_unit' => $f_unit,
                'freq' => $f_days,
                'comment' => trim($_POST['comment'] ?? ''),
                'last_done' => $last_done_ts
            ];
        } else {
            if (isset($_POST['label'])) $tasks[$room][$id]['label'] = trim($_POST['label']);
            if (isset($_POST['effort'])) $tasks[$room][$id]['effort'] = max(1, min(5, (int)$_POST['effort']));
            if (isset($_POST['comment'])) $tasks[$room][$id]['comment'] = trim($_POST['comment']);
            $tasks[$room][$id]['freq_value'] = $f_val;
            $tasks[$room][$id]['freq_unit'] = $f_unit;
            $tasks[$room][$id]['freq'] = $f_days;
            $tasks[$room][$id]['last_done'] = $last_done_ts;
        }
    } elseif ($action === 'done' && isset($tasks[$room][$id])) {
        $tasks[$room][$id]['last_done'] = time();
    } elseif ($action === 'delete') {
        unset($tasks[$room][$id]);
    }

    file_put_contents($tasks_file, json_encode($tasks, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true]);
    exit;
}



$todays_suggestions = [];

// 1. Gestion du cache journalier pour que la sélection reste la même toute la journée
if (file_exists($daily_cache_file)) {
    $cache_data = json_decode(file_get_contents($daily_cache_file), true);
    if (isset($cache_data['date']) && $cache_data['date'] === $today) {
        $todays_suggestions = $cache_data['tasks'] ?? [];
    }
}

// 2. Si aucun cache pour aujourd'hui, on pioche dans les tâches
if (empty($todays_suggestions) && file_exists($tasks_file)) {
    $all_tasks = json_decode(file_get_contents($tasks_file), true);
    $flat_tasks = [];

    // Aplatissement du tableau multi-dimensionnel (Pièces -> Tâches)
    foreach ($all_tasks as $room => $tasks) {
        foreach ($tasks as $tid => $t) {
            $days_elapsed = (time() - $t['last_done']) / 86400;
            $ratio = min(1, max(0, $days_elapsed / $t['freq']));
            $score = 100 * (1 - $ratio); // 0 = très en retard / sale, 100 = propre

            $flat_tasks[] = [
                'room' => $room,
                'id' => $tid,
                'label' => $t['label'],
                'effort' => (int)$t['effort'],
                'score' => $score,
                'days_elapsed' => $days_elapsed
            ];
        }
    }

    if (!empty($flat_tasks)) {
        // Tâches simples et rapides (effort <= 2)
        $easy_tasks = array_filter($flat_tasks, fn($t) => $t['effort'] <= 2);
        // Tâches difficiles ou qui attendent (effort >= 4 ou score très bas)
        $hard_tasks = array_filter($flat_tasks, fn($t) => $t['effort'] >= 4 || $t['score'] < 30);

        // Tri par score ascendant (les plus urgentes en premier)
        usort($easy_tasks, fn($a, $b) => $a['score'] <=> $b['score']);
        usort($hard_tasks, fn($a, $b) => $a['score'] <=> $b['score']);

        // Sélection : 2 tâches rapides/moyennes + 1 grosse tâche difficile
        if (!empty($easy_tasks)) {
            $todays_suggestions[] = array_shift($easy_tasks);
            if (!empty($easy_tasks)) {
                $todays_suggestions[] = array_shift($easy_tasks);
            }
        }
        if (!empty($hard_tasks)) {
            $todays_suggestions[] = array_shift($hard_tasks);
        } else {
            // Fallback si pas de tâche dure : on prend la plus complexe dispo
            usort($flat_tasks, fn($a, $b) => $b['effort'] <=> $a['effort']);
            if (!empty($flat_tasks)) $todays_suggestions[] = array_shift($flat_tasks);
        }

        // Sauvegarde dans le cache journalier
        file_put_contents($daily_cache_file, json_encode([
            'date' => $today,
            'tasks' => $todays_suggestions
        ], JSON_PRETTY_PRINT));
    }
}


include("header.php");

// --- MOTEUR DE CALCUL DE PROPRETÉ ---
$conf_file = file_exists('conf/home_structure.conf') ? 'conf/home_structure.conf' : 'config/home_structure.conf';
$rooms = file_exists($conf_file) ? parse_ini_file($conf_file, true) : [];

if (!is_array($rooms)) {
    $rooms = [];
}

$tasks_data = file_exists($tasks_file) ? json_decode(file_get_contents($tasks_file), true) : [];
$global_score_accum = 0;
$global_effort_accum = 0;
$room_stats = [];

foreach ($rooms as $name => $data) {
    if (in_array($name, ['System', 'Defaults'])) continue;
    
    $room_tasks = $tasks_data[$name] ?? [];
    $r_score_accum = 0;
    $r_effort_accum = 0;

    // 1. Calcul et injection des scores dans les tâches (Passage par référence avec &$t)
    foreach ($room_tasks as $tid => &$t) {
        $days_elapsed = (time() - $t['last_done']) / 86400;
        $ratio = min(1, max(0, $days_elapsed / $t['freq']));
        $task_score = 100 * (1 - $ratio);
        
        // On stocke le score dynamiquement pour le tri
        $t['current_score'] = $task_score;

        $r_score_accum += $task_score * $t['effort'];
        $r_effort_accum += $t['effort'];
        
        $global_score_accum += $task_score * $t['effort'];
        $global_effort_accum += $t['effort'];
    }
    unset($t); // Destruction de la référence de boucle (sécurité PHP)

    // 2. Tri du tableau $room_tasks
    uasort($room_tasks, function($a, $b) {
        if ($a['current_score'] == $b['current_score']) {
            // En cas d'égalité de score : Tri ascendant sur l'effort (le plus simple en haut)
            return $a['effort'] <=> $b['effort'];
        }
        // Règle principale : Tri ascendant sur le score (le plus sale/faible en haut)
        return $a['current_score'] <=> $b['current_score'];
    });
    
    $room_stats[$name] = [
        'score' => $r_effort_accum > 0 ? round($r_score_accum / $r_effort_accum) : 100,
        'tasks' => $room_tasks
    ];
}

$gesamt_sauberkeit = $global_effort_accum > 0 ? round($global_score_accum / $global_effort_accum) : 100;
$color_gesamt = $gesamt_sauberkeit < 33 ? 'var(--red)' : ($gesamt_sauberkeit < 66 ? 'var(--orange)' : 'var(--green)');
?>

<div class="container">
    <div class="room-card task-global-card" style="border-color: <?= $color_gesamt ?>; border-top-color: <?= $color_gesamt ?>; display: flex; flex-direction: column; gap: 15px;">
    
    <!-- Ligne principale Gesantsauberkeit -->
    <div style="display: flex; flex-direction: column; gap: 10px;">
        <div class="room-title task-global-title" style="color: <?= $color_gesamt ?>;"><span>🧹</span> GESAMTSAUBERKEIT</div>
        <div class="task-global-score" style="color: <?= $color_gesamt ?>;">
            <?= $gesamt_sauberkeit ?> <small style="color: #444;">%</small>
        </div>
        <div class="task-progress-bg">
            <div class="task-progress-fill" style="width: <?= $gesamt_sauberkeit ?>%; background: <?= $color_gesamt ?>;"></div>
        </div>
    </div>

    <!-- Affichage des suggestions dans le bandeau -->
    <?php if (!empty($todays_suggestions)): ?>
    <div style="border-top: 1px solid rgba(255,255,255,0.08); padding-top: 12px; display: flex; flex-direction: column; gap: 8px;">
        <span style="font-size: 0.75rem; color: var(--orange); font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">🎯 Objectifs du jour :</span>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <?php foreach ($todays_suggestions as $st): ?>
                <div style="background: rgba(0,0,0,0.2); border: 1px solid var(--border); padding: 5px 10px; border-radius: var(--radius-sm); font-size: 0.8rem; display: flex; align-items: center; gap: 6px;">
                    <span style="color: #eee; font-weight: bold;"><?= htmlspecialchars($st['label']) ?></span>
                    <span style="font-size: 0.65rem; color: #888;">(<?= htmlspecialchars($st['room']) ?>)</span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

    <div class="sha-main-grid">
    <?php 
    // --- TRI DES PIÈCES ---
    $display_rooms = [];
    $other_rooms = [];

    // 1. Extraction de "Haus" et filtrage
    foreach ($rooms as $name => $data) {
        if (in_array($name, ['System', 'Defaults'])) continue;
        if ($name === 'Haus') {
            $display_rooms['Haus'] = $data;
        } else {
            $other_rooms[$name] = $data;
        }
    }

    // 2. Tri des autres pièces en fonction du nombre de tâches (décroissant)
    uksort($other_rooms, function($a, $b) use ($room_stats) {
        $count_a = count($room_stats[$a]['tasks'] ?? []);
        $count_b = count($room_stats[$b]['tasks'] ?? []);
        
        // Trie du plus grand nombre de tâches vers le plus petit. 
        // (Pour l'ordre inverse, remplace $count_b <=> $count_a par $count_a <=> $count_b)
        return $count_b <=> $count_a;
    });

    // 3. Fusion (l'opérateur + préserve strictement les clés associatives et l'ordre)
    $display_rooms += $other_rooms;

    $unit_labels = ['d' => 'j', 'w' => ' sem', 'm' => ' mois'];

    foreach ($display_rooms as $name => $data): 
        // (La condition if (in_array...) a été retirée ici car déjà gérée plus haut)
        
        $stats = $room_stats[$name] ?? ['score' => 100, 'tasks' => []];
        $score = $stats['score'];
        $color_room = $score < 33 ? 'var(--red)' : ($score < 66 ? 'var(--orange)' : 'var(--green)');
        $is_wide = ($name === 'Haus') ? 'wide' : '';
    ?>
        <div class="room-card <?= $is_wide ?>">
            
            <div class="room-head" style="display: flex; align-items: center; gap: 15px; margin-bottom: 10px;">
                <div class="room-title" style="white-space: nowrap; margin: 0;">
                    <span><?= $data['icon'] ?? '🏠' ?></span> <?= strtoupper($name) ?>
                </div>
                <div style="flex: 1; height: 16px; background: #333; border-radius: 8px; position: relative; overflow: hidden; box-shadow: inset 0 1px 3px rgba(0,0,0,0.5);">
                    <div style="width: <?= $score ?>%; height: 100%; background: <?= $color_room ?>; transition: width 0.3s ease;"></div>
                    <span style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 0.75rem; font-weight: bold; text-shadow: 1px 1px 2px rgba(0,0,0,0.8);">
                        <?= $score ?>%
                    </span>
                </div>
            </div>

            <div class="room-body flex-column task-room-body">
                <?php if (!empty($stats['tasks'])): ?>
                    <?php foreach ($stats['tasks'] as $tid => $t): 
                        $days_elapsed = round((time() - $t['last_done']) / 86400, 1);
                        $ratio = min(1, max(0, $days_elapsed / $t['freq']));
                        $task_score = 100 * (1 - $ratio);
                        $bar_color = $task_score < 33 ? 'var(--red)' : ($task_score < 66 ? 'var(--orange)' : 'var(--green)');

                        $comment_text = htmlspecialchars($t['comment'] ?? '', ENT_QUOTES);
                        $display_val = $t['freq_value'] ?? $t['freq'];
                        $raw_unit = $t['freq_unit'] ?? 'd';
                        $display_unit = $unit_labels[$raw_unit];
                        
                        $js_label = htmlspecialchars(addslashes($t['label']), ENT_QUOTES);
                        $js_comment = htmlspecialchars(addslashes($t['comment'] ?? ''), ENT_QUOTES);
                    ?>
                        <div class="dev-row" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--border); min-height: 50px; width: 100%; box-sizing: border-box;">
                            
                            <div class="task-info" style="display: flex; flex-direction: column; gap: 6px; flex: 1 1 0%; min-width: 0; padding-right: 10px;">
                                <span style="color: #eee; font-weight: bold; font-size: 0.95rem; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                    <span style="word-break: break-word; line-height: 1.2;"><?= htmlspecialchars($t['label']) ?></span>
                                    <?php if (!empty($t['comment'])): ?>
                                        <span class="task-tooltip-wrapper" style="cursor: help;">💬<span class="task-tooltip"><?= $comment_text ?></span></span>
                                    <?php endif; ?>
                                </span>
                                <!-- Remplacement du texte "Fait il y a" par l'icône ⌛ -->
                                <span style="font-size: 0.75rem; color: #888; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    💪: <?= $t['effort'] ?>/5 | 🔄: <?= $display_val . $display_unit ?> | ⌛ <?= $days_elapsed ?>j
                                </span>
                            </div>

                            <!-- Utilisation des nouvelles classes CSS responsives -->
                            <div class="task-actions task-actions-responsive">
                                <div class="task-buttons-grid">
                                    <button class="toggle-btn btn-off task-btn-responsive" onclick="doneTask('<?= $name ?>', '<?= $tid ?>')" title="Fait !">✓</button>
                                    <button class="toggle-btn task-btn-responsive" style="background: #f39c12;" onclick="editTask('<?= $name ?>', '<?= $tid ?>', '<?= $js_label ?>', <?= $t['effort'] ?>, <?= $display_val ?>, '<?= $raw_unit ?>', '<?= $js_comment ?>', <?= $t['last_done'] ?>)" title="Modifier">✏️</button>
                                    <button class="toggle-btn btn-on task-btn-responsive" onclick="deleteTask('<?= $name ?>', '<?= $tid ?>')" title="Supprimer">🗑</button>
                                </div>
                                
                                <div style="width: 100%; height: 4px; background: #333; border-radius: 2px; overflow: hidden; box-sizing: border-box;">
                                    <div style="width: <?= $task_score ?>%; height: 100%; background: <?= $bar_color ?>; transition: width 0.3s ease;"></div>
                                </div>
                            </div>

                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="btn-add-task-container" style="margin-top: 15px;">
                    <button class="toggle-btn btn-add-task" type="button" onclick="promptNewTask('<?= $name ?>')">+ Tâche</button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
</div>

<!-- MODAL HTML (Classes CSS à placer dans assets/css/style.css) -->
<div id="taskModal" class="task-modal-overlay">
    <div class="room-card task-modal-content">
        <div class="room-head task-modal-header">
            <div class="room-title" id="modalTitle">Tâche</div>
        </div>
        <form id="taskForm" onsubmit="submitTaskForm(event)">
            <input type="hidden" id="modalAction" name="action" value="add">
            <input type="hidden" id="modalRoom" name="room" value="">
            <input type="hidden" id="modalId" name="id" value="">
            
            <div class="task-modal-form-group">
                <label class="task-modal-label">Titre :</label>
                <input type="text" id="modalLabel" name="label" required class="task-modal-input">
            </div>
            
            <div class="task-modal-row">
                <div class="task-modal-col">
                    <label class="task-modal-label">Effort (1-5) :</label>
                    <input type="number" id="modalEffort" name="effort" min="1" max="5" value="1" required class="task-modal-input">
                </div>
                <div class="task-modal-col-2">
                    <label class="task-modal-label">Fréquence :</label>
                    <div class="task-modal-input-group">
                        <input type="number" id="modalFreqVal" name="freq_value" min="1" value="1" required class="task-modal-input-half">
                        <select id="modalFreqUnit" name="freq_unit" class="task-modal-input-half">
                            <option value="d">Jours</option>
                            <option value="w">Semaines</option>
                            <option value="m">Mois</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="task-modal-form-group">
                <label class="task-modal-label">Date du dernier changement :</label>
                <input type="datetime-local" id="modalLastDone" name="last_done" required class="task-modal-input task-modal-input-datetime">
            </div>

            <div class="task-modal-form-group" style="margin-bottom:20px;">
                <label class="task-modal-label">Commentaire (optionnel) :</label>
                <input type="text" id="modalComment" name="comment" class="task-modal-input">
            </div>
            
            <div class="task-modal-actions">
                <button type="button" class="toggle-btn" style="background:#555;" onclick="toggleTaskModal(false)">Annuler</button>
                <button type="submit" class="toggle-btn btn-off" style="padding: 0 20px;">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<?php include("footer.php"); ?>