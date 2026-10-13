-- =============================================================
-- Dashboard Performance Indexes
-- Run once against the lgu_system database.
-- Uses ALTER TABLE ... ADD INDEX with IF NOT EXISTS (MySQL 8.0+).
-- On MySQL 5.7 the duplicate-key error is safe to ignore.
-- =============================================================

-- 1. residents: primary filter used in almost every dashboard query
ALTER TABLE residents ADD INDEX IF NOT EXISTS idx_res_brgy_status (barangay_id, resident_status);

-- 2. residents: employment counts (occupation_status GROUP BY)
ALTER TABLE residents ADD INDEX IF NOT EXISTS idx_res_brgy_occ_status (barangay_id, occupation_status, resident_status);

-- 3. residents: population trend GROUP BY DATE_FORMAT(created_at,...)
ALTER TABLE residents ADD INDEX IF NOT EXISTS idx_res_brgy_created (barangay_id, created_at);

-- 4. blotter_cases: blotter trend
ALTER TABLE blotter_cases ADD INDEX IF NOT EXISTS idx_blotter_brgy_created (barangay_id, created_at);

-- 5. blotter_cases: status counts
ALTER TABLE blotter_cases ADD INDEX IF NOT EXISTS idx_blotter_brgy_status (barangay_id, status);

-- 6. households: active household count
ALTER TABLE households ADD INDEX IF NOT EXISTS idx_hh_brgy_status (barangay_id, status);

-- 7. certificates: cert counts per barangay
ALTER TABLE certificates ADD INDEX IF NOT EXISTS idx_cert_brgy (barangay_id);

-- 8. user_requests: request counts and pending lookups
ALTER TABLE user_requests ADD INDEX IF NOT EXISTS idx_req_brgy_status_type (barangay_id, status, request_type);
