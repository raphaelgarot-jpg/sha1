<?php
// task.php
$tasks_file = __DIR__ . '/data/tasks.json';

// --- ROUTEUR AJAX INTEGRE (Autonome) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $tasks = file_exists($tasks_file) ? json_decode(file_get_contents($tasks_file), true) : [];
    
    $action = $_POST['action'];
    $room = $_POST['room'] ?? '';
    $id = $_POST['id'] ?? uniqid();

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

// Inclusion de l'interface principale (qui charge core/functions.php et le CSS)
include("header.php");

// --- MOTEUR DE CALCUL DE PROPRETÉ ---
// Auto-détection du dossier pour éviter l'erreur fatale de chemin
$conf_file = file_exists('conf/home_structure.conf') ? 'conf/home_structure.conf' : 'config/home_structure.conf';
$rooms = file_exists($conf_file) ? parse_ini_file($conf_file, true) : [];

// Sécurité de type : si parse_ini_file échoue (ex: erreur de syntaxe), on force un tableau vide
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

    foreach ($room_tasks as $tid => $t) {
        $days_elapsed = (time() - $t['last_done']) / 86400;
        $ratio = min(1, max(0, $days_elapsed / $t['freq']));
        $task_score = 100 * (1 - $ratio);

        $r_score_accum += $task_score * $t['effort'];
        $r_effort_accum += $t['effort'];
        
        $global_score_accum += $task_score * $t['effort'];
        $global_effort_accum += $t['effort'];
    }
    
    $room_stats[$name] = [
        'score' => $r_effort_accum > 0 ? round($r_score_accum / $r_effort_accum) : 100,
        'tasks' => $room_tasks
    ];
}

$gesamt_sauberkeit = $global_effort_accum > 0 ? round($global_score_accum / $global_effort_accum) : 100;
$color_gesamt = $gesamt_sauberkeit < 50 ? 'var(--red)' : ($gesamt_sauberkeit < 80 ? 'var(--orange)' : 'var(--green)');
?>

<div class="container">
    <div class="room-card task-global-card" style="border-color: <?= $color_gesamt ?>; border-top-color: <?= $color_gesamt ?>;">
        <div class="room-title task-global-title" style="color: <?= $color_gesamt ?>;"><span>🧹</span> GESAMTSAUBERKEIT</div>
        <div class="task-global-score" style="color: <?= $color_gesamt ?>;">
            <?= $gesamt_sauberkeit ?> <small style="color: #444;">%</small>
        </div>
        <div class="task-progress-bg">
            <div class="task-progress-fill" style="width: <?= $gesamt_sauberkeit ?>%; background: <?= $color_gesamt ?>;"></div>
        </div>
    </div>

    <div class="sha-main-grid">
        <?php 
        $display_rooms = [];
        if (isset($rooms['Haus'])) $display_rooms['Haus'] = $rooms['Haus'];
        foreach ($rooms as $n => $d) { if ($n !== 'Haus') $display_rooms[$n] = $d; }

        $unit_labels = ['d' => 'j', 'w' => ' sem', 'm' => ' mois'];

        foreach ($display_rooms as $name => $data): 
            if (in_array($name, ['System', 'Defaults'])) continue;
            
            $stats = $room_stats[$name] ?? ['score' => 100, 'tasks' => []];
            $score = $stats['score'];
            $color_room = $score < 50 ? 'var(--red)' : ($score < 80 ? 'var(--orange)' : 'var(--green)');
            $is_wide = ($name === 'Haus') ? 'wide' : '';
        ?>
            <div class="room-card <?= $is_wide ?>">
                <div class="room-head">
                    <div class="room-title"><span><?= $data['icon'] ?? '🏠' ?></span> <?= strtoupper($name) ?></div>
                    <span class="badge task-badge" style="background: <?= $color_room ?>;"><?= $score ?>%</span>
                </div>
                
                <div class="room-body flex-column task-room-body">
                    <?php if (!empty($stats['tasks'])): ?>
                        <?php foreach ($stats['tasks'] as $tid => $t): 
                            $days_elapsed = round((time() - $t['last_done']) / 86400, 1);
                            $is_late = $days_elapsed >= $t['freq'];
                            $border_color = $is_late ? 'var(--red)' : 'var(--border)';
                            $comment_text = htmlspecialchars($t['comment'] ?? '', ENT_QUOTES);

                            $display_val = $t['freq_value'] ?? $t['freq'];
                            $raw_unit = $t['freq_unit'] ?? 'd';
                            $display_unit = $unit_labels[$raw_unit];
                            
                            $js_label = htmlspecialchars(addslashes($t['label']), ENT_QUOTES);
                            $js_comment = htmlspecialchars(addslashes($t['comment'] ?? ''), ENT_QUOTES);
                        ?>
                            <div class="dev-row <?= $is_late ? 'offline' : '' ?>" style="border-left: 3px solid <?= $border_color ?>;">
                                <span class="dev-name task-tooltip-wrapper" style="flex-direction: row; align-items: baseline; gap: 8px; flex-wrap: wrap;" onclick="this.classList.toggle('active')">
                                    <span style="color: #eee; font-weight: bold; font-size: 0.9rem;">
                                        <?= htmlspecialchars($t['label']) ?>
                                        <?= !empty($t['comment']) ? ' 💬' : '' ?>
                                    </span>
                                    <span style="font-size: 0.65rem; color: #888;">
                                        (💪: <?= $t['effort'] ?>/5 | 🔄: <?= $display_val . $display_unit ?> | Fait il y a <?= $days_elapsed ?>j)
                                    </span>
                                    
                                    <?php if (!empty($t['comment'])): ?>
                                        <span class="task-tooltip"><?= $comment_text ?></span>
                                    <?php endif; ?>
                                </span>
                                <div style="display: flex; gap: 8px;">
                                    <button class="toggle-btn btn-off" onclick="doneTask('<?= $name ?>', '<?= $tid ?>')" title="Fait !">✓</button>
                                    <button class="toggle-btn" style="background: #f39c12;" onclick="editTask('<?= $name ?>', '<?= $tid ?>', '<?= $js_label ?>', <?= $t['effort'] ?>, <?= $display_val ?>, '<?= $raw_unit ?>', '<?= $js_comment ?>', <?= $t['last_done'] ?>)" title="Modifier">✏️</button>
                                    <button class="toggle-btn btn-on" onclick="deleteTask('<?= $name ?>', '<?= $tid ?>')" title="Supprimer">🗑</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="btn-add-task-container">
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