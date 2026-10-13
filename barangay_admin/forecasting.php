<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

$brgy = $db->prepare("SELECT * FROM barangays WHERE id=?");
$brgy->execute([$bid]); $brgy = $brgy->fetch();

$pageIcon     = 'bi-graph-up-arrow';
$pageTitle    = 'Population Forecasting';
$pageSubtitle = 'ARIMA-based population growth projections.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="<?= csrfMeta() ?>">
  <title>Forecasting — <?= APP_NAME ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
  <style>
    .fc-filter-label { font-size:.72rem; color:#6b7280; margin-bottom:3px; font-weight:500; }
    .fc-filter-val   { font-size:.82rem; font-weight:600; color:#111827; }
    .meta-pill { font-size:.75rem; color:#374151; }
    .meta-pill span { font-weight:700; }
    .trend-up   { color:#15803d; font-weight:700; }
    .trend-down { color:#b91c1c; font-weight:700; }
    .trend-stable { color:#1a4f7a; font-weight:700; }
    .explain-box { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:14px 18px; font-size:.82rem; color:#374151; line-height:1.85; }
    .section-divider { border:none; border-top:1px solid #e5e7eb; margin:18px 0; }
  </style>
</head>
<body>
<?php include __DIR__.'/../includes/sidebar_barangay.php'; ?>
<?php include __DIR__.'/../includes/topbar.php'; ?>

<div class="main-content">

  <!-- Page heading -->
  <nav style="font-size:.75rem;color:#9ca3af;margin-bottom:6px;">
    <i class="bi bi-house-fill me-1"></i>
    <a href="dashboard.php" style="color:#9ca3af;text-decoration:none;">Dashboard</a>
    <span class="mx-1">/</span> Forecasting
  </nav>
  <h5 class="fw-bold mb-4">Forecasting</h5>

  <!-- ── Filter Row (mirrors the image layout) ── -->
  <div class="card border shadow-sm mb-3" style="border-radius:10px;border-color:#e5e7eb!important;">
    <div class="card-body py-3">
      <div class="row g-3 align-items-end">

        <div class="col-6 col-sm-3">
          <div class="fc-filter-label">Forecast Type</div>
          <select id="selSeries" class="form-select form-select-sm" style="font-size:.82rem;">
            <option value="residents">Residents</option>
            <option value="blotter">Blotter Cases</option>
            <option value="certificates">Certificates</option>
          </select>
        </div>

        <div class="col-6 col-sm-2">
          <div class="fc-filter-label">Period</div>
          <select id="selMonths" class="form-select form-select-sm" style="font-size:.82rem;">
            <option value="3">3 Months</option>
            <option value="6" selected>6 Months</option>
            <option value="9">9 Months</option>
            <option value="12">12 Months</option>
          </select>
        </div>

        <div class="col-6 col-sm-3">
          <div class="fc-filter-label">Barangay</div>
          <div class="form-select form-select-sm" style="font-size:.82rem;background:#f3f4f6;color:#6b7280;cursor:default;pointer-events:none;">
            <?= htmlspecialchars($brgy['barangay_name'] ?? 'Your Barangay') ?>
          </div>
        </div>

        <div class="col-6 col-sm-2">
          <div class="fc-filter-label">Method</div>
          <div id="methodBadge" class="form-select form-select-sm" style="font-size:.82rem;background:#f3f4f6;color:#6b7280;cursor:default;pointer-events:none;">
            ARIMA
          </div>
        </div>

        <div class="col-12 col-sm-2 d-flex align-items-end">
          <button id="runBtn" class="btn btn-primary btn-sm w-100" style="font-size:.82rem;">
            <i class="bi bi-play-fill me-1"></i>Run Forecast
          </button>
        </div>

      </div>
    </div>
  </div>

  <!-- ── Meta row: Method · Total · Monthly Avg · Trend ── -->
  <div id="metaRow" style="display:none;" class="d-flex flex-wrap gap-4 mb-3 px-1 align-items-center">
    <div class="meta-pill">Method <span id="metaMethod">—</span></div>
    <div class="meta-pill">Total Forecast <span id="metaTotal">—</span></div>
    <div class="meta-pill">Monthly Avg <span id="metaAvg">—</span></div>
    <div class="meta-pill">Trend <span id="metaTrend">—</span></div>
  </div>

  <!-- ── Chart Card ── -->
  <div class="card border shadow-sm mb-3" style="border-radius:10px;border-color:#e5e7eb!important;">
    <div class="card-body" style="padding:16px 20px;">

      <!-- Legend row (Actual ── · Forecast - - -) -->
      <div id="legendRow" class="d-flex align-items-center gap-3 mb-3" style="font-size:.75rem;color:#6b7280;">
        <span>
          <svg width="28" height="10"><line x1="0" y1="5" x2="28" y2="5" stroke="#1a4f7a" stroke-width="2"/></svg>
          &nbsp;Actual
        </span>
        <span>
          <svg width="28" height="10"><line x1="0" y1="5" x2="28" y2="5" stroke="#22c55e" stroke-width="2" stroke-dasharray="5,3"/></svg>
          &nbsp;Forecast
        </span>
      </div>

      <div style="position:relative;height:300px;">
        <!-- Loader -->
        <div id="chartLoader" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.9);z-index:5;border-radius:8px;">
          <div class="spinner-border spinner-border-sm text-primary me-2"></div>
          <span style="font-size:.8rem;color:#6b7280;">Running ARIMA…</span>
        </div>
        <canvas id="mainChart"></canvas>
      </div>

    </div>
  </div>

  <!-- ── Forecast Table ── -->
  <div id="tableSection" style="display:none;" class="card border shadow-sm mb-3" style="border-radius:10px;border-color:#e5e7eb!important;">
    <div class="card-body p-0">
      <div style="padding:10px 16px;font-size:.78rem;font-weight:600;color:#1a4f7a;border-bottom:1px solid #e5e7eb;">
        <i class="bi bi-table me-1"></i>Forecast Table — <span id="tableSeriesLabel"></span>
      </div>
      <div id="forecastTable"></div>
    </div>
  </div>

  <!-- ── Forecast Explanation ── -->
  <div id="explainSection" style="display:none;">
    <div class="explain-box">
      <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:8px;">Forecast Explanation</div>
      <div id="explainText"></div>
    </div>
  </div>

  <!-- ── Documentation ── -->
  <div style="margin-top:24px;padding:18px 22px;background:#f4f7fa;border:1.5px solid #d0e4f0;border-radius:12px;" class="d-flex align-items-center justify-content-between flex-wrap gap-3">
    <div>
      <div style="font-size:.78rem;font-weight:700;color:#3a7fa8;margin-bottom:3px;">
        <i class="bi bi-book-fill me-1"></i>How Does This Forecasting Work?
      </div>
      <p style="font-size:.8rem;color:#4b5563;margin:0;">
        This module uses <strong>ARIMA</strong> to learn from past monthly data and predict future trends for population, blotter cases, and certificates.
      </p>
    </div>
    <button class="btn btn-sm" onclick="document.getElementById('arimaDocModal').style.display='flex'"
      style="background:#1a4f7a;color:#fff;border:none;border-radius:8px;padding:7px 16px;font-size:.78rem;font-weight:600;white-space:nowrap;">
      <i class="bi bi-journal-text me-1"></i>Learn More
    </button>
  </div>

</div><!-- /main-content -->

<!-- ARIMA Doc Modal -->
<div id="arimaDocModal" style="display:none;position:fixed;inset:0;z-index:1055;background:rgba(15,23,42,.55);align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:640px;max-height:88vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25);">

    <div style="background:#1a4f7a;padding:16px 20px;border-radius:14px 14px 0 0;display:flex;align-items:center;justify-content:space-between;">
      <div style="font-size:.9rem;font-weight:700;color:#fff;display:flex;align-items:center;gap:8px;">
        <i class="bi bi-cpu-fill"></i> ARIMA Forecasting — How It Works
      </div>
      <button onclick="document.getElementById('arimaDocModal').style.display='none'"
        style="background:rgba(255,255,255,.15);border:none;color:#fff;width:26px;height:26px;border-radius:50%;font-size:.95rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">✕</button>
    </div>

    <div style="padding:20px 22px;font-size:.83rem;color:#374151;line-height:1.85;">

      <div style="margin-bottom:16px;">
        <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#1a4f7a;margin-bottom:6px;"><i class="bi bi-info-circle-fill me-1"></i>What is ARIMA?</div>
        <p style="margin:0;">
          <strong>ARIMA</strong> stands for <strong>AutoRegressive Integrated Moving Average</strong>. It uses past monthly data to find patterns and project future values — for residents, blotter cases, and certificates issued.
        </p>
      </div>

      <div style="margin-bottom:16px;">
        <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#1a4f7a;margin-bottom:8px;"><i class="bi bi-diagram-3-fill me-1"></i>The Three Parts: ARIMA(p, d, q)</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;">
          <div style="background:#dbeafe;border-radius:8px;padding:11px;">
            <div style="font-weight:700;color:#1a4f7a;font-size:.82rem;margin-bottom:3px;"><strong>p</strong> — AutoRegressive</div>
            <div style="font-size:.77rem;color:#1e3a5f;">Uses past values to predict the next one.</div>
          </div>
          <div style="background:#dcfce7;border-radius:8px;padding:11px;">
            <div style="font-weight:700;color:#15803d;font-size:.82rem;margin-bottom:3px;"><strong>d</strong> — Integrated</div>
            <div style="font-size:.77rem;color:#14532d;">Removes trends to stabilize the data.</div>
          </div>
          <div style="background:#fef9c3;border-radius:8px;padding:11px;">
            <div style="font-weight:700;color:#854d0e;font-size:.82rem;margin-bottom:3px;"><strong>q</strong> — Moving Average</div>
            <div style="font-size:.77rem;color:#713f12;">Smooths out noise from past prediction errors.</div>
          </div>
        </div>
      </div>

      <div style="margin-bottom:16px;">
        <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#1a4f7a;margin-bottom:8px;"><i class="bi bi-graph-up me-1"></i>How to Read the Chart</div>
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

      <div style="margin-bottom:16px;">
        <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#1a4f7a;margin-bottom:8px;"><i class="bi bi-list-ol me-1"></i>How the Forecast is Generated</div>
        <ol style="padding-left:16px;margin:0;display:flex;flex-direction:column;gap:5px;font-size:.81rem;">
          <li>Monthly data is pulled from the database (last 24 months).</li>
          <li>ARIMA is fitted to the data, choosing the best p, d, q values automatically.</li>
          <li>The model projects values for the selected months ahead.</li>
          <li>If data is too sparse, a simpler trend method (Holt's smoothing) is used as fallback.</li>
        </ol>
      </div>

      <div style="background:#fef9c3;border:1px solid #fbbf24;border-radius:8px;padding:12px 14px;">
        <div style="font-size:.7rem;font-weight:700;color:#854d0e;margin-bottom:5px;"><i class="bi bi-exclamation-triangle-fill me-1"></i>Important Reminders</div>
        <ul style="margin:0;padding-left:16px;font-size:.78rem;color:#78350f;display:flex;flex-direction:column;gap:3px;">
          <li>Forecasts are based on <strong>historical patterns only</strong> — unexpected events are not accounted for.</li>
          <li>More months of history = more accurate forecast.</li>
          <li>Use forecasts as a <strong>planning guide</strong>, not guaranteed predictions.</li>
        </ul>
      </div>

    </div>

    <div style="padding:12px 20px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;">
      <button onclick="document.getElementById('arimaDocModal').style.display='none'"
        class="btn btn-sm btn-primary" style="font-size:.78rem;padding:5px 18px;border-radius:8px;">Close</button>
    </div>

  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const PROXY   = '<?= BASE_URL ?>/ajax/get_arima_forecast.php';
const BRGY_ID = <?= $bid ?>;

let mainChart = null;

// Colors per series
const SERIES_CFG = {
  residents:    { histColor: '#1a4f7a', fcColor: '#22c55e',  label: 'Residents'     },
  blotter:      { histColor: '#c62828', fcColor: '#f97316',  label: 'Blotter Cases' },
  certificates: { histColor: '#6a1b9a', fcColor: '#06b6d4',  label: 'Certificates'  },
};

document.getElementById('runBtn').addEventListener('click', runForecast);

// Auto-run on page load
runForecast();

/* ── Save Forecast Records (fire-and-forget) ──────────────── */
async function saveForecastRecords(data, type) {
  try {
    await fetch('<?= BASE_URL ?>/ajax/save_forecast.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify({
        barangay_id:     BRGY_ID,
        series_type:     type,
        forecast_months: parseInt(document.getElementById('selMonths').value),
        lookback_months: 24,
        arima_order:     (data.arima_order || []).join(','),
        engine:          data.engine ?? 'ARIMA',
        summary:         data.summary,
        forecast:        data.forecast,
        history:         data.history,
        generated_at:    data.generated_at ?? new Date().toISOString(),
      })
    });
  } catch (e) { /* silent — never block the UI */ }
}

async function runForecast() {
  const series = document.getElementById('selSeries').value;
  const months = document.getElementById('selMonths').value;
  const btn    = document.getElementById('runBtn');

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Running…';

  // Show loader, hide meta/explain
  document.getElementById('chartLoader').style.display   = 'flex';
  document.getElementById('metaRow').style.display       = 'none';
  document.getElementById('tableSection').style.display  = 'none';
  document.getElementById('explainSection').style.display = 'none';

  try {
    const res  = await fetch(`${PROXY}?type=${series}&months=${months}&barangay_id=${BRGY_ID}&lookback=24`);
    const data = await res.json();

    if (!res.ok || data.error) {
      showError('Forecast failed. Please check if the ARIMA API is running.');
      return;
    }

    const d = data.forecasts?.[series] ?? data;
    drawChart(series, d);
    drawMeta(series, d, months);
    drawTable(series, d);
    drawExplain(series, d, months);
    saveForecastRecords(d, series);

  } catch (e) {
    showError('Cannot connect to the ARIMA API. Make sure it is running.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Run Forecast';
    document.getElementById('chartLoader').style.display = 'none';
  }
}

/* ── Chart ────────────────────────────────────────────── */
function drawChart(series, d) {
  const cfg       = SERIES_CFG[series];
  const hLabels   = d.history.labels;
  const hValues   = d.history.values;
  const fLabels   = d.forecast.labels;
  const fValues   = d.forecast.values;
  const bridge    = hValues[hValues.length - 1];
  const allLabels = [...hLabels, ...fLabels];

  // Update legend colors
  const leg = document.getElementById('legendRow');
  leg.querySelectorAll('line')[0].setAttribute('stroke', cfg.histColor);
  leg.querySelectorAll('line')[1].setAttribute('stroke', cfg.fcColor);

  const histDS = {
    label: 'Actual',
    data: [...hValues, ...Array(fLabels.length).fill(null)],
    borderColor: cfg.histColor,
    backgroundColor: 'transparent',
    borderWidth: 2,
    pointRadius: 3,
    pointBackgroundColor: cfg.histColor,
    tension: 0.35,
  };

  const fcDS = {
    label: 'Forecast',
    data: [...Array(hLabels.length - 1).fill(null), bridge, ...fValues],
    borderColor: cfg.fcColor,
    backgroundColor: 'transparent',
    borderWidth: 2,
    borderDash: [6, 4],
    pointRadius: 4,
    pointBackgroundColor: cfg.fcColor,
    pointBorderColor: '#fff',
    pointBorderWidth: 1.5,
    tension: 0.35,
  };

  const ctx = document.getElementById('mainChart');
  if (mainChart) mainChart.destroy();

  mainChart = new Chart(ctx, {
    type: 'line',
    data: { labels: allLabels, datasets: [histDS, fcDS] },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          mode: 'index',
          intersect: false,
          callbacks: {
            label: c => c.parsed.y === null ? null : ` ${c.dataset.label}: ${Math.round(c.parsed.y)}`,
          },
        },
      },
      scales: {
        x: {
          ticks: { font: { size: 10 }, maxRotation: 35,
            color: (c) => c.index >= hLabels.length ? cfg.fcColor : '#9ca3af' },
          grid: { color: '#f3f4f6' },
        },
        y: {
          ticks: { font: { size: 10 }, color: '#9ca3af' },
          grid: { color: '#f3f4f6' },
          beginAtZero: true,
        },
      },
    },
  });
}

/* ── Meta row ─────────────────────────────────────────── */
function drawMeta(series, d, months) {
  const s      = d.summary;
  const dir    = s.trend_direction ?? 'stable';
  const pct    = Math.abs(s.trend_pct ?? 0);
  const engine = d.engine ?? 'ARIMA';
  const order  = (d.arima_order ?? []).join(',');
  const total  = d.forecast.values.reduce((a, b) => a + b, 0);
  const avg    = total / d.forecast.values.length;

  // Method badge
  document.getElementById('metaMethod').textContent =
    order ? `${engine}(${order})` : engine;

  document.getElementById('metaTotal').textContent =
    Math.round(total).toLocaleString();

  document.getElementById('metaAvg').textContent =
    Math.round(avg).toLocaleString();

  let trendHtml;
  if (dir === 'up') {
    trendHtml = `<span class="trend-up">↑ Increasing (${pct}%)</span>`;
  } else if (dir === 'down') {
    trendHtml = `<span class="trend-down">↓ Decreasing (${pct}%)</span>`;
  } else {
    trendHtml = `<span class="trend-stable">→ Stable</span>`;
  }
  document.getElementById('metaTrend').innerHTML = trendHtml;

  document.getElementById('metaRow').style.display = '';
}

/* ── Forecast Table ──────────────────────────────────── */
function drawTable(series, d) {
  const cfg  = SERIES_CFG[series];
  const fls  = d.forecast.labels;
  const fvs  = d.forecast.values;
  const maxV = Math.max(...fvs) || 1;

  let rows = '';
  fls.forEach((lbl, i) => {
    const v   = Math.round(fvs[i]);
    const pct = Math.min(100, Math.round((v / maxV) * 100));
    rows += `<tr>
      <td style="font-size:.75rem;color:#9ca3af;padding:6px 14px;width:30px;">${i + 1}</td>
      <td style="font-size:.80rem;font-weight:500;padding:6px 14px;">${lbl}</td>
      <td style="text-align:center;padding:6px 14px;">
        <span style="background:#1a3a5c;color:#fff;font-size:.75rem;font-weight:700;padding:3px 10px;border-radius:6px;">${v}</span>
      </td>
    </tr>`;
  });

  document.getElementById('forecastTable').innerHTML = `
    <table class="table table-sm mb-0">
      <thead style="background:#f0f4f8;">
        <tr>
          <th style="font-size:.68rem;color:#3a7fa8;padding:7px 14px;">#</th>
          <th style="font-size:.68rem;color:#3a7fa8;padding:7px 14px;">MONTH</th>
          <th style="font-size:.68rem;color:#3a7fa8;text-align:center;padding:7px 14px;">FORECAST</th>
        </tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>`;

  document.getElementById('tableSeriesLabel').textContent = cfg.label;
  document.getElementById('tableSection').style.display = '';
}

/* ── Explanation ──────────────────────────────────────── */
function drawExplain(series, d, months) {
  const s      = d.summary;
  const dir    = s.trend_direction ?? 'stable';
  const label  = SERIES_CFG[series].label;
  const engine = d.engine ?? 'ARIMA';
  const avg    = Math.round(s.avg_historical ?? 0);
  const fvs    = d.forecast.values;
  const fls    = d.forecast.labels;

  // Build one clean paragraph like the image
  let line1, line2, line3;

  if (dir === 'up') {
    line1 = `Based on the historical data, a <strong>trend-based method using ${engine}</strong> was applied for the ${label.toLowerCase()} forecast.`;
    line2 = `The forecast starts from the most recent actual value and applies a gradual <strong>upward monthly change</strong> over ${months} months.`;
  } else if (dir === 'down') {
    line1 = `Based on the historical data, a <strong>trend-based method using ${engine}</strong> was applied for the ${label.toLowerCase()} forecast.`;
    line2 = `The forecast starts from the most recent actual value and applies a gradual <strong>downward monthly change</strong> over ${months} months.`;
  } else {
    line1 = `Based on the historical data, a <strong>trend-based method using ${engine}</strong> was applied for the ${label.toLowerCase()} forecast.`;
    line2 = `The forecast starts from the most recent actual value and projects a <strong>stable trend</strong> with minimal change over ${months} months.`;
  }

  line3 = `The projection should be interpreted as an indicative estimate for planning purposes.`;

  document.getElementById('explainText').innerHTML =
    `${line1}<br>${line2}<br>${line3}`;

  document.getElementById('explainSection').style.display = '';
}

/* ── Error ────────────────────────────────────────────── */
function showError(msg) {
  document.getElementById('chartLoader').style.display = 'none';
  document.getElementById('explainText').innerHTML =
    `<span class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>${msg}</span>`;
  document.getElementById('explainSection').style.display = '';
}

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }
</script>
</body>
</html>
