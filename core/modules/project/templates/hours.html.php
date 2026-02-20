<?php
$tbg_response->addBreadcrumb(__('Hours'), make_url('project_hours', array('project_key' => $selected_project->getKey())));
$tbg_response->setTitle(__('"%project_name" hours', array('%project_name' => $selected_project->getName())));
// Highcharts bundle (your site-local build)
$tbg_response->addJavascript('http://acu.portlandbolt.com/js/pb-highcharts.js');

include_component('project/projectheader', array('selected_project' => $selected_project, 'subpage' => __('Hours')));
?>
<?php
// -------------------- INPUTS --------------------
$series = $series ?? [];
$users  = $users ?? [];
$issues = $issues ?? [];
$from   = $fromStr ?? null;  // 'YYYY-MM-DD'
$to     = $toStr   ?? null;  // 'YYYY-MM-DD'
$rowsWithDate = $rowsWithDate ?? [];   // [['uid','iid','date','hours'], ...]
$totalsByDay  = $totalsByDay  ?? [];   // ['YYYY-MM-DD' => hours]

// -------------------- LABEL MAPS --------------------
$userNames = [];
foreach ($users as $uid => $userObj) {
    $userNames[(int)$uid] = htmlspecialchars($userObj->getName(), ENT_QUOTES, 'UTF-8');
}
$issueLabels = [];
foreach ($issues as $iid => $issueObj) {
    $num   = method_exists($issueObj, 'getFormattedIssueNo') ? $issueObj->getFormattedIssueNo() : ('#' . (int)$iid);
    $title = method_exists($issueObj, 'getTitle') ? $issueObj->getTitle() : '';
    $issueLabels[(int)$iid] = htmlspecialchars(trim($num . ' ' . $title), ENT_QUOTES, 'UTF-8');
}

// -------------------- TOTALS (for User mode) --------------------
$totalsByUser  = [];
$totalsByIssue = [];
foreach ($series as $uid => $byIssue) {
    $uid = (int)$uid;
    foreach ($byIssue as $iid => $h) {
        $iid = (int)$iid;
        $val = (float)$h;
        $totalsByUser[$uid]  = ($totalsByUser[$uid]  ?? 0.0) + $val;
        $totalsByIssue[$iid] = ($totalsByIssue[$iid] ?? 0.0) + $val;
    }
}

$labelsUser = [];
$valuesUser = [];
$userIdByLabel = [];
foreach ($totalsByUser as $uid => $h) {
    $label = $userNames[$uid] ?? ('User #' . (int)$uid);
    $labelsUser[] = $label;
    $valuesUser[] = round((float)$h, 2);
    $userIdByLabel[$label] = (int)$uid;
}

// -------------------- ISSUE STACK: users stacked per issue --------------------
$issueCategories = [];
foreach ($totalsByIssue as $iid => $h) {
    $issueCategories[] = $issueLabels[$iid] ?? ('#' . (int)$iid);
}
// To map label back to id for click
$issueIdByLabel = [];
foreach ($totalsByIssue as $iid => $h) {
    $issueIdByLabel[$issueLabels[$iid] ?? ('#' . (int)$iid)] = (int)$iid;
}

// Build matrix: for each user, hours for each issue category
$issueStackSeries = []; // [{name: uname, data:[...]}]
foreach ($userNames as $uid => $uname) {
    $row = [];
    foreach ($totalsByIssue as $iid => $h) {
        $row[] = isset($series[$uid][$iid]) ? round((float)$series[$uid][$iid], 2) : 0.0;
    }
    $issueStackSeries[] = ['name' => $uname, 'uid' => (int)$uid, 'data' => $row];
}

// -------------------- DAY STACK: users stacked per day (fill empty days) --------------------
// Build an inclusive date range using from/to if provided; else derive from rowsWithDate keys
$dayKeys = [];
if (!empty($from) && !empty($to)) {
    $start = strtotime($from);
    $end   = strtotime($to);
    if ($start !== false && $end !== false && $end >= $start) {
        for ($ts = $start; $ts <= $end; $ts += 86400) {
            $dayKeys[] = gmdate('Y-m-d', $ts);
        }
    }
}
if (empty($dayKeys)) {
    // Fallback: keys from existing totalsByDay or rowsWithDate
    $tmp = [];
    if (!empty($totalsByDay)) { foreach ($totalsByDay as $d => $h) $tmp[$d] = true; }
    if (!empty($rowsWithDate)) { foreach ($rowsWithDate as $r) { if (!empty($r['date'])) $tmp[$r['date']] = true; } }
    $dayKeys = array_keys($tmp);
    sort($dayKeys);
}

// Labels using PHP date format D n/j (e.g., "Thu 2/19")
$labelsDay = [];
foreach ($dayKeys as $d) {
    // Use gmdate to stay UTC-consistent; switch to date('D n/j', ...) for server-local time
    $label = gmdate('D n/j', strtotime($d));
    $labelsDay[] = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
}

// Build hours[uid][day] initialized to 0
$hoursByDayUser = [];
foreach ($userNames as $uid => $uname) {
    foreach ($dayKeys as $d) {
        $hoursByDayUser[$uid][$d] = 0.0;
    }
}
// Fill from rowsWithDate (preferred) else from totalsByDay split cannot per user -> remains zero per user
if (!empty($rowsWithDate)) {
    foreach ($rowsWithDate as $r) {
        $uid = (int)($r['uid'] ?? 0);
        $iid = (int)($r['iid'] ?? 0); // not used here
        $d   = (string)($r['date'] ?? '');
        $h   = (float)($r['hours'] ?? 0);
        if ($d !== '' && isset($hoursByDayUser[$uid]) && array_key_exists($d, $hoursByDayUser[$uid])) {
            $hoursByDayUser[$uid][$d] += $h;
        }
    }
}

$dayStackSeries = [];
foreach ($userNames as $uid => $uname) {
    $row = [];
    foreach ($dayKeys as $d) { $row[] = round((float)$hoursByDayUser[$uid][$d], 2); }
    $dayStackSeries[] = ['name' => $uname, 'uid' => (int)$uid, 'data' => $row];
}

// -------------------- Matrix rows (add Status badge) --------------------
$matrixRows = [];
if (!empty($rowsWithDate) && is_array($rowsWithDate)) {
    foreach ($rowsWithDate as $row) {
        $uid  = (int)($row['uid'] ?? 0);
        $iid  = (int)($row['iid'] ?? 0);
        $date = isset($row['date']) ? DateTime::createFromFormat('Y-m-d', $row['date']) : '';
        $h    = round((float)($row['hours'] ?? 0), 2);
        $matrixRows[] = [
            'uid' => $uid,
            'user' => $userNames[$uid] ?? ('User #' . $uid),
            'iid' => $iid,
            'issue_label' => $issueLabels[$iid] ?? ('#' . $iid),
            'issue' => $issues[$iid] ?? null,
            'date' => $date ? $date->format('D n/j') : '',
            'date_iso' => $date ? $date->format('Y-m-d') : '',
            'hours' => $h,
        ];
    }
} else {
    foreach ($series as $uid => $byIssue) {
        $uid = (int)$uid; $uname = $userNames[$uid] ?? ('User #' . $uid);
        foreach ($byIssue as $iid => $h) {
            $iid = (int)$iid;
            $matrixRows[] = [
                'uid' => $uid,
                'user' => $uname,
                'iid' => $iid,
                'issue_label' => $issueLabels[$iid] ?? ('#' . $iid),
                'issue' => $issues[$iid] ?? null,
                'date' => '',
                'date_iso' => '',
                'hours' => round((float)$h, 2),
            ];
        }
    }
}

$rangeText = '';
if ($from && $to)      $rangeText = htmlspecialchars("$from → $to", ENT_QUOTES, 'UTF-8');
elseif ($from && !$to) $rangeText = htmlspecialchars($from, ENT_QUOTES, 'UTF-8');
elseif (!$from && $to) $rangeText = htmlspecialchars($to,   ENT_QUOTES, 'UTF-8');
?>

<style>
    .tbg-hours-widget { padding: 10px 12px; }
    .tbg-hours-header { display:flex; flex-wrap:wrap; align-items:baseline; gap:8px; margin-bottom:8px; }
    .tbg-hours-widget h3 { margin:0 8px 0 0; font-size:16px; line-height:1.2; }
    .tbg-hours-range { color:#666; font-size:12px; }
    .tbg-hours-actions { margin-left:auto; display:flex; gap:6px; }
    .tbg-btn { appearance:none; border:1px solid #bbb; background:#fafafa; color:#222; padding:4px 8px; border-radius:4px; cursor:pointer; font-size:12px; }
    .tbg-btn.active { border-color:#2f6fdd; color:#fff; background:#2f6fdd; }
    #hours-chart-container { width:100%; min-height:360px; }
    .legend { font-size:12px; color:#555; margin:6px 0 10px 0; }
    table.tbg-hours-table { width:100%; border-collapse:collapse; margin-top:8px; }
    table.tbg-hours-table th, table.tbg-hours-table td { border-bottom:1px solid #eee; padding:6px 8px; text-align:left; font-size:12px; }
    table.tbg-hours-table th { background:#f8f8f8; font-weight:600; }
    .muted { color:#777; }
    .filter-chip { margin-left:8px; font-size:12px; color:#2f6fdd; }
    /* Status badge matches your examples */
    .status_badge { display:inline-block; width:12px; height:12px; border-radius:3px; vertical-align:middle; }
    .issue_closed { text-decoration: line-through; }
    .issue_estimate {
        display: inline-block;
        padding: 1px 5px 2px 5px;
        border-radius: 2px;
        background: rgba(50, 50, 50, 0.8);
        color: #FFF;
        font-size: 0.85em;
        border: 1px solid rgba(100, 100, 100, 0.3);
        vertical-align: middle;
        line-height: 1.25em;
        text-shadow: none;
        margin: 1px 1px 0;
    }
</style>
<div id="project_roadmap_page" class="<?php if ($mode == 'upcoming') echo 'upcoming'; ?> project_info_container">
    <div class="project_left_container" style="flex:0 0 300px;"><div class="project_left"></div></div>
    <div class="project_right_container" id="project_planning">
        <div class="project_right" id="project_roadmap_container">

            <div class="tbg-hours-widget">
                <div class="tbg-hours-header">
                    <h3><?php echo __('Hours by user, issue & day'); ?></h3>
                    <?php if ($rangeText): ?><span class="tbg-hours-range"><?php echo $rangeText; ?></span><?php endif; ?>
                    <form id="hbu-form" style="margin-left:auto; display:flex; gap:6px; align-items:center;">
                        <label>From</label><input type="date" name="from" value="<?php echo $fromStr; ?>" required />
                        <label>To</label><input type="date" name="to" value="<?php echo $toStr; ?>" required />
                        <!-- keep users field (hidden) only if you still want manual filtering; not used for client-side now -->
                        <input id="users-field" type="text" name="users" value="" style="display:none;" />
                        <button type="submit">Load</button>
                    </form>
                </div>

                <div class="tbg-hours-actions">
                    <button type="button" class="tbg-btn active" data-mode="user"><?php echo __('Group by user'); ?></button>
                    <button type="button" class="tbg-btn" data-mode="issue"><?php echo __('Group by issue'); ?></button>
                    <button type="button" class="tbg-btn" data-mode="day"><?php echo __('Group by day'); ?></button>
                    <button type="button" class="tbg-btn" id="clear-filter" title="Clear active filter" style="display:none;">✕ <?php echo __('Clear filter'); ?></button>
                    <span id="active-filter-chip" class="filter-chip" style="display:none;"></span>
                </div>

                <div id="hours-chart-container" aria-label="<?php echo __('Hours chart'); ?>"></div>

                <div class="legend muted">
                    <?php echo __('Click a bar (or stacked segment) to filter the chart and table to that segment.'); ?>
                    <?php if (empty($dayKeys)) { echo ' ' . __('(Day grouping needs date range or rowsWithDate.)'); } ?>
                </div>

                <table class="tbg-hours-table" aria-label="<?php echo __('Detailed hours'); ?>">
                    <thead>
                    <tr>
                        <th><?php echo __('User'); ?></th>
                        <th><?php echo __('Issue'); ?></th>
                        <th style="text-align:center"><?php echo __('Status'); ?></th>
                        <th style="text-align:center"><?php echo __('Date'); ?></th>
                        <th style="width: 110px; text-align: right;"><?php echo __('Hours'); ?></th>
                    </tr>
                    </thead>
                    <tbody id="hours-tbody">
                    <?php if (!empty($matrixRows)): ?>
                        <?php foreach ($matrixRows as $row): ?>
                            <?php
                            $iid = (int)$row['iid'];
                            $issueObj = $row['issue'];
                            $issue = $row['issue'];
                            $issueLink = '';
                            if ($issueObj) {
                                $issueLink = link_tag(
                                    make_url('viewissue', [
                                        'issue_no'   => method_exists($issueObj, 'getFormattedIssueNo') ? $issueObj->getFormattedIssueNo(false) : $iid,
                                        'project_key'=> method_exists($issueObj->getProject(), 'getKey') ? $issueObj->getProject()->getKey() : $selected_project->getKey()
                                    ]),
                                    fa_image_tag($issueObj->getIssueType()->getFontAwesomeIcon(), [ 'title' => $issueObj->getIssueType()->getName() ]) .' '.
                                    (method_exists($issueObj,'getFormattedTitle') ? $issueObj->getFormattedTitle() : ($row['issue_label'] ?? ('#'.$iid))),
                                    [ 'title' => method_exists($issueObj,'getFormattedTitle') ? $issueObj->getFormattedTitle() : '' ]
                                );
                            } else {
                                $issueLink = htmlspecialchars($row['issue_label'] ?? ('#'.$iid), ENT_QUOTES, 'UTF-8');
                            }

                            // Build status badge (color + title) as per your snippet
                            $statusHtml = '';
                            if ($issueObj && ($issueObj->getStatus() instanceof \thebuggenie\core\entities\Datatype)) {
                                $color = $issueObj->getStatus()->getColor();
                                $title = $issueObj->getStatus()->getName();
                                $statusHtml = '<div class="status_badge" style="background-color: '.htmlspecialchars($color, ENT_QUOTES, 'UTF-8').';" title="'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'"></div>';
                            } else {
                                $statusHtml = '<div class="status_badge" style="background-color:#FFF;" title="'.__('Unknown').'"></div>';
                            }
                            ?>
                            <tr
                                    data-user-id="<?= (int)$row['uid']; ?>"
                                    data-user-name="<?= htmlspecialchars($row['user'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-issue-id="<?= (int)$row['iid']; ?>"
                                    data-issue-label="<?= htmlspecialchars($row['issue_label'] ?? ('#'.$iid), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-date="<?= htmlspecialchars($row['date_iso'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            >
                                <td><?= htmlspecialchars($row['user'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="issue <?php if ($issue->isClosed()) echo 'issue_closed'; ?> <?php if ($issue->isBlocking()) echo 'blocking'; ?>">
                                    <?php /*
                                    <div class="issue_estimates" style="float:right">
                                        <div class="issue_estimate points" style="<?php if (!$issue->getEstimatedPoints() && !$issue->getSpentPoints()) echo 'display: none;'; ?>"><?php if ($issue->getSpentPoints()): ?><span title="<?php echo __('Spent points'); ?>"><?php echo $issue->getSpentPoints(); ?></span>/<?php endif; ?><span title="<?php echo __('Estimated points'); ?>"><?php echo $issue->getEstimatedPoints(); ?></span></div>
                                        <div class="issue_estimate hours" style="<?php if (!$issue->getEstimatedHoursAndMinutes(true, true) && !$issue->getSpentHoursAndMinutes(true, true)) echo 'display: none;'; ?>"><?php if ($issue->getSpentHoursAndMinutes(true, true)): ?><span title="<?php echo __('Spent hours'); ?>"><?php echo $issue->getSpentHoursAndMinutes(true, true); ?></span>/<?php endif; ?><span title="<?php echo __('Estimated hours'); ?>"><?php echo $issue->getEstimatedHoursAndMinutes(true, true); ?></span></div>
                                    </div>
                                     */ ?>
                                    <?php if ($issue->getPriority() instanceof \thebuggenie\core\entities\Priority): ?>
                                        <span class="priority priority_<?php echo ($issue->getPriority() instanceof \thebuggenie\core\entities\Priority) ? $issue->getPriority()->getValue() : 0; ?>" title="<?php echo ($issue->getPriority() instanceof \thebuggenie\core\entities\Priority) ? __($issue->getPriority()->getName()) : __('Priority not set'); ?>"><?php echo ($issue->getPriority() instanceof \thebuggenie\core\entities\Priority) ? $issue->getPriority()->getAbbreviation() : '-'; ?></span>
                                    <?php endif; ?>
                                    <?= $issueLink; ?>
                                    <?php /*
                                    <div class="issue_percentage" title="<?php echo __('%percentage % completed', array('%percentage' => $issue->getPercentCompleted())); ?>">
                                        <div class="filler" id="issue_<?php echo $issue->getID(); ?>_percentage_filler" style="width: <?php echo $issue->getPercentCompleted(); ?>%;" title="<?php echo __('%percentage completed', array('%percentage' => $issue->getPercentCompleted().'%')); ?>"></div>
                                    </div>
                                     */ ?>
                                </td>
                                <td style="text-align:center"><?= $statusHtml; ?></td>
                                <td style="text-align:center"><?= htmlspecialchars($row['date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="text-align: right;"><?= number_format((float)$row['hours'], 2); ?></td>

                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="muted"><?php echo __('No time entries to display.'); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    (function(){
        // ---- Data from PHP ----
        var dataByUser = {
            labels: <?= json_encode($labelsUser, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            values: <?= json_encode($valuesUser, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            idByLabel: <?= json_encode($userIdByLabel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
        };
        var dataIssueStack = {
            categories: <?= json_encode($issueCategories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            issueIdByLabel: <?= json_encode($issueIdByLabel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            series: <?= json_encode($issueStackSeries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
        };
        var dataDayStack = {
            categories: <?= json_encode($labelsDay, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            dayKeys: <?= json_encode($dayKeys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            series: <?= json_encode($dayStackSeries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
        };

        // ---- DOM ----
        var buttons    = document.querySelectorAll('.tbg-hours-actions .tbg-btn[data-mode]');
        var clearBtn   = document.getElementById('clear-filter');
        var filterChip = document.getElementById('active-filter-chip');
        var tbody      = document.getElementById('hours-tbody');

        // ---- State (supports composite filter for stacked clicks) ----
        var state = {
            mode: 'user',            // 'user' | 'issue' | 'day'
            userId: null,            // when set, filter by this user
            issueId: null,           // when in issue mode and a stack clicked
            dayKey: null             // 'YYYY-MM-DD' when in day mode and a stack clicked
        };
        // Users visible via legend toggles (null => all)
        var visibleUserIds = null;


        function setActive(btn){
            for (var i=0;i<buttons.length;i++){ var b = buttons[i]; b.classList.toggle('active', b===btn); }
        }


        function userIdFromSeriesName(name) {
            for (var lbl in dataByUser.idByLabel) {
                if (Object.prototype.hasOwnProperty.call(dataByUser.idByLabel, lbl) && lbl === name) {
                    return String(dataByUser.idByLabel[lbl]);
                }
            }
            return null;
        }
        function recomputeVisibleUsersFrom(chart) {
            if (!chart || !chart.series) { visibleUserIds = null; return; }
            var ids = [];
            for (var i=0; i<chart.series.length; i++) {
                var s = chart.series[i];
                if (s && typeof s.visible !== 'undefined' && s.name) {
                    if (s.visible) {
                        var uid = userIdFromSeriesName(s.name);
                        if (uid != null) ids.push(uid);
                    }
                }
            }
            if (ids.length === 0 || ids.length === chart.series.length) visibleUserIds = null; else visibleUserIds = ids;
        }

        function filterTable(){
            if (!tbody) return;
            var rows = tbody.querySelectorAll('tr');
            rows.forEach(function(tr){
                var ok = true;
                if (visibleUserIds && visibleUserIds.length){
                    var uidStr = String(tr.getAttribute('data-user-id'));
                    ok = ok && (visibleUserIds.indexOf(uidStr) !== -1);
                }
                if (state.userId != null) {
                    ok = ok && (String(tr.getAttribute('data-user-id')) === String(state.userId));
                }
                if (state.issueId != null) {
                    ok = ok && (String(tr.getAttribute('data-issue-id')) === String(state.issueId));
                }
                if (state.dayKey != null) {
                    ok = ok && ((tr.getAttribute('data-date') || '') === String(state.dayKey));
                }
                tr.style.display = ok ? '' : 'none';
            });
        }
        function setFilterChip(){
            if (!filterChip || !clearBtn) return;
            var parts = [];
            if (state.userId != null) {
                // find label for userId
                var name = null;
                // reverse lookup from dataByUser.idByLabel
                for (var lbl in dataByUser.idByLabel){ if (String(dataByUser.idByLabel[lbl]) === String(state.userId)) { name = lbl; break; } }
                parts.push('User: ' + (name || state.userId));
            }
            if (state.issueId != null) {
                // find label for issueId
                var label = null;
                for (var k in dataIssueStack.issueIdByLabel){ if (String(dataIssueStack.issueIdByLabel[k]) === String(state.issueId)) { label = k; break; } }
                parts.push('Issue: ' + (label || state.issueId));
            }
            if (state.dayKey != null) {
                // map to pretty label by index
                var idx = dataDayStack.dayKeys.indexOf(String(state.dayKey));
                var pretty = (idx >= 0) ? dataDayStack.categories[idx] : state.dayKey;
                parts.push('Day: ' + pretty);
            }
            var has = parts.length > 0;
            clearBtn.style.display = has ? '' : 'none';
            filterChip.style.display = has ? '' : 'none';
            filterChip.textContent = has ? ('Filter: ' + parts.join(' · ')) : '';
        }

        function makeUserColumnOptions(){
            // Reduce dataset to the selected user (if any)
            var labels = dataByUser.labels.slice();
            var values = dataByUser.values.slice();

            if (state.userId != null) {
                // Find label by userId (reverse lookup)
                var selLabel = null;
                for (var lbl in dataByUser.idByLabel) {
                    if (String(dataByUser.idByLabel[lbl]) === String(state.userId)) { selLabel = lbl; break; }
                }
                if (selLabel) {
                    var idx = labels.indexOf(selLabel);
                    if (idx > -1) {
                        labels = [labels[idx]];
                        values = [Number(values[idx] || 0)];
                    }
                }
            }

            return {
                chart: { type: 'column', renderTo: 'hours-chart-container', events: { redraw: function(){ recomputeVisibleUsersFrom(this); filterTable(); } } },
                title: { text: 'Total hours by user' },
                xAxis: { categories: labels, labels: { rotation: labels.length > 8 ? -45 : 0 } },
                yAxis: { title: { text: 'Hours' }, allowDecimals: true },
                legend: { enabled: false },
                tooltip: { pointFormat: '<b>{point.y:.2f} h</b>' },
                plotOptions: {
                    series: {
                        cursor: 'pointer',
                        point: {
                            events: {
                                click: function (e) {
                                    // Avoid global body click handler side-effects
                                    if (e && e.originalEvent && e.originalEvent.stopPropagation) { e.originalEvent.stopPropagation(); }

                                    // Set filter to this user
                                    var id = dataByUser.idByLabel[this.name];
                                    state.userId = (id != null) ? id : null;

                                    // Clear cross-dimension filters when picking a top-level user bar
                                    state.issueId = null;
                                    state.dayKey  = null;

                                    setFilterChip();
                                    filterTable();
                                    renderChart('user');
                                }
                            }
                        }
                    }
                },
                series: [{
                    name: 'Hours',
                    data: (function(){
                        var pts = [];
                        for (var i=0;i<labels.length;i++){
                            pts.push({ name: labels[i], y: Number(values[i] || 0) });
                        }
                        return pts;
                    })()
                }]
            };
        }

        function makeIssueStackOptions(){
            // Optionally filter to a single issue and/or single user
            var cats = dataIssueStack.categories.slice();
            var series = JSON.parse(JSON.stringify(dataIssueStack.series));

            if (state.issueId != null) {
                var targetLabel = null; var idx = -1;
                for (var k in dataIssueStack.issueIdByLabel) {
                    if (String(dataIssueStack.issueIdByLabel[k]) === String(state.issueId)) { targetLabel = k; break; }
                }
                if (targetLabel) {
                    idx = cats.indexOf(targetLabel);
                    if (idx >= 0) {
                        cats = [cats[idx]];
                        for (var s=0; s<series.length; s++){ series[s].data = [ series[s].data[idx] ]; }
                    }
                }
            }
            if (state.userId != null) {
                var filtered = [];
                for (var s=0; s<series.length; s++){ if (String(series[s].uid) === String(state.userId)) filtered.push(series[s]); }
                series = filtered;
            }

            return {
                chart: { type: 'column', renderTo: 'hours-chart-container', events: {
                        redraw: function(){ recomputeVisibleUsersFrom(this); filterTable(); }
                    }},
                title: { text: 'Hours by Issue' },
                xAxis: { categories: cats, labels: { rotation: cats.length > 8 ? -45 : 0 } },
                yAxis: { title: { text: 'Hours' }, allowDecimals: true, stackLabels: { enabled:false } },
                legend: { enabled: true },
                tooltip: { shared: true },
                plotOptions: {
                    column: {
                        stacking: 'normal',
                        cursor: 'pointer',
                        point: { events: { click: function(e){
                                    if (e && e.originalEvent && e.originalEvent.stopPropagation) { e.originalEvent.stopPropagation(); }
                                    // clicking a stack segment sets user + issue
                                    var userName = this.series.name;
                                    var userId = null; for (var lbl in dataByUser.idByLabel){ if (lbl === userName) { userId = dataByUser.idByLabel[lbl]; break; } }
                                    var issueLabel = this.category; var issueId = dataIssueStack.issueIdByLabel[issueLabel];
                                    state.userId = (userId != null) ? userId : null;
                                    state.issueId = (issueId != null) ? issueId : null;
                                    state.dayKey = null;
                                    setFilterChip(); filterTable(); renderChart(state.mode);
                                }}}
                    },
                    // NEW: keep the matrix in sync when legend toggles visibility (no matter redraw behavior)
                    series: {
                        events: {
                            show: function(){ recomputeVisibleUsersFrom(this.chart); filterTable(); },
                            hide: function(){ recomputeVisibleUsersFrom(this.chart); filterTable(); }
                        }
                    }
                },
                series: (function(){
                    var out = []; for (var i=0;i<series.length;i++){ out.push({ name: series[i].name, data: series[i].data }); }
                    return out;
                })()
            };
        }

        function makeDayStackOptions(){
            var cats = dataDayStack.categories.slice();
            var keys = dataDayStack.dayKeys.slice();
            var series = JSON.parse(JSON.stringify(dataDayStack.series));

            if (state.dayKey != null) {
                var idx = keys.indexOf(String(state.dayKey));
                if (idx >= 0) {
                    cats = [cats[idx]]; keys = [keys[idx]];
                    for (var s=0; s<series.length; s++){ series[s].data = [ series[s].data[idx] ]; }
                }
            }
            if (state.userId != null) {
                var filtered = []; for (var s=0; s<series.length; s++){ if (String(series[s].uid) === String(state.userId)) filtered.push(series[s]); }
                series = filtered;
            }

            return {
                chart: { type: 'column', renderTo: 'hours-chart-container', events: {
                        redraw: function(){ recomputeVisibleUsersFrom(this); filterTable(); }
                    }},
                title: { text: 'Hours by Day' },
                xAxis: { categories: cats, labels: { rotation: cats.length > 10 ? -45 : 0 } },
                yAxis: { title: { text: 'Hours' }, allowDecimals: true },
                legend: { enabled: true },
                tooltip: { shared: true },
                plotOptions: {
                    column: {
                        stacking: 'normal',
                        cursor: 'pointer',
                        point: { events: { click: function(e){
                                    if (e && e.originalEvent && e.originalEvent.stopPropagation) { e.originalEvent.stopPropagation(); }
                                    // clicking a stack segment sets user + day
                                    var userName = this.series.name;
                                    var userId = null; for (var lbl in dataByUser.idByLabel){ if (lbl === userName) { userId = dataByUser.idByLabel[lbl]; break; } }
                                    var idx = cats.indexOf(this.category); var dk = (idx>=0) ? keys[idx] : null;
                                    state.userId = (userId != null) ? userId : null;
                                    state.dayKey = dk;
                                    state.issueId = null;
                                    setFilterChip(); filterTable(); renderChart(state.mode);
                                }}}
                    },
                    // NEW: mirror legend visibility to the matrix
                    series: {
                        events: {
                            show: function(){ recomputeVisibleUsersFrom(this.chart); filterTable(); },
                            hide: function(){ recomputeVisibleUsersFrom(this.chart); filterTable(); }
                        }
                    }
                },
                series: (function(){ var out=[]; for (var i=0;i<series.length;i++){ out.push({ name: series[i].name, data: series[i].data }); } return out; })()
            };
        }

        var chart = null;
        function renderChart(mode){
            state.mode = mode || state.mode;
            var options = null;
            if (state.mode === 'issue') options = makeIssueStackOptions();
            else if (state.mode === 'day') options = makeDayStackOptions();
            else options = makeUserColumnOptions();
            if (chart) chart.destroy();
            chart = new Highcharts.Chart(options);
            recomputeVisibleUsersFrom(chart);
            filterTable();
        }

        // Buttons
        buttons.forEach(function(btn){
            btn.addEventListener('click', function(){
                setActive(btn);
                // clear all filters on mode switch
                state.userId = null; state.issueId = null; state.dayKey = null;
                setFilterChip(); filterTable(); renderChart(btn.getAttribute('data-mode'));
            });
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function(){
                state.userId = null; state.issueId = null; state.dayKey = null;
                setFilterChip(); filterTable(); renderChart(state.mode);
            });
        }

        // Initial render
        renderChart('user');
        setFilterChip();
        filterTable();
    })();
</script>
