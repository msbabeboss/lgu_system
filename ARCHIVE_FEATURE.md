# Archive Feature Implementation for Barangay Admin System

## Overview
This document describes the comprehensive archive feature implementation for the LGU System, specifically for barangay administrators. The archive feature allows safe preservation of records instead of permanent deletion, maintaining audit trails while keeping active data clean.

## What Can Be Archived

### 1. **Households**
- **When to Archive**: 
  - Entire family relocated/moved out
  - All household members deceased
  - Duplicate household records
  - Merged with another household
  - Household becomes inactive
- **Archive Reasons** (dropdown):
  - Relocated / Moved Out
  - Deceased (All Members)
  - Duplicate Record
  - Merged Household
  - Inactive
- **How to Archive**: Click archive button on household row → Select reason → Confirm
- **How to Restore**: Go to Archive page → Find household → Click Restore

### 2. **Blotter Cases (Incident Records)**
- **When to Archive**: When a case is closed/resolved
- **Status Flow**: Open → Under Investigation → Settled/Escalated/Closed → Archived (optional)
- **Archive Button**: Appears only on cases with "Closed" status
- **How to Archive**: Mark case as "Closed" → Click archive button → Confirm
- **How to Restore**: Go to Archive page → Find case → Click Restore (reverts to "Closed" status)

### 3. **Residents** (Already Implemented)
- Status: Active, Inactive, Deceased, Transferred
- Archive button available on resident list

### 4. **Officials** (Already Implemented)
- Status: Active or Inactive
- Archive button available on officials page

## Database Schema Changes

### 1. Households Table
```sql
ALTER TABLE households ADD COLUMN status ENUM('Active','Inactive','Archived') DEFAULT 'Active';
ALTER TABLE households ADD COLUMN archive_reason VARCHAR(100);
CREATE INDEX idx_households_status ON households(barangay_id, status);
```

### 2. Blotter Cases Table
```sql
ALTER TABLE blotter_cases MODIFY status ENUM('Open','Under Investigation','Settled','Escalated','Closed','Archived') DEFAULT 'Open';
ALTER TABLE blotter_cases ADD COLUMN archive_reason VARCHAR(100);
CREATE INDEX idx_blotter_status ON blotter_cases(barangay_id, status);
```

### 3. Notices Table (Ready for Implementation)
```sql
ALTER TABLE notices ADD COLUMN status ENUM('Active','Expired','Archived') DEFAULT 'Active';
ALTER TABLE notices ADD COLUMN expiry_date DATE;
CREATE INDEX idx_notices_status ON notices(status);
```

### 4. Archives Table (Already Existed)
```sql
CREATE TABLE archives (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(100) NOT NULL,
    record_id INT UNSIGNED NOT NULL,
    data_json LONGTEXT NOT NULL,  -- Full record data preserved as JSON
    archived_by INT UNSIGNED,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
);
```

## Features

### Archive Functionality
1. **Safe Archiving**: Records are never deleted, only marked as archived
2. **Full Data Preservation**: Complete record data stored in JSON format
3. **Audit Trail**: Records who archived and when
4. **Reason Tracking**: Archive reason stored for compliance
5. **Easy Restoration**: One-click restoration to previous state
6. **Permanent Deletion**: Optional permanent deletion after archiving (with confirmation)

### Views & Queries
- **Households Page**: Shows only "Active" households (excludes "Archived")
- **Blotter Page**: Shows only non-"Archived" cases
- **Archive Page**: Central location to view, restore, or permanently delete archived records

### Activity Logging
All archive operations are logged:
- `ARCHIVE_HOUSEHOLD` / `ARCHIVE_BLOTTER_CASES` / `ARCHIVE_RESIDENT` / `ARCHIVE_OFFICIAL`
- `RESTORE_ARCHIVE`
- `PERMANENT_DELETE`

## How to Use

### For Barangay Admins

#### Archiving a Household
1. Go to **Household Management**
2. Find the household you want to archive
3. Click the **Archive** button (trash icon)
4. A modal appears asking for the archive reason
5. Select a reason from the dropdown
6. Click **Archive** to confirm
7. Household is moved to archive (no longer appears in active list)

#### Archiving a Blotter Case
1. Go to **Blotter Management**
2. Find a case with status "Closed"
3. Click the **Archive** button (archive icon) in the actions column
4. Confirm the archive action
5. Case is moved to archive (no longer appears in active list)

#### Viewing Archived Records
1. Go to **Archive** page
2. Browse all archived records across all types:
   - Residents
   - Officials
   - Households
   - Blotter Cases
3. Filter by record type if needed
4. View archive reason for each record

#### Restoring an Archived Record
1. Go to **Archive** page
2. Find the record you want to restore
3. Click **Restore** button
4. Confirm the restoration
5. Record is restored to its previous status:
   - Households: Reverts to "Active"
   - Blotter Cases: Reverts to "Closed"
   - Residents: Reverts to "Active"
   - Officials: Reverts to "active"

#### Permanently Deleting an Archived Record
1. Go to **Archive** page
2. Find the record you want to delete permanently
3. Click **Delete** button
4. Confirm the deletion (cannot be undone)
5. Record is permanently removed from archive

## Best Practices

✅ **DO:**
- Archive households when all members move out or pass away
- Archive blotter cases when fully resolved and documented
- Archive instead of deleting for compliance and audit
- Use appropriate archive reasons
- Regularly review archived records

❌ **DON'T:**
- Delete records directly - use archive first
- Archive active households with living residents
- Archive blotter cases that are still under investigation
- Use archive for temporary data cleanup without reason

## Implementation Details

### PHP Files Modified
1. **barangay_admin/households.php**: Added archive action and UI
2. **barangay_admin/blotter.php**: Added archive action for closed cases
3. **barangay_admin/archive.php**: Enhanced to support households and blotter cases
4. **helpers/functions.php**: Added `archiveRecord()` helper function
5. **database/lgu_system.sql**: Updated schema with new columns

### Helper Function
```php
function archiveRecord(int $userId, string $tableName, int $recordId, array $recordData, ?string $archiveReason = null): bool {
    // Archives record to archives table
    // Logs the activity
    // Returns true on success
}
```

### Archive Reasons for Households
- Relocated / Moved Out
- Deceased (All Members)
- Duplicate Record
- Merged Household
- Inactive

## Compliance & Audit

All archive operations are tracked:
- **Activity Logs**: User, action, timestamp, description
- **Archives Table**: Complete record backup in JSON
- **Status Fields**: Track record lifecycle (Active → Archived)
- **Archive Reason**: Business reason for archiving

## Migration Steps

To apply this feature to an existing system:

1. **Run Migration Script**:
   ```bash
   mysql -u [user] -p [database] < database/migrate_archive_schema.sql
   ```

2. **Or Execute Manually** (if unable to run script):
   - Add `status` column to households, blotter_cases, notices
   - Add `archive_reason` column to households, blotter_cases
   - Update status enum for blotter_cases
   - Update notices to include status and expiry_date
   - Create indexes for performance

3. **Verify**: Check that all columns are added without errors

## Future Enhancements

- Notices archive (expired announcements)
- Auto-archive old closed cases after X days
- Archive analytics and reporting
- Bulk archive operations
- Archive search and export
- Archive retention policies

## Support

For issues or questions about the archive feature:
1. Check activity logs for what happened
2. Review audit trail in archives table
3. Use restore function to undo accidental archives
4. Contact system administrator for permanent deletions
