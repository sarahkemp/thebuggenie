<div id="dashboard_<?php echo $view->getID(); ?>_graph"
     class="graph_view"
     style="margin: 5px; width: 100%; height: 250px;"></div>

<style>
    .flot-tooltip {
        position: absolute;
        display: none;
        padding: 6px 8px;
        background: rgba(50,50,50,.92);
        color: #fff;
        font-size: 12px;
        line-height: 1.2;
        border-radius: 3px;
        pointer-events: none;
        z-index: 9999;
        white-space: nowrap;
    }
</style>

<?php
// ---------- 1) Read server data WITHOUT overwriting it ----------
$hasIssues = (isset($issues) && is_array($issues));
$hasOpen   = ($hasIssues && isset($issues['open'])   && is_array($issues['open']));
$hasClosed = ($hasIssues && isset($issues['closed']) && is_array($issues['closed']));

// We'll build sequences oldest → newest for plotting.
// IMPORTANT: Do NOT overwrite $issues. Only read from it and default missing keys to 0.
$tzLA    = new \DateTimeZone('America/Los_Angeles');
$tzUTC   = new \DateTimeZone('UTC');
$todayLA = new \DateTime('today', $tzLA);

$xUtcMs     = []; // oldest → newest (UTC ms that represent LA midnights)
$openSeq    = []; // oldest → newest
$closedSeq  = []; // oldest → newest

for ($cc = 30; $cc >= 0; $cc--) {
    // Build X-axis as LA midnight for the day (converted to UTC ms)
    $startLA   = (clone $todayLA)->modify('-' . $cc . ' days')->setTime(0, 0, 0);
    $startUtcMs = (clone $startLA)->setTimezone($tzUTC)->getTimestamp() * 1000;

    $xUtcMs[] = (int)$startUtcMs;

    // Pull counts from $issues if present; otherwise default to 0 for that day only
    $openVal   = ($hasOpen   && array_key_exists($cc, $issues['open']))   ? (int)$issues['open'][$cc]   : 0;
    $closedVal = ($hasClosed && array_key_exists($cc, $issues['closed'])) ? (int)$issues['closed'][$cc] : 0;

    $openSeq[]   = $openVal;
    $closedSeq[] = $closedVal;
}

// Optional quick diagnostics (PHP comment in source for you to view, won’t show to end users)
?>


<script type="text/javascript">
    require(['jquery', 'jquery.flot', 'jquery.flot.time', 'jquery.ba-resize'], function ($) {
        var $el = $("#dashboard_<?php echo $view->getID(); ?>_graph");

        // ---------- 2) Data from PHP (oldest → newest) ----------
        var xUtcMs  = <?php echo json_encode($xUtcMs); ?>;
        var opens   = <?php echo json_encode($openSeq); ?>;
        var closeds = <?php echo json_encode($closedSeq); ?>;

        // Optional console diagnostics (helps confirm we're not feeding all zeros)
        try {
            console.log('Plot tail (last 5 days):',
                xUtcMs.slice(-5).map(function(x, i){
                    var j = opens.length - 5 + i;
                    return { x:x, open: opens[j], closed: closeds[j] };
                })
            );
        } catch(e) {}

        var d_open = [], d_closed = [];
        for (var i = 0; i < xUtcMs.length; i++) {
            d_open.push([xUtcMs[i], opens[i]]);
            d_closed.push([xUtcMs[i], closeds[i]]);
        }

        // X-axis: cover the full window (inclusive of last day)
        var xMin = xUtcMs[0];
        var xMax = xUtcMs[xUtcMs.length - 1] + 24*3600*1000;

        // Make labels readable for ~31 days
        var tickSize = [2, 'day']; // adjust to [3,'day'] if still cramped

        // ---------- 3) Plot ----------
        var plot;
        function initPlot() {
            plot = $.plot($el, [
                {
                    data: d_closed,
                    lines: { show: true, fill: true },
                    points: { show: true },
                    color: '#92BA6F',
                    label: '<?php echo __('Issues closed'); ?>'
                },
                {
                    data: d_open,
                    lines: { show: true, fill: true },
                    points: { show: true },
                    color: '#F8C939',
                    label: '<?php echo __('Issues opened'); ?>'
                }
            ], {
                // keep the rest of your options the same

                xaxis: {
                    mode: 'time',
                    timezone: 'utc',     // x values are LA midnights converted to UTC ms
                    min: xMin,
                    max: xMax,
                    // Put grid lines exactly at each day's LA-midnight (in UTC ms)
                    ticks: (function () {
                        // Use your existing xUtcMs (oldest → newest) from the surrounding scope
                        var step = 2; // show a label every 2 days; change to 3 if still tight
                        var ticks = [];
                        for (var i = 0; i < xUtcMs.length; i++) {
                            // Label every Nth day; blank others but keep the grid line
                            if (i % step === 0) {
                                var d = new Date(xUtcMs[i]);
                                var mm = String(d.getUTCMonth() + 1).padStart(2, '0'); // UTC is fine because timestamps encode LA midnight in UTC
                                var dd = String(d.getUTCDate()).padStart(2, '0');
                                ticks.push([xUtcMs[i], mm + '/' + dd]);
                            } else {
                                ticks.push([xUtcMs[i], '']); // keep the line, hide the label
                            }
                        }
                        return ticks;
                    })(),
                    color: '#AAA'
                },
            });
        }

        $el.resize(initPlot);
        initPlot();

        // ---------- 4) Tooltips (LA date; clamped to viewport) ----------
        var $tip = $('.flot-tooltip');
        if ($tip.length === 0) $tip = $('<div class="flot-tooltip"></div>').appendTo('body');

        function formatDateLA(msUtc) {
            try {
                return new Date(msUtc).toLocaleDateString('en-US', {
                    timeZone: 'America/Los_Angeles',
                    month: 'short', day: 'numeric', year: 'numeric'
                });
            } catch (e) {
                var d = new Date(msUtc);
                return (d.getUTCMonth()+1) + '/' + d.getUTCDate() + '/' + d.getUTCFullYear();
            }
        }

        function showTip(pageX, pageY, html) {
            $tip.html(html).show();

            var tipW = $tip.outerWidth(), tipH = $tip.outerHeight();
            var $win = $(window);
            var left = pageX + 12, top = pageY - tipH - 12;
            var scrollL = $win.scrollLeft(), scrollT = $win.scrollTop();
            var maxL = scrollL + $win.width()  - tipW - 8;
            var maxT = scrollT + $win.height() - tipH - 8;

            if (left > maxL) left = pageX - tipW - 12;  // flip if near right edge
            if (top < scrollT) top = pageY + 12;        // place below if clipped
            if (left < scrollL + 8) left = scrollL + 8;
            if (top  > maxT)        top  = maxT;

            $tip.css({ left:left, top:top });
        }

        $el.off('plothover.__tip').on('plothover.__tip', function (event, pos, item) {
            if (!item) return $tip.hide();
            var x = item.datapoint[0], y = item.datapoint[1];
            var series = item.series && item.series.label ? item.series.label : '';
            showTip(item.pageX, item.pageY,
                '<strong>' + series + '</strong><br/>' +
                formatDateLA(x) + ' : <strong>' + y + '</strong>'
            );
        });

        $el.off('mouseleave.__tip').on('mouseleave.__tip', function () { $tip.hide(); });
    });
</script>