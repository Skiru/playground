# C2 Final Merge Handoff and Runbook

This document provides the necessary handoff runbook for the repository operator to safely merge Pull Request #1.

## 1. Release Metrics and Identifiers

- **Repository**: `Skiru/playground`
- **Branch**: `feat/family-places-platform-v1`
- **PR Number**: `1`
- **Base Branch**: `main`
- **C2 Final Implementation SHA**: `02ec5b9a869a42d0a278115833f5bc0bccd97049`
- **C2 Final Implementation Tree**: `df2de76d9975e6042c7e02b6bd7c8e1f8e523303`

## 2. Operator Verification Steps (Runbook)

Before merging PR #1, the operator must execute the following verifications:

### Step 2.1: Check Remote Head and Alignment
Verify that the remote repository branch matches our certified SHA:
```fish
git fetch origin
git ls-remote origin refs/heads/feat/family-places-platform-v1
```
Expected output:
`02ec5b9a869a42d0a278115833f5bc0bccd97049  refs/heads/feat/family-places-platform-v1`

### Step 2.2: Verify Branch Protection and Required Status Checks
Since the local environment cannot modify branch protection rules without direct administrative access via GitHub CLI, the operator must manually verify:
1. **Force Push** is disabled on `main`.
2. **Branch Deletion** is disabled on `main`.
3. **Required Status Checks** are enabled for PR merges on `main`.
4. All 6 remote checks on the final implementation SHA are **PASS** (green).

## 3. Merging the Pull Request

To preserve history integrity without squash-merging or rebase rewrites (which are forbidden), the PR must be merged using a standard merge commit or fast-forward:

```fish
# Fetch and checkout main
git checkout main
git pull origin main

# Merge branch with custom merge commit
git merge --no-ff feat/family-places-platform-v1 -m "merge: release FamilyPlaces platform C2 production milestone"

# Push to origin main
git push origin main
```

## 4. Launching the Production Topology

After merging, to run the certified production containers:

### Step 4.1: Setup Environment Secrets
Create a `.env` file containing required production variables (do not commit this):
```ini
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=your_long_production_app_secret
DATABASE_URL=postgresql://family_places:db_password@database:5432/family_places?serverVersion=18&charset=utf8
POSTGRES_DB=family_places
POSTGRES_USER=family_places
POSTGRES_PASSWORD=db_password
MAP_STYLE_URL=http://your-style-url/style.json
MAP_ATTRIBUTION=your-map-attribution
MAP_PROVIDER_NAME=your-provider-name
```

### Step 4.2: Build once and Start Services
```fish
docker compose -f compose.yaml -f compose.prod.yaml build api web worker
docker compose -f compose.yaml -f compose.prod.yaml up -d database
# Wait 10s for database to start
docker compose -f compose.yaml -f compose.prod.yaml run --rm api php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f compose.yaml -f compose.prod.yaml up -d api web worker
```

## 5. Rollback Runbook

If any issues arise in production:
1. Roll back immediately to the previous stable release images using their immutable digests.
2. If database schema was backward-incompatible (not applicable to C2 Final), execute the recovery restore drill using the latest backup:
```fish
set -x BACKUP_PASSPHRASE "your-backup-phrase"
./scripts/restore /tmp/family-places-backups/backup-YOUR-TIMESTAMP.dump.enc
```
3. Restart production services.
