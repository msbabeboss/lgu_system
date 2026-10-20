<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

$barangays    = $db->query("SELECT * FROM barangays WHERE status='active' ORDER BY barangay_name")->fetchAll();
$selectedBrgy = isset($_GET['barangay_id']) ? (int)$_GET['barangay_id'] : 0;

$pageIcon     = 'bi-cpu-fill';
$pageTitle    = 'ARIMA Forecasting';
$pageSubtitle = 'Predictive analytics powered by Python · statsmodels';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= csrfMeta() ?>">
<title><?= $pageTitle ?> — <?= APP_NAME ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
<style>
.sec-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#3a7fa8;margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.sec-title::after{content:'';flex:1;height:1px;background:#e0f0e4;}
.chart-card{background:#fff;border:1.5px solid #90c0d8;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(26,79,122,.07);}
.chart-card .ch{padding:9px 14px;font-size:.75rem;font-weight:600;color:#1a2e1f;background:#f4f7fa;border-bottom:1.5px solid #90c0d8;display:flex;align-items:center;justify-content:between;gap:6px;}
.chart-card .cb{padding:12px 14px;}
.pcard{border-radius:14px;padding:14px;display:flex;flex-direction:column;gap:8px;border:1.5px solid rgba(0,0,0,.10);box-shadow:0 1px 4px rgba(0,0,0,.06);transition:transform .15s,box-shadow .15s;}
.pcard:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.12);}
.pcard-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.05rem;flex-shrink:0;}
.pcard-val{font-size:1.3rem;font-weight:800;color:#1a2e1f;line-height:1.1;}
.pcard-lbl{font-size:.68rem;color:#2a5070;font-weight:500;}
.stat-chip{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:600;padding:2px 8px;border-radius:20px;}
.insight-card{background:#fff;border-radius:12px;border:1.5px solid #d0e8f0;padding:14px 16px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.loader-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.88);z-index:5;border-radius:10px;}
.api-badge{font-size:.65rem;padding:2px 8px;border-radius:20px;font-weight:600;}
</style>
</head>
<body>
<?php include __DIR__.'/../includes/sidebar_super.php'; ?>
<?php include __DIR__.'/../includes/topbar.php'; ?>
<div class="main-content">

  <!-- ── Top Controls Bar ─────────────────────────────────── -->
  <div class="d-flex flex-wrap align-items-end gap-3 mb-4 mt-1">

    <!-- Barangay filter -->
    <div>
      <label class="form-label mb-1" style="font-size:.73rem;font-weight:600;color:#3a7fa8;">Barangay Filter</label>
      <select id="ctrlBarangay" class="form-select form-select-sm" style="width:190px;font-size:.78rem;">
        <option value="0">All Barangays</option>
        <?php foreach ($barangays as $b): ?>
          <option value="<?= $b['id'] ?>" <?= $selectedBrgy == $b['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['barangay_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Forecast horizon -->
    <div>
      <label class="form-label mb-1" style="font-size:.73rem;font-weight:600;color:#3a7fa8;">Forecast Horizon</label>
      <select id="ctrlMonths" class="form-select form-select-sm" style="width:150px;font-size:.78rem;">
        <option value="3">Next 3 months</option>
        <option value="6" selected>Next 6 months</option>
        <option value="9">Next 9 months</option>
        <option value="12">Next 12 months</option>
      </select>
    </div>

    <!-- History window -->
    <div>
      <label class="form-label mb-1" style="font-size:.73rem;font-weight:600;color:#3a7fa8;">History Window</label>
      <select id="ctrlLookback" class="form-select form-select-sm" style="width:155px;font-size:.78rem;">
        <option value="12">Last 12 months</option>
        <option value="24" selected>Last 24 months</option>
        <option value="36">Last 36 months</option>
      </select>
    </div>

    <!-- Run button -->
    <div>
      <button id="runBtn" class="btn btn-primary btn-sm px-4" style="font-size:.8rem;">
        <i class="bi bi-play-fill me-1"></i>Run Forecast
      </button>
    </div>

    <!-- Status + engine badge -->
    <div class="ms-auto d-flex align-items-center gap-2">
      <span id="apiStatus" class="api-badge" style="display:none;"></span>
      <span id="runStatus" style="font-size:.72rem;color:#64748b;"></span>
    </div>
  </div>

  <!-- ── API Offline Alert ──────────────────────────────────── -->
  <div id="offlineAlert" style="display:none!important;"></div>

  <!-- ── Summary KPI Cards ────────────────────────────────── -->
  <div id="kpiRow" class="row g-3 mb-4" style="display:none!important;"></div>

  <!-- ── Series Tabs ───────────────────────────────────────── -->
  <ul class="nav nav-pills mb-3" id="forecastTabs" style="gap:6px;">
    <li class="nav-item">
      <button class="nav-link active" data-tab="residents" style="font-size:.78rem;padding:5px 14px;">
        <i class="bi bi-people-fill me-1"></i>Population
      </button>
    </li>
    <li class="nav-item">
      <button class="nav-link" data-tab="blotter" style="font-size:.78rem;padding:5px 14px;">
        <i class="bi bi-shield-exclamation me-1"></i>Blotter Cases
      </button>
    </li>
    <li class="nav-item">
      <button class="nav-link" data-tab="certificates" style="font-size:.78rem;padding:5px 14px;">
        <i class="bi bi-file-earmark-check-fill me-1"></i>Certificates
      </button>
    </li>
    <li class="nav-item">
      <button class="nav-link" data-tab="all" style="font-size:.78rem;padding:5px 14px;">
        <i class="bi bi-grid-fill me-1"></i>All Series
      </button>
    </li>
  </ul>

  <!-- ── Individual Tab Panels ─────────────────────────────── -->
  <!-- POPULATION -->
  <div id="tab-residents" class="tab-panel">
    <div class="row g-3">
      <div class="col-12">
        <div class="chart-card" style="position:relative;">
          <div class="ch justify-content-between">
            <span><i class="bi bi-people-fill me-1 text-primary"></i>New Residents Registered — Historical + ARIMA Forecast</span>
            <span id="ord-residents" style="font-size:.65rem;color:#64748b;font-family:monospace;"></span>
          </div>
          <div class="cb" style="min-height:260px;position:relative;">
            <div id="ldr-residents" class="loader-overlay"><div class="spinner-border spinner-border-sm text-primary me-2"></div><span style="font-size:.78rem;">Running ARIMA…</span></div>
            <canvas id="chart-residents" style="max-height:260px;"></canvas>
          </div>
        </div>
      </div>
      <div class="col-12">
        <div class="chart-card">
          <div class="ch"><i class="bi bi-table me-1"></i>Forecast Table — Population</div>
          <div class="cb p-0"><div id="table-residents"></div></div>
        </div>
      </div>
      <div class="col-12"><div id="desc-residents"></div></div>
    </div>
  </div>

  <!-- BLOTTER -->
  <div id="tab-blotter" class="tab-panel" style="display:none;">
    <div class="row g-3">
      <div class="col-12">
        <div class="chart-card">
          <div class="ch justify-content-between">
            <span><i class="bi bi-shield-exclamation me-1 text-danger"></i>Blotter Cases — Historical + ARIMA Forecast</span>
            <span id="ord-blotter" style="font-size:.65rem;color:#64748b;font-family:monospace;"></span>
          </div>
          <div class="cb" style="min-height:260px;position:relative;">
            <div id="ldr-blotter" class="loader-overlay"><div class="spinner-border spinner-border-sm text-danger me-2"></div><span style="font-size:.78rem;">Running ARIMA…</span></div>
            <canvas id="chart-blotter" style="max-height:260px;"></canvas>
          </div>
        </div>
      </div>
      <div class="col-12">
        <div class="chart-card">
          <div class="ch"><i class="bi bi-table me-1"></i>Forecast Table — Blotter</div>
          <div class="cb p-0"><div id="table-blotter"></div></div>
        </div>
      </div>
      <div class="col-12"><div id="desc-blotter"></div></div>
    </div>
  </div>

  <!-- CERTIFICATES -->
  <div id="tab-certificates" class="tab-panel" style="display:none;">
    <div class="row g-3">
      <div class="col-12">
        <div class="chart-card">
          <div class="ch justify-content-between">
            <span><i class="bi bi-file-earmark-check-fill me-1 text-success"></i>Certificates Issued — Historical + ARIMA Forecast</span>
            <span id="ord-certificates" style="font-size:.65rem;color:#64748b;font-family:monospace;"></span>
          </div>
          <div class="cb" style="min-height:260px;position:relative;">
            <div id="ldr-certificates" class="loader-overlay"><div class="spinner-border spinner-border-sm text-success me-2"></div><span style="font-size:.78rem;">Running ARIMA…</span></div>
            <canvas id="chart-certificates" style="max-height:260px;"></canvas>
          </div>
        </div>
      </div>
      <div class="col-12">
        <div class="chart-card">
          <div class="ch"><i class="bi bi-table me-1"></i>Forecast Table — Certificates</div>
          <div class="cb p-0"><div id="table-certificates"></div></div>
        </div>
      </div>
      <div class="col-12"><div id="desc-certificates"></div></div>
    </div>
  </div>

  <!-- ALL SERIES -->
  <div id="tab-all" class="tab-panel" style="display:none;">
    <div class="row g-3">
      <div class="col-12">
        <div class="chart-card">
          <div class="ch"><i class="bi bi-graph-up me-1 text-primary"></i>All Series — ARIMA Forecast (Normalized)</div>
          <div class="cb" style="min-height:260px;position:relative;">
            <div id="ldr-all" class="loader-overlay"><div class="spinner-border spinner-border-sm text-primary me-2"></div><span style="font-size:.78rem;">Running ARIMA…</span></div>
            <canvas id="chart-all" style="max-height:260px;"></canvas>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── ARIMA Documentation Section ─────────────────────── -->
  <div style="margin-top:32px;padding:20px 24px;background:#f4f7fa;border:1.5px solid #d0e4f0;border-radius:14px;">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
      <div>
        <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#3a7fa8;margin-bottom:4px;">
          <i class="bi bi-book-fill me-1"></i>How Does This Forecasting Work?
        </div>
        <p style="font-size:.82rem;color:#4b5563;margin:0;max-width:680px;">
          This module uses <strong>ARIMA</strong> (AutoRegressive Integrated Moving Average) — a statistical model that learns from past monthly data to predict future trends for population, blotter cases, and certificates.
        </p>
      </div>
      <button class="btn btn-sm" onclick="document.getElementById('arimaDocModal').style.display='flex'"
        style="background:#1a4f7a;color:#fff;border:none;border-radius:8px;padding:7px 18px;font-size:.78rem;font-weight:600;white-space:nowrap;display:flex;align-items:center;gap:6px;">
        <i class="bi bi-journal-text"></i> Learn More
      </button>
    </div>
  </div>

</div><!-- /main-content -->

<!-- ══════════════════════════════════════════════════════════
     ARIMA Documentation Modal
═══════════════════════════════════════════════════════════ -->
<div id="arimaDocModal" style="display:none;position:fixed;inset:0;z-index:1055;background:rgba(15,23,42,.55);align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:680px;max-height:88vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25);">

    <!-- Header -->
    <div style="background:#1a4f7a;padding:18px 22px;border-radius:16px 16px 0 0;display:flex;align-items:center;justify-content:space-between;">
      <div style="font-size:.95rem;font-weight:700;color:#fff;display:flex;align-items:center;gap:8px;">
        <i class="bi bi-cpu-fill"></i> ARIMA Forecasting — How It Works
      </div>
      <button onclick="document.getElementById('arimaDocModal').style.display='none'"
        style="background:rgba(255,255,255,.15);border:none;color:#fff;width:28px;height:28px;border-radius:50%;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">✕</button>
    </div>

    <!-- Body -->
    <div style="padding:22px 24px;font-size:.83rem;color:#374151;line-height:1.85;">

      <!-- What is ARIMA -->
      <div style="margin-bottom:18px;">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#1a4f7a;margin-bottom:6px;">
          <i class="bi bi-info-circle-fill me-1"></i>What is ARIMA?
        </div>
        <p style="margin:0;">
          <strong>ARIMA</strong> stands for <strong>AutoRegressive Integrated Moving Average</strong>. It is a statistical model that uses past monthly data to predict future values. The model looks for patterns in the historical numbers and extends those patterns forward.
        </p>
      </div>

      <!-- 3 Components -->
      <div style="margin-bottom:18px;">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#1a4f7a;margin-bottom:8px;">
          <i class="bi bi-diagram-3-fill me-1"></i>The Three Parts: ARIMA(p, d, q)
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">
          <div style="background:#dbeafe;border-radius:8px;padding:12px;">
            <div style="font-weight:700;color:#1a4f7a;margin-bottom:4px;font-size:.82rem;"><strong>p</strong> — AutoRegressive</div>
            <div style="font-size:.78rem;color:#1e3a5f;">Uses past values to predict the next one. If registrations were high last month, it likely affects this month.</div>
          </div>
          <div style="background:#dcfce7;border-radius:8px;padding:12px;">
            <div style="font-weight:700;color:#15803d;margin-bottom:4px;font-size:.82rem;"><strong>d</strong> — Integrated</div>
            <div style="font-size:.78rem;color:#14532d;">Removes trends from the data so the model can work with stable, consistent numbers.</div>
          </div>
          <div style="background:#fef9c3;border-radius:8px;padding:12px;">
            <div style="font-weight:700;color:#854d0e;margin-bottom:4px;font-size:.82rem;"><strong>q</strong> — Moving Average</div>
            <div style="font-size:.78rem;color:#713f12;">Smooths out noise by accounting for past prediction errors.</div>
          </div>
        </div>
      </div>

      <!-- How the chart works -->
      <div style="margin-bottom:18px;">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#1a4f7a;margin-bottom:8px;">
          <i class="bi bi-graph-up me-1"></i>How to Read the Chart
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:28px;height:3px;background:#1a4f7a;border-radius:2px;flex-shrink:0;"></div>
            <div><strong>Solid line</strong> — actual recorded data from the database.</div>
          </div>
          <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:28px;height:0;border-top:3px dashed #22c55e;flex-shrink:0;"></div>
            <div><strong>Dashed line</strong> — ARIMA projected values for future months.</div>
          </div>
        </div>
      </div>

      <!-- How forecast is generated -->
      <div style="margin-bottom:18px;">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#1a4f7a;margin-bottom:8px;">
          <i class="bi bi-list-ol me-1"></i>How the Forecast is Generated
        </div>
        <ol style="padding-left:18px;margin:0;display:flex;flex-direction:column;gap:6px;font-size:.82rem;">
          <li><strong>Data is pulled</strong> from the database — monthly counts for the last 24 months.</li>
          <li><strong>ARIMA is fitted</strong> to the historical data, automatically choosing the best p, d, q values.</li>
          <li><strong>The model projects</strong> values for the selected number of months ahead.</li>
          <li><strong>If data is too sparse</strong>, the system falls back to a simpler trend-based method (Holt's smoothing).</li>
        </ol>
      </div>

      <!-- Limitations -->
      <div style="background:#fef9c3;border:1px solid #fbbf24;border-radius:8px;padding:12px 16px;">
        <div style="font-size:.72rem;font-weight:700;color:#854d0e;margin-bottom:6px;">
          <i class="bi bi-exclamation-triangle-fill me-1"></i>Important Reminders
        </div>
        <ul style="margin:0;padding-left:16px;font-size:.78rem;color:#78350f;display:flex;flex-direction:column;gap:4px;">
          <li>Forecasts are based on <strong>historical patterns only</strong> — unexpected events (disasters, policy changes) are not accounted for.</li>
          <li>The more months of history available, the more accurate the forecast.</li>
          <li>Use forecasts as a <strong>planning guide</strong>, not as guaranteed predictions.</li>
        </ul>
      </div>

    </div><!-- /body -->

    <!-- Footer -->
    <div style="padding:12px 22px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;">
      <button onclick="document.getElementById('arimaDocModal').style.display='none'"
        class="btn btn-sm btn-primary" style="font-size:.78rem;padding:6px 18px;border-radius:8px;">
        Close
      </button>
    </div>

  </div>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script defer>
// ════════════════════════════════════════════════════════
//  Config
// ════════════════════════════════════════════════════════
const PROXY       = '<?= BASE_URL ?>/ajax/get_arima_forecast.php';
const BRGY_SEL    = document.getElementById('ctrlBarangay');
const MONTHS_SEL  = document.getElementById('ctrlMonths');
const LOOKBACK_SEL= document.getElementById('ctrlLookback');
const RUN_BTN     = document.getElementById('runBtn');
const RUN_STATUS  = document.getElementById('runStatus');
const API_STATUS  = document.getElementById('apiStatus');
const OFFLINE_ALT = document.getElementById('offlineAlert');
const KPI_ROW     = document.getElementById('kpiRow');

const SERIES = ['residents','blotter','certificates'];

const PALETTE = {
  residents:    { hist:'#1a4f7a', fc:'#22c55e', ci:'rgba(34,197,94,.13)',  icon:'bi-people-fill',              label:'Population',   color:'#1a4f7a' },
  blotter:      { hist:'#c62828', fc:'#f97316', ci:'rgba(249,115,22,.13)', icon:'bi-shield-exclamation',       label:'Blotter Cases',color:'#c62828' },
  certificates: { hist:'#6a1b9a', fc:'#06b6d4', ci:'rgba(6,182,212,.13)', icon:'bi-file-earmark-check-fill',  label:'Certificates', color:'#6a1b9a' },
};

const TREND = { up:{ icon:'↑', color:'#15803d', bg:'#dcfce7' }, down:{ icon:'↓', color:'#b91c1c', bg:'#fee2e2' }, stable:{ icon:'→', color:'#1a4f7a', bg:'#dbeafe' } };

let charts  = {};
let lastData = null;
let activeTab = 'residents';

// ════════════════════════════════════════════════════════
//  Tab switching
// ════════════════════════════════════════════════════════
document.querySelectorAll('#forecastTabs .nav-link').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#forecastTabs .nav-link').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    activeTab = btn.dataset.tab;
    document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
    document.getElementById('tab-' + activeTab).style.display = '';
    if (lastData) renderAll(lastData);
  });
});

// ════════════════════════════════════════════════════════
//  Loader helpers
// ════════════════════════════════════════════════════════
function showLoader(id, show) {
  const el = document.getElementById('ldr-' + id);
  if (el) el.style.display = show ? 'flex' : 'none';
}
function showAllLoaders(show) {
  [...SERIES, 'all'].forEach(k => showLoader(k, show));
}

// ════════════════════════════════════════════════════════
//  Chart builder
// ════════════════════════════════════════════════════════
function buildChart(canvasId, seriesKey, data) {
  const pal = PALETTE[seriesKey];
  const ctx = document.getElementById(canvasId);
  if (!ctx) return;
  if (charts[canvasId]) charts[canvasId].destroy();

  const hLabels = data.history.labels;
  const hVals   = data.history.values;
  const fLabels = data.forecast.labels;
  const fVals   = data.forecast.values;
  const loCI    = data.forecast.lower_ci;
  const hiCI    = data.forecast.upper_ci;
  const bridge  = hVals[hVals.length - 1];
  const allLbls = [...hLabels, ...fLabels];
  const pad     = n => [...Array(hLabels.length - 1).fill(null), bridge, ...n];

  // Vertical "Today" divider plugin
  const todayLinePlugin = {
    id: 'todayLine' + canvasId,
    afterDraw(chart) {
      const { ctx: c, scales: { x, y } } = chart;
      const divIdx = hLabels.length - 1;
      if (divIdx < 0) return;
      const xPos = x.getPixelForValue(divIdx);
      c.save();
      c.beginPath();
      c.moveTo(xPos, y.top);
      c.lineTo(xPos, y.bottom);
      c.strokeStyle = 'rgba(100,116,139,0.45)';
      c.lineWidth = 1.5;
      c.setLineDash([4, 4]);
      c.stroke();
      c.setLineDash([]);
      c.fillStyle = '#64748b';
      c.font = 'bold 9px sans-serif';
      c.textAlign = 'center';
      c.fillText('TODAY', xPos, y.top + 10);
      c.fillStyle = '#15803d';
      c.textAlign = 'left';
      c.fillText('FORECAST →', xPos + 6, y.top + 10);
      c.restore();
    }
  };

  charts[canvasId] = new Chart(ctx, {
    type: 'line',
    data: {
      labels: allLbls,
      datasets: [
        { label:'CI Upper', data:pad(hiCI), borderColor:'transparent', backgroundColor:pal.ci, fill:'+1', tension:.4, pointRadius:0, order:4 },
        { label:'CI Lower', data:pad(loCI), borderColor:'transparent', backgroundColor:'transparent', fill:false, tension:.4, pointRadius:0, order:5 },
        { label:'Historical', data:[...hVals, ...Array(fLabels.length).fill(null)], borderColor:pal.hist, backgroundColor:'transparent', borderWidth:2, tension:.4, pointRadius:3, pointBackgroundColor:pal.hist, order:2 },
        { label:'Forecast', data:pad(fVals), borderColor:pal.fc, backgroundColor:'transparent', borderWidth:2.5, borderDash:[7,4], tension:.4, pointRadius:4, pointBackgroundColor:pal.fc, pointBorderColor:'#fff', pointBorderWidth:1.5, order:1 },
      ]
    },
    plugins: [todayLinePlugin],
    options: {
      responsive:true, maintainAspectRatio:true,
      interaction:{ mode:'index', intersect:false },
      plugins:{
        legend:{ display:true, position:'top', labels:{ font:{size:10}, boxWidth:12, padding:10, filter:i=>!i.text.startsWith('CI') }},
        tooltip:{ callbacks:{
          label: c => {
            if (c.dataset.label.startsWith('CI') || c.parsed.y === null) return null;
            if (c.dataset.label === 'Forecast' && c.dataIndex >= hLabels.length) {
              const fi = c.dataIndex - hLabels.length;
              return [` Forecast: ${Math.round(c.parsed.y)}`, ` 80% CI: [${Math.round(loCI[fi]??0)}, ${Math.round(hiCI[fi]??0)}]`];
            }
            return ` ${c.dataset.label}: ${Math.round(c.parsed.y)}`;
          },
          filter: i => i.parsed.y !== null
        }}
      },
      scales:{
        x:{ ticks:{ font:{size:9}, maxRotation:35, color: c => c.index >= hLabels.length ? '#15803d' : '#64748b' }, grid:{ color:'#f0f0f0' }},
        y:{ ticks:{ font:{size:10} }, grid:{ color:'#f0f0f0' }, beginAtZero:true }
      }
    }
  });
}

// ════════════════════════════════════════════════════════
//  Overlay / combined chart
// ════════════════════════════════════════════════════════
function buildAllChart(forecasts) {
  const ctx = document.getElementById('chart-all');
  if (!ctx) return;
  if (charts['chart-all']) charts['chart-all'].destroy();

  // Normalize each series to % of its own max for comparison
  const datasets = [];
  SERIES.forEach(key => {
    const d   = forecasts[key];
    if (!d) return;
    const pal = PALETTE[key];
    const all = [...d.history.values, ...d.forecast.values];
    const mx  = Math.max(...all) || 1;
    const norm = v => v === null ? null : Math.round((v / mx) * 100);

    const hLabels = d.history.labels;
    const fLabels = d.forecast.labels;
    const allLbls = [...hLabels, ...fLabels];
    const hNorm   = d.history.values.map(norm);
    const bridge  = hNorm[hNorm.length - 1];
    const fNorm   = d.forecast.values.map(norm);

    datasets.push({
      label: pal.label + ' (hist)',
      data: [...hNorm, ...Array(fLabels.length).fill(null)],
      borderColor: pal.hist, backgroundColor:'transparent',
      borderWidth:1.5, tension:.4, pointRadius:2, pointBackgroundColor:pal.hist,
    });
    datasets.push({
      label: pal.label + ' (forecast)',
      data: [...Array(hLabels.length - 1).fill(null), bridge, ...fNorm],
      borderColor: pal.fc, backgroundColor:'transparent',
      borderWidth:2, borderDash:[6,4], tension:.4,
      pointRadius:3, pointBackgroundColor:pal.fc,
    });
  });

  // All labels from residents (they all share the same months)
  const rd = forecasts.residents;
  const allLabels = rd ? [...rd.history.labels, ...rd.forecast.labels] : [];

  charts['chart-all'] = new Chart(ctx, {
    type:'line',
    data:{ labels:allLabels, datasets },
    options:{
      responsive:true, maintainAspectRatio:true,
      interaction:{ mode:'index', intersect:false },
      plugins:{
        legend:{ display:true, position:'top', labels:{ font:{size:9}, boxWidth:10, padding:8 }},
        tooltip:{ callbacks:{ label: c => c.parsed.y === null ? null : ` ${c.dataset.label}: ${c.parsed.y}%` }}
      },
      scales:{
        x:{ ticks:{ font:{size:9}, maxRotation:35 }, grid:{ color:'#f0f0f0' }},
        y:{ ticks:{ font:{size:10}, callback: v => v + '%' }, grid:{ color:'#f0f0f0' }, beginAtZero:true }
      }
    }
  });
}

// ════════════════════════════════════════════════════════
//  Insight card builder
// ════════════════════════════════════════════════════════
function buildInsight(containerId, key, d) {
  const el = document.getElementById(containerId);
  if (!el || !d) return;
  const s   = d.summary;
  const pal = PALETTE[key];
  const tr  = TREND[s.trend_direction] || TREND.stable;
  const order = (d.arima_order || []).join(',');
  const dqColor = {good:'#15803d',fair:'#854d0e',sparse:'#b91c1c'}[s.data_quality||'fair'];
  const dqBg    = {good:'#dcfce7',fair:'#fef9c3',sparse:'#fee2e2'}[s.data_quality||'fair'];

  el.innerHTML = `
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
      <div style="width:36px;height:36px;border-radius:9px;background:${pal.ci.replace('.13','.35')};display:flex;align-items:center;justify-content:center;">
        <i class="bi ${pal.icon}" style="color:${pal.color};font-size:1rem;"></i>
      </div>
      <div>
        <div style="font-size:.8rem;font-weight:700;color:#1a2e1f;">${pal.label}</div>
        <div style="font-size:.65rem;color:#64748b;font-family:monospace;">ARIMA(${order}) · ${d.engine ?? 'ARIMA'}</div>
      </div>
    </div>
    <div class="row g-2 mb-2">
      <div class="col-6">
        <div style="background:#f4f7fa;border-radius:8px;padding:8px 10px;">
          <div style="font-size:.62rem;color:#64748b;font-weight:600;text-transform:uppercase;">Avg Historical</div>
          <div style="font-size:1.1rem;font-weight:800;color:#1a4f7a;">${Math.round(s.avg_historical)}</div>
          <div style="font-size:.62rem;color:#94a3b8;">per month</div>
        </div>
      </div>
      <div class="col-6">
        <div style="background:#f4f7fa;border-radius:8px;padding:8px 10px;">
          <div style="font-size:.62rem;color:#64748b;font-weight:600;text-transform:uppercase;">Avg Forecast</div>
          <div style="font-size:1.1rem;font-weight:800;color:${pal.color};">${Math.round(s.avg_forecast)}</div>
          <div style="font-size:.62rem;color:#94a3b8;">per month</div>
        </div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
      <span style="background:${tr.bg};color:${tr.color};font-size:.75rem;font-weight:700;padding:3px 10px;border-radius:20px;">
        ${tr.icon} ${Math.abs(s.trend_pct)}% ${s.trend_direction}
      </span>
      <span style="font-size:.65rem;color:#94a3b8;">vs historical avg</span>
    </div>
    <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
      <span style="background:${dqBg};color:${dqColor};font-size:.65rem;font-weight:700;padding:2px 8px;border-radius:12px;text-transform:capitalize;">Data: ${s.data_quality||'fair'}</span>
      <span style="font-size:.65rem;color:#94a3b8;">${s.non_zero_points??s.data_points} active months</span>
    </div>
    <div style="font-size:.65rem;color:#94a3b8;border-top:1px solid #e8f0f4;padding-top:8px;">
      Based on ${s.data_points} months of history
    </div>`;
}

// ════════════════════════════════════════════════════════
//  Forecast table builder
// ════════════════════════════════════════════════════════
function buildTable(containerId, d) {
  const el = document.getElementById(containerId);
  if (!el || !d) return;
  const fLabels = d.forecast.labels;
  const fVals   = d.forecast.values;
  const loCI    = d.forecast.lower_ci;
  const hiCI    = d.forecast.upper_ci;

  let rows = '';
  fLabels.forEach((lbl, i) => {
    const val = Math.round(fVals[i]);
    const lo  = Math.round(loCI[i] ?? 0);
    const hi  = Math.round(hiCI[i] ?? 0);
    const range = hi - lo;
    rows += `<tr>
      <td style="font-size:.75rem;color:#64748b;padding:5px 10px;">${i+1}</td>
      <td style="font-size:.78rem;font-weight:600;padding:5px 10px;">${lbl}</td>
      <td style="font-size:.78rem;text-align:center;padding:5px 10px;"><span class="badge bg-primary" style="font-size:.72rem;">${val}</span></td>
    </tr>`;
  });

  el.innerHTML = `<table class="table table-sm mb-0">
    <thead style="background:#f4f7fa;">
      <tr>
        <th style="font-size:.65rem;color:#3a7fa8;padding:6px 10px;">#</th>
        <th style="font-size:.65rem;color:#3a7fa8;padding:6px 10px;">Month</th>
        <th style="font-size:.65rem;color:#3a7fa8;text-align:center;padding:6px 10px;">Forecast</th>
      </tr>
    </thead>
    <tbody>${rows}</tbody>
  </table>`;
}

// ════════════════════════════════════════════════════════
//  KPI cards
// ════════════════════════════════════════════════════════
function buildKPI(forecasts) {
  const meta = {
    residents:    { bg:'#d8edf7', ibg:'#90d0e8' },
    blotter:      { bg:'#fde8e8', ibg:'#f8c4c4' },
    certificates: { bg:'#e8f4fb', ibg:'#b0d8f0' },
  };
  let html = '';
  SERIES.forEach(key => {
    const d = forecasts[key]; if (!d) return;
    const s = d.summary, pal = PALETTE[key], m = meta[key];
    const tr = TREND[s.trend_direction] || TREND.stable;
    html += `<div class="col-md-4">
      <div class="pcard" style="background:${m.bg};">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:${m.ibg};"><i class="bi ${pal.icon}" style="color:${pal.color};"></i></div>
          <div style="flex:1;">
            <div class="pcard-val">${Math.round(s.avg_forecast)}
              <span style="font-size:.7rem;font-weight:500;color:${tr.color};margin-left:5px;">${tr.icon} ${Math.abs(s.trend_pct)}%</span>
            </div>
            <div class="pcard-lbl">${pal.label} — avg forecast/mo</div>
            <div style="font-size:.62rem;color:#64748b;margin-top:1px;">ARIMA(${(d.arima_order||[]).join(',')}) · ${s.data_points} data pts</div>
          </div>
        </div>
      </div>
    </div>`;
  });
  KPI_ROW.innerHTML = html;
  KPI_ROW.style.display = '';
}

// ════════════════════════════════════════════════════════
//  Forecast change description builder
// ════════════════════════════════════════════════════════
function buildDescription(containerId, key, d) {
  const el = document.getElementById(containerId);
  if (!el || !d) return;

  const s      = d.summary;
  const pal    = PALETTE[key];
  const engine = d.engine ?? 'ARIMA';
  const dir    = s.trend_direction ?? 'stable';
  const months = d.forecast.labels.length;

  let line2;
  if (dir === 'up') {
    line2 = `The forecast starts from the most recent actual value and applies a gradual <strong>upward</strong> monthly change over ${months} months.`;
  } else if (dir === 'down') {
    line2 = `The forecast starts from the most recent actual value and applies a gradual <strong>downward</strong> monthly change over ${months} months.`;
  } else {
    line2 = `The forecast starts from the most recent actual value and projects a <strong>stable</strong> trend over ${months} months.`;
  }

  el.innerHTML = `
    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px 18px;margin-top:6px;font-size:.82rem;color:#374151;line-height:1.85;">
      <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:8px;">Forecast Explanation</div>
      Based on the historical data, a trend-based method using <strong>${engine}</strong> was applied for the ${pal.label.toLowerCase()} forecast.<br>
      ${line2}<br>
      The projection should be interpreted as an indicative estimate for planning purposes.
    </div>`;
}

// ════════════════════════════════════════════════════════
//  Auto-save forecast records (fire-and-forget)
// ════════════════════════════════════════════════════════
async function saveForecastRecords(data) {
  const fcs   = data.forecasts || {};
  const brgid = parseInt(BRGY_SEL.value) || 0;
  for (const key of SERIES) {
    const d = fcs[key];
    if (!d) continue;
    try {
      await fetch('<?= BASE_URL ?>/ajax/save_forecast.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
          barangay_id:     brgid,
          series_type:     key,
          forecast_months: parseInt(MONTHS_SEL.value),
          lookback_months: parseInt(LOOKBACK_SEL.value),
          arima_order:     (d.arima_order || []).join(','),
          engine:          d.engine ?? 'ARIMA',
          summary:         d.summary,
          forecast:        d.forecast,
          history:         d.history,
          generated_at:    data.generated_at ?? new Date().toISOString(),
        })
      });
    } catch (e) { /* silent — never block the UI */ }
  }
}

// ════════════════════════════════════════════════════════
//  Render all
// ════════════════════════════════════════════════════════
function renderAll(data) {
  const fcs = data.forecasts || {};

  // Individual series
  SERIES.forEach(key => {
    if (!fcs[key]) return;
    buildChart('chart-' + key, key, fcs[key]);
    buildTable('table-' + key, fcs[key]);
    buildDescription('desc-' + key, key, fcs[key]);
  });

  // All-series overlay
  buildAllChart(fcs);

  // KPI
  buildKPI(fcs);
  showAllLoaders(false);
}

// ════════════════════════════════════════════════════════
//  Fetch & run
// ════════════════════════════════════════════════════════
async function runForecast() {
  const months   = MONTHS_SEL.value;
  const lookback = LOOKBACK_SEL.value;
  const brgid    = BRGY_SEL.value;

  RUN_BTN.disabled = true;
  RUN_BTN.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Running…';
  RUN_STATUS.textContent = '';
  OFFLINE_ALT.style.display = 'none';
  showAllLoaders(true);

  const url = `${PROXY}?type=all&months=${months}&barangay_id=${brgid}&lookback=${lookback}`;

  try {
    const t0  = performance.now();
    const res = await fetch(url);
    const data= await res.json();

    if (!res.ok || data.error) {
      OFFLINE_ALT.style.display = '';
      showAllLoaders(false);
      RUN_BTN.disabled = false;
      RUN_BTN.innerHTML = '<i class="bi bi-play-fill me-1"></i>Run Forecast';
      return;
    }

    lastData = data;
    const elapsed = ((performance.now() - t0) / 1000).toFixed(1);

    // Engine badge
    const eng = data.engine ?? 'ARIMA';
    API_STATUS.textContent   = eng;
    API_STATUS.style.display = '';
    API_STATUS.style.background = eng.includes('ARIMA') ? '#dcfce7' : '#fef9c3';
    API_STATUS.style.color      = eng.includes('ARIMA') ? '#15803d' : '#854d0e';

    renderAll(data);
    saveForecastRecords(data);

    RUN_STATUS.textContent = `✓ Completed in ${elapsed}s`;
    RUN_STATUS.style.color = '#15803d';

  } catch (err) {
    OFFLINE_ALT.style.display = '';
    showAllLoaders(false);
    RUN_STATUS.textContent = 'Connection error — API not running?';
    RUN_STATUS.style.color = '#c62828';
  }

  RUN_BTN.disabled = false;
  RUN_BTN.innerHTML = '<i class="bi bi-play-fill me-1"></i>Run Forecast';
}

RUN_BTN.addEventListener('click', runForecast);

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

// Auto-run on load
runForecast();
</script>
</body>
</html>
