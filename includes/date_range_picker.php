<?php
/**
 * Reusable Custom Date Range Picker
 * ------------------------------------
 * Usage — set variables then include inside your <form>:
 *
 *   $drpId       = 'myPage';       // unique ID (no spaces)
 *   $drpDateFrom = $dateFrom;      // Y-m-d or ''
 *   $drpDateTo   = $dateTo;        // Y-m-d or ''
 *   $drpPreset   = $activePreset;  // preset key or ''
 *   $drpMinDate  = '';             // optional min=""
 *   $drpMaxDate  = date('Y-m-d');  // optional max=""
 *   include __DIR__ . '/../includes/date_range_picker.php';
 *
 * The panel is teleported to <body> on first open so it always renders
 * on top regardless of ancestor stacking contexts (transform, z-index, etc.)
 */

$drpId     = $drpId      ?? 'drp';
$drpFrom   = $drpDateFrom ?? '';
$drpTo     = $drpDateTo   ?? '';
$drpPreset = $drpPreset   ?? '';
$drpMin    = $drpMinDate  ?? '';
$drpMax    = $drpMaxDate  ?? date('Y-m-d');

// Button label
if ($drpPreset && $drpPreset !== 'custom') {
    $presetLabels = [
        'today'   => 'Today',       'last_day' => 'Last day',
        'week'    => 'Last 7 days', 'month'    => 'Last 30 days',
        'quarter' => 'Last quarter','year'     => 'This year',
    ];
    $drpBtnLabel = $presetLabels[$drpPreset] ?? ucfirst(str_replace('_', ' ', $drpPreset));
} elseif ($drpFrom || $drpTo) {
    $drpBtnLabel = ($drpFrom ?: '…') . ' – ' . ($drpTo ?: '…');
} else {
    $drpBtnLabel = 'Date Range';
}
$drpActive = ($drpFrom || $drpTo || ($drpPreset && $drpPreset !== 'custom'));
?>

<!-- ── Date Range Picker: <?= htmlspecialchars($drpId) ?> ── -->
<input type="hidden" name="date_from"   id="<?= $drpId ?>_from"   value="<?= htmlspecialchars($drpFrom) ?>">
<input type="hidden" name="date_to"     id="<?= $drpId ?>_to"     value="<?= htmlspecialchars($drpTo) ?>">
<input type="hidden" name="date_preset" id="<?= $drpId ?>_preset" value="<?= htmlspecialchars($drpPreset) ?>">

<!-- Anchor: only the button lives here; panel is teleported to <body> -->
<span id="<?= $drpId ?>_anchor" style="display:inline-block;position:relative;">
    <button type="button" id="<?= $drpId ?>_btn"
            onclick="drpToggle('<?= $drpId ?>')"
            style="display:inline-flex;align-items:center;gap:7px;padding:5px 12px;
                   border-radius:8px;cursor:pointer;white-space:nowrap;min-width:152px;
                   font-size:0.82rem;font-weight:500;transition:all .15s;
                   border:1px solid <?= $drpActive ? '#1a4f7a' : '#ced4da' ?>;
                   background:<?= $drpActive ? '#edf4fd' : '#fff' ?>;color:#1a4f7a;">
        <i class="bi bi-calendar-range" style="color:#3a7fa8;font-size:0.9rem;flex-shrink:0;"></i>
        <span id="<?= $drpId ?>_label" style="flex:1;text-align:left;"><?= htmlspecialchars($drpBtnLabel) ?></span>
        <i class="bi bi-chevron-down" id="<?= $drpId ?>_chevron"
           style="font-size:0.7rem;color:#3a7fa8;transition:transform .2s;flex-shrink:0;"></i>
    </button>
</span>

<!--
  Panel is defined here but immediately moved to <body> by JS.
  This means it is never clipped or buried by any ancestor stacking context.
-->
<div id="<?= $drpId ?>_panel"
     data-drp-id="<?= $drpId ?>"
     style="display:none;position:fixed;z-index:2147483647;
            background:#fff;border:1px solid #d0e6f5;border-radius:14px;
            box-shadow:0 8px 32px rgba(26,79,122,0.18);min-width:236px;
            overflow:hidden;font-family:inherit;">

    <!-- Preset list -->
    <div id="<?= $drpId ?>_presets">
        <?php
        $presets = [
            ''        => ['label' => 'All time',     'icon' => 'bi-infinity'],
            'today'   => ['label' => 'Today',        'icon' => 'bi-calendar-day'],
            'last_day' => ['label' => 'Last day',    'icon' => 'bi-calendar2'],
            'week'    => ['label' => 'Last 7 days',  'icon' => 'bi-calendar-week'],
            'month'   => ['label' => 'Last 30 days', 'icon' => 'bi-calendar-month'],
            'quarter' => ['label' => 'Last quarter', 'icon' => 'bi-calendar3'],
            'year'    => ['label' => 'This year',    'icon' => 'bi-calendar-check'],
            'custom'  => ['label' => 'Custom range', 'icon' => 'bi-sliders'],
        ];
        foreach ($presets as $val => $p):
            $isActive = ($drpPreset === $val);
        ?>
        <div class="drp-preset-item"
             onclick="drpApplyPreset('<?= $drpId ?>', '<?= $val ?>')"
             style="display:flex;align-items:center;gap:10px;padding:9px 16px;cursor:pointer;
                    font-size:0.83rem;transition:background .12s;
                    color:<?= $isActive ? '#1a4f7a' : '#2d3748' ?>;
                    background:<?= $isActive ? '#edf4fd' : '#fff' ?>;">
            <i class="bi <?= $p['icon'] ?>" style="font-size:0.9rem;color:#3a7fa8;flex-shrink:0;"></i>
            <span style="flex:1;"><?= $p['label'] ?></span>
            <?php if ($isActive): ?>
                <i class="bi bi-check2" style="color:#1a4f7a;font-size:0.85rem;"></i>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Custom range -->
    <div id="<?= $drpId ?>_custom"
         style="display:<?= $drpPreset === 'custom' ? 'block' : 'none' ?>;
                padding:12px 16px 14px;border-top:1px solid #e8f0f8;background:#f8fbff;">
        <div style="font-size:0.68rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;
                    letter-spacing:.06em;margin-bottom:10px;">Custom Range</div>
        <div style="display:flex;flex-direction:column;gap:8px;">
            <div>
                <label style="font-size:0.74rem;color:#374151;font-weight:600;display:block;margin-bottom:3px;">From</label>
                <input type="date" id="<?= $drpId ?>_customFrom"
                       style="width:100%;padding:5px 8px;border:1px solid #d0e6f5;border-radius:7px;font-size:0.82rem;"
                       value="<?= htmlspecialchars($drpFrom) ?>"
                       <?= $drpMin ? 'min="'.htmlspecialchars($drpMin).'"' : '' ?>
                       max="<?= htmlspecialchars($drpMax) ?>">
            </div>
            <div>
                <label style="font-size:0.74rem;color:#374151;font-weight:600;display:block;margin-bottom:3px;">To</label>
                <input type="date" id="<?= $drpId ?>_customTo"
                       style="width:100%;padding:5px 8px;border:1px solid #d0e6f5;border-radius:7px;font-size:0.82rem;"
                       value="<?= htmlspecialchars($drpTo) ?>"
                       <?= $drpMin ? 'min="'.htmlspecialchars($drpMin).'"' : '' ?>
                       max="<?= htmlspecialchars($drpMax) ?>">
            </div>
            <button type="button"
                    onclick="drpApplyCustom('<?= $drpId ?>')"
                    style="margin-top:2px;padding:7px;border-radius:8px;border:none;
                           background:#1a4f7a;color:#fff;font-size:0.83rem;font-weight:600;
                           cursor:pointer;width:100%;transition:background .15s;"
                    onmouseover="this.style.background='#14406a'"
                    onmouseout="this.style.background='#1a4f7a'">
                Apply
            </button>
        </div>
    </div>
</div>
<!-- ── End Date Range Picker ── -->

<?php if (!defined('DRP_SCRIPT_INCLUDED')): define('DRP_SCRIPT_INCLUDED', true); ?>
<script>
/* ── Date Range Picker — shared JS ─────────────────────────────────── */
(function () {

    // Teleport all DRP panels to <body> once DOM is ready so they are never
    // trapped inside ancestor stacking contexts (transform / isolation / etc.)
    function teleportPanels() {
        document.querySelectorAll('[data-drp-id]').forEach(function (panel) {
            if (panel.parentElement !== document.body) {
                document.body.appendChild(panel);
            }
        });
    }

    // Reposition open panels (called on scroll / resize)
    function repositionOpenPanels() {
        document.querySelectorAll('[data-drp-id]').forEach(function (panel) {
            if (panel.style.display === 'none' || panel.style.display === '') return;
            var id  = panel.getAttribute('data-drp-id');
            var btn = document.getElementById(id + '_btn');
            if (!btn) return;
            var rect = btn.getBoundingClientRect();
            panel.style.top  = (rect.bottom + 4) + 'px';
            panel.style.left = rect.left + 'px';
            // Prevent overflow off right edge
            var panelW = panel.offsetWidth || 236;
            if (rect.left + panelW > window.innerWidth - 8) {
                panel.style.left = Math.max(8, window.innerWidth - panelW - 8) + 'px';
            }
        });
    }

    // Close all open panels (optionally except one)
    function closeAll(exceptId) {
        document.querySelectorAll('[data-drp-id]').forEach(function (panel) {
            var id = panel.getAttribute('data-drp-id');
            if (id === exceptId) return;
            panel.style.display = 'none';
            var chv = document.getElementById(id + '_chevron');
            if (chv) chv.style.transform = 'rotate(0deg)';
        });
    }

    // Outside-click closes all panels
    document.addEventListener('click', function (e) {
        document.querySelectorAll('[data-drp-id]').forEach(function (panel) {
            if (panel.style.display === 'none' || panel.style.display === '') return;
            var id  = panel.getAttribute('data-drp-id');
            var btn = document.getElementById(id + '_btn');
            var anc = document.getElementById(id + '_anchor');
            if (!panel.contains(e.target) && (!btn || !btn.contains(e.target)) && (!anc || !anc.contains(e.target))) {
                panel.style.display = 'none';
                var chv = document.getElementById(id + '_chevron');
                if (chv) chv.style.transform = 'rotate(0deg)';
            }
        });
    });

    window.addEventListener('scroll',  repositionOpenPanels, { passive: true });
    window.addEventListener('resize',  repositionOpenPanels, { passive: true });

    // Run teleport after DOM ready (handles both inline and deferred cases)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', teleportPanels);
    } else {
        teleportPanels();
    }

    // Expose helpers globally
    window._drpTeleport  = teleportPanels;
    window._drpRepos     = repositionOpenPanels;
    window._drpCloseAll  = closeAll;
})();

function drpToggle(id) {
    var panel = document.getElementById(id + '_panel');
    if (!panel) return;
    var isOpen = panel.style.display === 'block';
    _drpCloseAll(id);                   // close siblings first
    isOpen ? _drpClose(id) : _drpOpen(id);
}

function _drpOpen(id) {
    var panel   = document.getElementById(id + '_panel');
    var btn     = document.getElementById(id + '_btn');
    var chevron = document.getElementById(id + '_chevron');
    if (!panel || !btn) return;

    var rect     = btn.getBoundingClientRect();
    var panelW   = panel.offsetWidth || 236;
    var leftPos  = rect.left;

    // Clamp so panel doesn't bleed off right edge
    if (leftPos + panelW > window.innerWidth - 8) {
        leftPos = Math.max(8, window.innerWidth - panelW - 8);
    }

    panel.style.top     = (rect.bottom + 4) + 'px';
    panel.style.left    = leftPos + 'px';
    panel.style.display = 'block';
    if (chevron) chevron.style.transform = 'rotate(180deg)';
}

function _drpClose(id) {
    var panel   = document.getElementById(id + '_panel');
    var chevron = document.getElementById(id + '_chevron');
    if (panel)   panel.style.display    = 'none';
    if (chevron) chevron.style.transform = 'rotate(0deg)';
}

function drpApplyPreset(id, preset) {
    var fromEl   = document.getElementById(id + '_from');
    var toEl     = document.getElementById(id + '_to');
    var presetEl = document.getElementById(id + '_preset');
    var custom   = document.getElementById(id + '_custom');
    var label    = document.getElementById(id + '_label');
    var btn      = document.getElementById(id + '_btn');

    // Update active highlight on all preset rows
    var vals = ['', 'today', 'last_day', 'week', 'month', 'quarter', 'year', 'custom'];
    document.querySelectorAll('#' + id + '_presets .drp-preset-item').forEach(function (item, i) {
        var active = vals[i] === preset;
        item.style.background = active ? '#edf4fd' : '#fff';
        item.style.color      = active ? '#1a4f7a' : '#2d3748';
        var chk = item.querySelector('.bi-check2');
        if (active && !chk) {
            var ic = document.createElement('i');
            ic.className    = 'bi bi-check2';
            ic.style.cssText = 'color:#1a4f7a;font-size:0.85rem;';
            item.appendChild(ic);
        } else if (!active && chk) {
            chk.remove();
        }
    });

    if (preset === 'custom') {
        if (custom)   custom.style.display = 'block';
        if (label)    label.textContent    = 'Custom range';
        if (presetEl) presetEl.value       = 'custom';
        return; // wait for Apply click
    }

    if (custom) custom.style.display = 'none';

    var names = {
        '': 'Date Range', today: 'Today', last_day: 'Last day',
        week: 'Last 7 days', month: 'Last 30 days',
        quarter: 'Last quarter', year: 'This year'
    };
    if (label) label.textContent = names[preset] || 'Date Range';
    if (btn) {
        btn.style.borderColor = preset ? '#1a4f7a' : '#ced4da';
        btn.style.background  = preset ? '#edf4fd' : '#fff';
    }
    if (fromEl)   fromEl.value   = '';
    if (toEl)     toEl.value     = '';
    if (presetEl) presetEl.value = preset;

    _drpClose(id);

    // Submit the form that owns the hidden inputs (anchor is still in the form)
    var anchor = document.getElementById(id + '_anchor');
    if (anchor) {
        var form = anchor.closest('form');
        if (form) form.submit();
    }
}

function drpApplyCustom(id) {
    var fromInput = document.getElementById(id + '_customFrom');
    var toInput   = document.getElementById(id + '_customTo');
    var fromEl    = document.getElementById(id + '_from');
    var toEl      = document.getElementById(id + '_to');
    var presetEl  = document.getElementById(id + '_preset');
    var label     = document.getElementById(id + '_label');
    var btn       = document.getElementById(id + '_btn');

    var from = fromInput ? fromInput.value : '';
    var to   = toInput   ? toInput.value   : '';

    if (!from && !to) { alert('Please select at least one date.'); return; }

    if (fromEl)   fromEl.value   = from;
    if (toEl)     toEl.value     = to;
    if (presetEl) presetEl.value = 'custom';
    if (label)    label.textContent = (from || '…') + ' – ' + (to || '…');
    if (btn) {
        btn.style.borderColor = '#1a4f7a';
        btn.style.background  = '#edf4fd';
    }

    _drpClose(id);

    var anchor = document.getElementById(id + '_anchor');
    if (anchor) {
        var form = anchor.closest('form');
        if (form) form.submit();
    }
}
</script>
<style>
.drp-preset-item:hover { background: #f0f7ff !important; }
</style>
<?php endif; ?>
