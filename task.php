<?php
require_once("core/functions.php");

$tasks_file = __DIR__ . '/data/tasks.json';
$daily_cache_file = __DIR__ . '/data/daily_tasks_cache.json';
$today = date('Y-m-d');

// --- 1. ROUTEUR AJAX CENTRALISÉ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    handle_task_ajax_request($tasks_file, $_POST);
}

// --- 2. GESTION DU CACHE JOURNALIER DES SUGGESTIONS ---
$todays_suggestions = [];
if (file_exists($daily_cache_file)) {
    $cache_data = json_decode(file_get_contents($daily_cache_file), true);
    if (isset($cache_data['date']) && $cache_data['date'] === $today) {
        $todays_suggestions = $cache_data['tasks'] ?? [];
    }
}

if (empty($todays_suggestions) && file_exists($tasks_file)) {
    $all_tasks = json_decode(file_get_contents($tasks_file), true);
    $flat_tasks = [];

    foreach ($all_tasks as $room => $tasks) {
        foreach ($tasks as $tid => $t) {
            $days_elapsed = (time() - $t['last_done']) / 86400;
            $ratio = min(1, max(0, $days_elapsed / $t['freq']));
            $score = 100 * (1 - $ratio);

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
        $easy_tasks = array_filter($flat_tasks, fn($t) => $t['effort'] <= 2);
        $hard_tasks = array_filter($flat_tasks, fn($t) => $t['effort'] >= 4 || $t['score'] < 30);

        usort($easy_tasks, fn($a, $b) => $a['score'] <=> $b['score']);
        usort($hard_tasks, fn($a, $b) => $a['score'] <=> $b['score']);

        if (!empty($easy_tasks)) {
            $todays_suggestions[] = array_shift($easy_tasks);
            if (!empty($easy_tasks)) $todays_suggestions[] = array_shift($easy_tasks);
        }
        if (!empty($hard_tasks)) {
            $todays_suggestions[] = array_shift($hard_tasks);
        } else {
            usort($flat_tasks, fn($a, $b) => $b['effort'] <=> $a['effort']);
            if (!empty($flat_tasks)) $todays_suggestions[] = array_shift($flat_tasks);
        }

        file_put_contents($daily_cache_file, json_encode([
            'date' => $today,
            'tasks' => $todays_suggestions
        ], JSON_PRETTY_PRINT));
    }
}

// --- 3. CHARGEMENT DU HEADER ET DU DOM W3C ---
include("header.php");

// --- 4. MOTEUR DE CALCUL CENTRALISÉ ---
$conf_file = file_exists('conf/home_structure.conf') ? 'conf/home_structure.conf' : 'config/home_structure.conf';
$rooms_conf = file_exists($conf_file) ? parse_ini_file($conf_file, true) : [];

$tasks_data = file_exists($tasks_file) ? json_decode(file_get_contents($tasks_file), true) : [];
$global_score_accum = 0;
$global_effort_accum = 0;
$room_stats = [];

foreach ($rooms_conf as $name => $data) {
    if (in_array($name, ['System', 'Defaults'])) continue;
    
    $room_tasks = $tasks_data[$name] ?? [];
    $r_score_accum = 0;
    $r_effort_accum = 0;

    foreach ($room_tasks as $tid => &$t) {
        $days_elapsed = (time() - $t['last_done']) / 86400;
        $ratio = min(1, max(0, $days_elapsed / $t['freq']));
        $task_score = 100 * (1 - $ratio);
        
        $t['current_score'] = $task_score;

        $r_score_accum += $task_score * $t['effort'];
        $r_effort_accum += $t['effort'];
        
        $global_score_accum += $task_score * $t['effort'];
        $global_effort_accum += $t['effort'];
    }
    unset($t);

    uasort($room_tasks, function($a, $b) {
        if ($a['current_score'] == $b['current_score']) {
            return $a['effort'] <=> $b['effort'];
        }
        return $a['current_score'] <=> $b['current_score'];
    });
    
    $room_stats[$name] = [
        'score' => $r_effort_accum > 0 ? round($r_score_accum / $r_effort_accum) : 100,
        'tasks' => $room_tasks
    ];
}

$gesamt_sauberkeit = $global_effort_accum > 0 ? round($global_score_accum / $global_effort_accum) : 100;

// Fonction de calcul quadricolore A.S.H.E.S.
function get_task_status_color($score) {
    if ($score <= 10) return 'var(--red)';
    if ($score <= 25) return 'var(--orange)';
    if ($score <= 50) return 'var(--yellow)';
    return 'var(--green)';
}

$color_gesamt = get_task_status_color($gesamt_sauberkeit);
?>

<div class="container">
    <div class="room-card task-global-card" style="border-color: <?= $color_gesamt ?>; border-top-color: <?= $color_gesamt ?>;">
        <div class="task-global-header">
            <div class="room-title task-global-title" style="color: <?= $color_gesamt ?>;"><span>🧹</span> GESAMTSAUBERKEIT</div>
            <div class="task-global-score" style="color: <?= $color_gesamt ?>;">
                <?= $gesamt_sauberkeit ?> <small>%</small>
            </div>
            <!-- BARRE GLOBALE : Ajout de critical-alert-border si <= 5% -->
            <div class="task-progress-bg <?= ($gesamt_sauberkeit <= 5) ? 'critical-alert-border' : '' ?>">
                <div class="task-progress-fill" style="width: <?= $gesamt_sauberkeit ?>%; background: <?= $color_gesamt ?>;"></div>
            </div>
        </div>

        <?php if (!empty($todays_suggestions)): ?>
        <div class="task-suggestions-block">
            <span class="task-suggestions-title">🎯 Objectifs du jour :</span>
            <div class="task-suggestions-list">
                <?php foreach ($todays_suggestions as $st): ?>
                    <div class="task-suggestion-item">
                        <span class="task-suggestion-label"><?= htmlspecialchars($st['label']) ?></span>
                        <span class="task-suggestion-room">(<?= htmlspecialchars($st['room']) ?>)</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="sha-main-grid">
    <?php 
    $display_rooms = [];
    $other_rooms = [];

    foreach ($rooms_conf as $name => $data) {
        if (in_array($name, ['System', 'Defaults'])) continue;
        if ($name === 'Haus') {
            $display_rooms['Haus'] = $data;
        } else {
            $other_rooms[$name] = $data;
        }
    }

    uksort($other_rooms, function($a, $b) use ($room_stats) {
        $count_a = count($room_stats[$a]['tasks'] ?? []);
        $count_b = count($room_stats[$b]['tasks'] ?? []);
        return $count_b <=> $count_a;
    });

    $display_rooms += $other_rooms;
    $unit_labels = ['d' => 'j', 'w' => 'sem', 'm' => 'm'];

    foreach ($display_rooms as $name => $data): 
        $stats = $room_stats[$name] ?? ['score' => 100, 'tasks' => []];
        $score = $stats['score'];
        $color_room = get_task_status_color($score);
        $is_wide = ($name === 'Haus') ? 'wide' : '';
    ?>
        <div class="room-card <?= $is_wide ?>">
            <div class="room-head task-room-head">
                <div class="room-title task-title-nowrap">
                    <span><?= $data['icon'] ?? '🏠' ?></span> <?= strtoupper($name) ?>
                </div>
                <!-- BARRE PIÈCE : Ajout de critical-alert-border si <= 5% -->
                <div class="task-progress-bar-wrapper <?= ($score <= 5) ? 'critical-alert-border' : '' ?>">
                    <div class="task-progress-fill" style="width: <?= $score ?>%; background: <?= $color_room ?>;"></div>
                    <span class="task-progress-percent">
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
                        $bar_color = get_task_status_color($task_score);

                        $comment_text = htmlspecialchars($t['comment'] ?? '', ENT_QUOTES);
                        $display_val = $t['freq_value'] ?? $t['freq'];
                        $raw_unit = $t['freq_unit'] ?? 'd';
                        $display_unit = $unit_labels[$raw_unit];
                        
                        $js_label = htmlspecialchars(addslashes($t['label']), ENT_QUOTES);
                        $js_comment = htmlspecialchars(addslashes($t['comment'] ?? ''), ENT_QUOTES);

                        $effort_stars = str_repeat('⭐', max(1, min(5, (int)($t['effort'] ?? 1))));
                    ?>
                        <div class="dev-row task-dev-row">
                            <div class="task-info">
                                <span class="task-label-wrapper">
                                    <span class="task-label-text"><?= htmlspecialchars($t['label']) ?></span>
                                    <?php if (!empty($t['comment'])): ?>
                                        <span class="task-tooltip-wrapper">💬<span class="task-tooltip"><?= $comment_text ?></span></span>
                                    <?php endif; ?>
                                </span>
                                <span style="font-size: 0.75rem; color: #888; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
        <span class="task-stars"><?= $effort_stars ?></span> | 🔄 <?= $display_val . $display_unit ?> | ⌛ <?= $days_elapsed ?>j
    </span>
                            </div>

                            <div class="task-actions task-actions-responsive">
                                <div class="task-buttons-grid">
                                    <button class="toggle-btn btn-off task-btn-responsive" onclick="doneTask('<?= $name ?>', '<?= $tid ?>')" title="Fait !">✓</button>
                                    <button class="toggle-btn task-btn-responsive btn-edit-task" onclick="editTask('<?= $name ?>', '<?= $tid ?>', '<?= $js_label ?>', <?= $t['effort'] ?>, <?= $display_val ?>, '<?= $raw_unit ?>', '<?= $js_comment ?>', <?= $t['last_done'] ?>)" title="Modifier">✏️</button>
                                    <button class="toggle-btn btn-on task-btn-responsive" onclick="deleteTask('<?= $name ?>', '<?= $tid ?>')" title="Supprimer">🗑</button>
                                </div>
                                
                                <!-- BARRE TÂCHE INDIVIDUELLE : Ajout de critical-alert-border si <= 5% -->
                                <div class="task-mini-bar-bg <?= ($task_score <= 5) ? 'critical-alert-border' : '' ?>">
                                    <div class="task-progress-fill" style="width: <?= $task_score ?>%; background: <?= $bar_color ?>;"></div>
                                </div>
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

<!-- MODAL HTML -->
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

            <div class="task-modal-form-group task-modal-mb20">
                <label class="task-modal-label">Commentaire (optionnel) :</label>
                <input type="text" id="modalComment" name="comment" class="task-modal-input">
            </div>
            
            <div class="task-modal-actions">
                <button type="button" class="toggle-btn btn-modal-cancel" onclick="toggleTaskModal(false)">Annuler</button>
                <button type="submit" class="toggle-btn btn-off btn-modal-submit">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<?php include("footer.php"); ?>