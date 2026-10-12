# LGU ARIMA Forecasting API

Python Flask microservice that runs ARIMA models against the LGU MySQL database
and serves forecasts as JSON to the PHP dashboard.

## Requirements

- Python 3.10 or newer
- All packages in `requirements.txt`

## Setup (one time)

1. **Install Python**
   Download from https://www.python.org/downloads/
   During install: ✅ check "Add Python to PATH"

2. **Install dependencies**
   Open CMD in this folder and run:
   ```
   pip install -r requirements.txt
   ```

3. **Start the API**
   Double-click `start_api.bat`
   — or run in CMD:
   ```
   python arima_api.py
   ```
   The API will start on http://localhost:5001

4. **Verify it works**
   Open in browser: http://localhost:5001/health
   You should see: `{"status": "ok", "db_connected": true, ...}`

## API Endpoints

| Endpoint | Description |
|---|---|
| `GET /health` | Health check + DB status |
| `GET /forecast?type=residents&months=6&barangay_id=0` | Single series forecast |
| `GET /forecast?type=blotter&months=6` | Blotter cases forecast |
| `GET /forecast?type=certificates&months=6` | Certificates forecast |
| `GET /forecast/all?months=6&barangay_id=0` | All 3 series in one call |

### Parameters

| Param | Default | Description |
|---|---|---|
| `type` | — | `residents`, `blotter`, or `certificates` |
| `months` | 6 | Forecast steps (1–12) |
| `barangay_id` | 0 | Filter by barangay (0 = all) |
| `lookback` | 24 | How many months of history to use (6–36) |

## How it Works

1. PHP dashboard loads → JS calls `/ajax/get_arima_forecast.php`
2. PHP proxy forwards request to `localhost:5001/forecast/all`
3. Python fetches 24 months of monthly counts from MySQL
4. Runs ADF stationarity test to pick differencing order `d`
5. Fits ARIMA(p,d,q) — tries fallbacks if fit fails
6. Returns forecast values + 80% confidence intervals
7. JS renders history + forecast on Chart.js with CI band shading

## Notes

- The API reads DB credentials from `../.env` automatically
- No data leaves your local machine — all processing is on-device
- Keep `start_api.bat` running whenever you use the dashboard forecasting
- Port 5001 must be free; change in `arima_api.py` if needed
