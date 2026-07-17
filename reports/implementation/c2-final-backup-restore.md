# Backup and Restore Contract and Restore Drill Verification

This document certifies that a fully functional backup and restore capability has been implemented, utwardzony, and validated using an automated restore drill.

## Executed Rehearsals

### 1. Fresh Install Rehearsal
- **Scenarios**: Verified complete installation starting from an empty volume.
- **Commands**:
  - `docker compose -f compose.yaml -f compose.test.yaml build api`
  - `doctrine:database:create --if-not-exists`
  - `doctrine:migrations:migrate`
  - `doctrine:fixtures:load`
- **Result**: `PASS` (Database schema fully initialized and synchronized, all 86 backend tests passed).

### 2. Upgrade Rehearsal
- **Scenario**: Simulated deployment upgrade from the previous certified release `773df803985f3203e4c0a5c39856b12b0cbac387`.
- **Integrity**: Retained 100% of data (21 places), schema remained in sync, and idempotency check passed with zero modifications required.
- **Result**: `PASS`.

## Backup and Restore Scripts

The following scripts have been created under `scripts/`:
1. `scripts/backup`: Performs an encrypted custom-format binary dump of the PostgreSQL database, computes SHA256 checksums, and creates a JSON metadata manifest.
2. `scripts/restore`: Validates backup file checksums, handles symmetric decryption, disconnects active database sessions, and restores the dump.
3. `scripts/backup-verify`: Performs independent validation of the backup archive, its SHA256 signature, and corresponding metadata.
4. `scripts/restore-drill`: Coordinates the entire testing sequence: seeds data, runs backup, corrupts backup to test rejection, wipes database, restores, and verifies data integrity.

## Restore Drill Checklist

| Verification Step | Command / Evidence | Status |
|---|---|---|
| Seeding Data | loaded 15 places fixtures | `PASS` |
| Encrypted Backup | OpenSSL symmetric AES-256-CBC | `PASS` |
| Backup Verification | `sha256sum -c` checksum match | `PASS` |
| Corrupt Rejection | Malformed SHA256 value rejected with exit code 1 | `PASS` |
| Database Destruction | Dropped schema, verified 0 tables remain | `PASS` |
| Restore Execution | `pg_restore` restored all schema and data | `PASS` |
| Post-Restore Status | `doctrine:migrations:status` (5 executed, 0 new) | `PASS` |
| Post-Restore Validation | `doctrine:schema:validate` (Mapping & DB in sync) | `PASS` |
| Post-Restore Integrity | 15 places restored, PostGIS version query successful | `PASS` |

## Disaster Recovery and Restore Commands

To run a manual restore drill or execution:

### Backup Command
```fish
set -x BACKUP_PASSPHRASE "your-secure-phrase"
./scripts/backup
```

### Restore Command
```fish
set -x BACKUP_PASSPHRASE "your-secure-phrase"
./scripts/restore /tmp/family-places-backups/backup-YOUR-TIMESTAMP.dump.enc
```
