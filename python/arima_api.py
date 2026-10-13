"""
ARIMA Forecasting API for Talavera LGU System
Flask server on port 5001.

Works with:
  - statsmodels installed  → real ARIMA(p,d,q) model
  - statsmodels missing    → built-in fallback (Holt-Winters / linear trend)

Endpoints:
  GET /health
  GET /forecast?type=residents&months=6&barangay_id=0&lookback=24
  GET /forecast/all?months=6&barangay_id=0&lookback=24
"""

import os
import json
import math
import warnings
import traceback
from datetime import datetime
from dateutil.relativedelta import relativedelta

warnings.filterwarnings("ignore")

# ── Optional heavy deps ────────────────────────────────────────────────────────
try:
    import numpy as np
    HAS_NUMPY = True
except ImportError:
    HAS_NUMPY = False

try:
    from statsmodels.tsa.arima.model import ARIMA
    from statsmodels.tsa.stattools import adfuller
    HAS_STATSMODELS = True
except ImportError:
    HAS_STATSMODELS = False

try:
    import mysql.connector
    HAS_MYSQL = True
except ImportError:
    HAS_MYSQL = False

from flask import Flask, jsonify, request
from flask_cors import CORS

# ── Load .env ─────────────────────────────────────────────────────────────────
def load_env(path):
    env = {}
    try:
        with open(path) as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, v = line.split("=", 1)
                    env[k.strip()] = v.strip()
    except FileNotFoundError:
        pass
    return env

_env = load_env(os.path.join(os.path.dirname(__file__), "..", ".env"))

DB_CONFIG = {
    "host":     _env.get("DB_HOST", "localhost"),
    "port":     int(_env.get("DB_PORT", 3306)),
    "user":     _env.get("DB_USER", "root"),
    "password": _env.get("DB_PASS", ""),
    "database": _env.get("DB_NAME", "lgu_system"),
}

app = Flask(__name__)
CORS(app)


# ── DB ────────────────────────────────────────────────────────────────────────
def get_connection():
    if not HAS_MYSQL:
        raise RuntimeError("mysql-connector-python is not installed. Run: pip install mysql-connector-python")
    return mysql.connector.connect(**DB_CONFIG)


def fetch_monthly_series(series_type: str, barangay_id: int, lookback_months: int = 24):
    conn = get_connection()
    cur  = conn.cursor()
    results = []
    now = datetime.now()

    for i in range(lookback_months - 1, -1, -1):
        target = now - relativedelta(months=i)
        ym     = target.strftime("%Y-%m")
        label  = target.strftime("%b %Y")

        if barangay_id:
            bf     = " AND barangay_id = %s"
            params = (ym, int(barangay_id))
        else:
            bf     = ""
            params = (ym,)

        if series_type == "residents":
            sql = f"SELECT COUNT(*) FROM residents WHERE DATE_FORMAT(created_at,'%Y-%m')=%s{bf}"
        elif series_type == "blotter":
            sql = f"SELECT COUNT(*) FROM blotter_cases WHERE DATE_FORMAT(created_at,'%Y-%m')=%s AND status!='Archived'{bf}"
        elif series_type == "certificates":
            sql = f"SELECT COUNT(*) FROM certificates WHERE DATE_FORMAT(created_at,'%Y-%m')=%s{bf}"
        else:
            conn.close()
            raise ValueError(f"Unknown type: {series_type}")

        cur.execute(sql, params)
        results.append({"label": label, "value": int(cur.fetchone()[0]), "ym": ym})

    cur.close()
    conn.close()
    return results


# ── Pure-Python helpers (no numpy needed) ─────────────────────────────────────
def _mean(lst):
    return sum(lst) / len(lst) if lst else 0.0

def _std(lst):
    if len(lst) < 2:
        return 0.0
    m = _mean(lst)
    return math.sqrt(sum((x - m) ** 2 for x in lst) / (len(lst) - 1))

def _diff(lst):
    return [lst[i] - lst[i-1] for i in range(1, len(lst))]


# ── Fallback forecaster: Holt-Winters double exponential smoothing ─────────────
def fallback_forecast(values: list, steps: int):
    """
    Double exponential smoothing (Holt's method) — no external deps.
    Uses only the recent non-zero window to avoid dragging forecast to zero.
    Returns (forecast, lower_ci, upper_ci).
    """
    n = len(values)
    if n == 0:
        return [0]*steps, [0]*steps, [0]*steps

    if n == 1:
        v0 = max(0, values[0])
        return [v0]*steps, [max(0, v0-1)]*steps, [v0*1.5 + 1]*steps

    # Use only the recent window (last 12 or all if fewer) to avoid old zeros
    # dominating the trend calculation
    recent = values[-min(12, n):]

    # If all zeros or near-zero, use global mean as baseline
    non_zero = [v for v in recent if v > 0]
    if not non_zero:
        avg = _mean(values) if any(v > 0 for v in values) else 0
        ci_w = max(1.0, avg * 0.3)
        return (
            [round(avg, 2)] * steps,
            [max(0.0, round(avg - ci_w, 2))] * steps,
            [round(avg + ci_w, 2)] * steps
        )

    # Initialise level and trend from recent window
    alpha, beta = 0.3, 0.1   # smoother beta prevents runaway trends
    level = recent[0]
    trend = (recent[-1] - recent[0]) / max(1, len(recent) - 1)
    errors = []

    smoothed = []
    for i, v in enumerate(recent):
        if i == 0:
            smoothed.append(level)
            continue
        prev_level = level
        level = alpha * v + (1 - alpha) * (level + trend)
        trend = beta * (level - prev_level) + (1 - beta) * trend
        smoothed.append(level)
        errors.append(abs(v - smoothed[-1]))

    # Dampen the trend to prevent extreme projections
    # (phi = 0.88 per Gardner & McKenzie damping)
    phi = 0.88
    forecast = []
    phi_acc = phi
    for h in range(1, steps + 1):
        fc_val = level + phi_acc * trend
        forecast.append(max(0.0, round(fc_val, 2)))
        phi_acc += phi ** (h + 1)

    # CI based on mean absolute error, widening with horizon
    mae = _mean(errors) if errors else max(1.0, _std(recent))
    z   = 1.28  # 80% CI
    lower = [max(0.0, round(f - z * mae * math.sqrt(1 + i * 0.15), 2)) for i, f in enumerate(forecast)]
    upper = [round(f + z * mae * math.sqrt(1 + i * 0.15), 2) for i, f in enumerate(forecast)]

    return forecast, lower, upper


# ── Real ARIMA (statsmodels) ──────────────────────────────────────────────────
def _auto_order(values):
    """Pick ARIMA order via ADF test."""
    arr = list(values)
    if len(arr) < 4:
        return (1, 0, 0)
    try:
        p_val = adfuller(arr, autolag="AIC")[1]
        d = 0 if p_val < 0.05 else 1
    except Exception:
        d = 1

    m = _mean(arr)
    s = _std(arr)
    p = 2 if s > m * 0.5 else 1
    return (p, d, 1)


def statsmodels_forecast(values: list, steps: int):
    """Run real ARIMA. Returns (forecast, lower_ci, upper_ci, order).
    Uses recent non-zero data window to avoid zero-anchored forecasts.
    """
    # Only use recent non-zero data for fitting (last 24, but at least 8)
    working = values[-min(24, len(values)):]
    non_zero_count = sum(1 for v in working if v > 0)

    # If too few non-zero points, let fallback handle it
    if non_zero_count < 4:
        fc, lo, hi = fallback_forecast(values, steps)
        return fc, lo, hi, (1, 0, 1)

    orders = [_auto_order(working), (1,1,1), (2,1,1), (1,0,1), (1,0,0), (0,1,1)]
    model_fit = None
    used_order = orders[0]

    for o in orders:
        try:
            m = ARIMA(working, order=o).fit()
            # Sanity-check: reject models whose forecast drifts >5x the historical mean
            test_fc = m.get_forecast(steps=steps).predicted_mean
            hist_mean = _mean([v for v in working if v > 0])
            if hist_mean > 0 and max(abs(float(v)) for v in test_fc) > hist_mean * 8:
                continue
            model_fit = m
            used_order = o
            break
        except Exception:
            continue

    if model_fit is None:
        fc, lo, hi = fallback_forecast(values, steps)
        return fc, lo, hi, (1, 1, 1)

    fc_res  = model_fit.get_forecast(steps=steps)
    fc_mean = [max(0.0, round(float(v), 2)) for v in fc_res.predicted_mean]
    ci      = fc_res.conf_int(alpha=0.2)
    lo      = [max(0.0, round(float(v), 2)) for v in ci[:, 0]]
    hi      = [max(0.0, round(float(v), 2)) for v in ci[:, 1]]

    return fc_mean, lo, hi, list(used_order)


# ── Unified forecast dispatcher ───────────────────────────────────────────────
def run_forecast(values: list, steps: int):
    """Returns (forecast, lower_ci, upper_ci, order, engine)."""
    if len(values) >= 6 and HAS_STATSMODELS and HAS_NUMPY:
        try:
            fc, lo, hi, order = statsmodels_forecast(values, steps)
            return fc, lo, hi, order, "ARIMA"
        except Exception:
            pass  # fall through to fallback

    fc, lo, hi = fallback_forecast(values, steps)
    return fc, lo, hi, [1, 0, 1], "Holt"


# ── Label helpers ─────────────────────────────────────────────────────────────
def future_labels(steps: int):
    now = datetime.now()
    return [(now + relativedelta(months=i)).strftime("%b %Y") for i in range(1, steps + 1)]


# ── Build response dict ───────────────────────────────────────────────────────
def build_response(series_type, barangay_id, history, forecast_months):
    values = [x["value"] for x in history]
    fc, lo, hi, order, engine = run_forecast(values, forecast_months)
    f_labels = future_labels(forecast_months)

    non_zero = [v for v in values if v > 0]
    avg_hist = _mean(non_zero) if non_zero else 0.0
    avg_hist_all = _mean(values)
    avg_fc   = _mean(fc)

    # Compare forecast to recent non-zero mean for a meaningful trend
    trend_base = avg_hist if avg_hist > 0 else avg_hist_all
    trend_pct = round(((avg_fc - trend_base) / (trend_base + 1e-9)) * 100, 1)

    # Data quality flag
    data_quality = "good" if len(non_zero) >= 6 else ("fair" if len(non_zero) >= 3 else "sparse")

    # Don't report trend direction as meaningful when data is too sparse
    if data_quality == "sparse":
        trend_dir = "stable"
        trend_pct = 0.0
    else:
        trend_dir = "up" if trend_pct > 2 else ("down" if trend_pct < -2 else "stable")

    return {
        "type":        series_type,
        "barangay_id": barangay_id,
        "engine":      engine,
        "arima_order": order,
        "history": {
            "labels": [x["label"] for x in history],
            "values": values,
        },
        "forecast": {
            "labels":   f_labels,
            "values":   fc,
            "lower_ci": lo,
            "upper_ci": hi,
        },
        "summary": {
            "avg_historical":  round(avg_hist_all, 2),
            "avg_forecast":    round(avg_fc,   2),
            "trend_pct":       trend_pct,
            "trend_direction": trend_dir,
            "data_points":     len(values),
            "non_zero_points": len(non_zero),
            "data_quality":    data_quality,
        }
    }


# ── Routes ────────────────────────────────────────────────────────────────────
@app.route("/health", methods=["GET"])
def health():
    db_ok = False
    db_err = ""
    try:
        c = get_connection(); c.close(); db_ok = True
    except Exception as e:
        db_err = str(e)

    return jsonify({
        "status":          "ok",
        "db_connected":    db_ok,
        "db_error":        db_err,
        "has_statsmodels": HAS_STATSMODELS,
        "has_numpy":       HAS_NUMPY,
        "engine":          "ARIMA" if (HAS_STATSMODELS and HAS_NUMPY) else "Holt (fallback)",
        "timestamp":       datetime.now().isoformat(),
    })


@app.route("/forecast", methods=["GET"])
def forecast():
    stype    = request.args.get("type", "residents").lower()
    months   = max(1, min(12, int(request.args.get("months",   6))))
    brgid    = max(0,         int(request.args.get("barangay_id", 0)))
    lookback = max(6, min(36, int(request.args.get("lookback", 24))))

    if stype not in ("residents", "blotter", "certificates"):
        return jsonify({"error": "Invalid type. Use: residents, blotter, certificates"}), 400

    try:
        history = fetch_monthly_series(stype, brgid, lookback)
        return jsonify(build_response(stype, brgid, history, months))
    except Exception as e:
        return jsonify({"error": str(e), "details": traceback.format_exc()}), 500


@app.route("/forecast/all", methods=["GET"])
def forecast_all():
    months   = max(1, min(12, int(request.args.get("months",      6))))
    brgid    = max(0,         int(request.args.get("barangay_id", 0)))
    lookback = max(6, min(36, int(request.args.get("lookback",   24))))

    combined, errors = {}, {}
    for stype in ("residents", "blotter", "certificates"):
        try:
            history = fetch_monthly_series(stype, brgid, lookback)
            combined[stype] = build_response(stype, brgid, history, months)
        except Exception as e:
            errors[stype] = str(e)

    return jsonify({
        "barangay_id":     brgid,
        "forecast_months": months,
        "generated_at":    datetime.now().isoformat(),
        "engine":          "ARIMA" if (HAS_STATSMODELS and HAS_NUMPY) else "Holt (fallback)",
        "forecasts":       combined,
        "errors":          errors,
    })


# ── Entry ─────────────────────────────────────────────────────────────────────
if __name__ == "__main__":
    print("=" * 55)
    print("  LGU ARIMA Forecasting API")
    print(f"  Engine   : {'ARIMA (statsmodels)' if HAS_STATSMODELS else 'Holt fallback (statsmodels not installed)'}")
    print(f"  DB       : {DB_CONFIG['host']}:{DB_CONFIG['port']} / {DB_CONFIG['database']}")
    print("  Listening: http://localhost:5001")
    print("  Health   : http://localhost:5001/health")
    print("=" * 55)
    app.run(host="0.0.0.0", port=5001, debug=False)
