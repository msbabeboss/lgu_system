# Archive Feature Setup Guide

## Prerequisites
- MySQL/MariaDB access with ALTER TABLE permissions
- Database backup (recommended before running migrations)
- phpMyAdmin or command line access

## Quick Setup (Automated)

### Option 1: Using MySQL Command Line
```bash
cd c:\xampp\htdocs\lgu_system\database

# Login to MySQL
mysql -u root -p

# Select database
USE lgu_system;

# Run migration script
source migrate_archive_schema.sql;

# Verify changes
SHOW COLUMNS FROM households;
SHOW COLUMNS FROM blotter_cases;
SHOW COLUMNS FROM notices;
```

### Option 2: Using phpMyAdmin
1. Open phpMyAdmin in browser
2. Select `lgu_system` database
3. Click SQL tab
4. Copy entire content of `migrate_archive_schema.sql`
5. Paste into SQL editor
6. Click Execute

### Option 3: Manual via PHP
The system will auto-detect missing columns on first use and apply them automatically.

## Verification Steps

After running the migration, verify all changes:

### Check Households Table
```sql
SHOW COLUMNS FROM households;
-- Should show: status, archive_reason columns
-- Status should have ENUM values: Active, Inactive, Archived
```

### Check Blotter Cases Table
```sql
SHOW COLUMNS FROM blotter_cases;
-- Should show: status (updated), archive_reason columns
-- Status enum should include: Archived
```

### Check Notices Table
```sql
SHOW COLUMNS FROM notices;
-- Should show: status, expiry_date columns
```

### Check Indexes
```sql
SHOW INDEX FROM households;
SHOW INDEX FROM blotter_cases;
SHOW INDEX FROM notices;
-- Should show idx_*_status indexes
```

## Rollback (If Needed)

If something goes wrong, you can rollback:

```sql
-- Remove archive-related columns
ALTER TABLE households DROP COLUMN status;
ALTER TABLE households DROP COLUMN archive_reason;
ALTER TABLE blotter_cases DROP COLUMN archive_reason;
ALTER TABLE notices DROP COLUMN status;
ALTER TABLE notices DROP COLUMN expiry_date;

-- Remove indexes
DROP INDEX idx_households_status ON households;
DROP INDEX idx_blotter_status ON blotter_cases;
DROP INDEX idx_notices_status ON notices;
```

## What Gets Changed

### Before
- Households: No status tracking
- Blotter Cases: Limited status options (no Archived)
- Notices: No status or expiry

### After
- Households: Track Active/Inactive/Archived with reason
- Blotter Cases: Archived status for closed cases
- Notices: Track Active/Expired/Archived with expiry date

## Testing the Archive Feature

1. **Log in** to barangay admin account
2. **Navigate** to Household Management
3. **Find** a household to test
4. **Click** Archive button
5. **Select** archive reason
6. **Confirm** archive action
7. **Verify** household disappears from active list
8. **Go to** Archive page
9. **Find** archived household
10. **Click** Restore and verify it reappears

## Support & Troubleshooting

### Column Already Exists Error
- This is normal if running migration multiple times
- Modify the migration script to use `ADD COLUMN IF NOT EXISTS` syntax (MariaDB 10.3+)

### Permission Denied Error
- Ensure your MySQL user has ALTER TABLE permissions
- Contact database administrator

### Performance Issues After Migration
- Rebuild indexes: `OPTIMIZE TABLE households, blotter_cases, notices;`
- This is normal for large databases

## Data Safety

✅ All existing data is preserved
✅ New columns have default values (no data loss)
✅ Archives table already existed in schema
✅ Restoration is non-destructive

## Next Steps

1. Train barangay admins on new archive feature
2. Review ARCHIVE_FEATURE.md documentation
3. Set up regular archive reviews
4. Monitor activity logs for archive operations
5. Plan for permanent deletion policies

## Questions?

- Check ARCHIVE_FEATURE.md for feature documentation
- Review activity_logs table for what happened
- Check archives table for backed-up data
- Contact system administrator for support
