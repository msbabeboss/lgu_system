-- ═══════════════════════════════════════════════════════════
--  forecast_records  –  persists each ARIMA run result
--  Run once against lgu_system database
-- ═══════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS forecast_records (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    barangay_id     INT UNSIGNED NULL DEFAULT NULL,
    series_type     ENUM('residents','blotter','certificates') NOT NULL,
    forecast_months TINYINT UNSIGNED NOT NULL DEFAULT 6,
    lookback_months TINYINT UNSIGNED NOT NULL DEFAULT 24,
    arima_order     VARCHAR(20)  NULL COMMENT 'e.g. 1,1,1',
    engine          VARCHAR(20)  NULL COMMENT 'ARIMA or Holt',
    avg_historical  DECIMAL(10,2) NULL,
    avg_forecast    DECIMAL(10,2) NULL,
    trend_pct       DECIMAL(8,2)  NULL,
    trend_direction ENUM('up','down','stable') NULL,
    data_quality    ENUM('good','fair','sparse') NULL,
    forecast_json   JSON         NULL COMMENT 'forecast:{labels,values,lower_ci,upper_ci}',
    history_json    JSON         NULL COMMENT 'history:{labels,values}',
    generated_at    DATETIME     NOT NULL,
    saved_by        INT UNSIGNED NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangays(id),
    FOREIGN KEY (saved_by)    REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_fc_brgy_series (barangay_id, series_type, generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Apply to existing table if already created
ALTER TABLE forecast_records MODIFY COLUMN barangay_id INT UNSIGNED NULL DEFAULT NULL;
