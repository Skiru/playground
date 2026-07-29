# Free ARM64 production runbook

## Blocking first-release actions

MANUAL OWNER ACTION: create an Oracle Cloud Frankfurt `familyplaces-prod` compartment and an Ubuntu ARM64 `VM.Standard.A1.Flex` instance with 2 OCPU, 12 GB RAM, and a 100 GB boot volume. Restrict SSH to operator addresses. Do not open application, database, or Redis ports. Cloudflare Tunnel is the only application ingress.

MANUAL OWNER ACTION: onboard `playground.com.pl` in Cloudflare and migrate nameservers. Create a remotely managed tunnel with public hostnames `playground.com.pl` and `www.playground.com.pl` targeting `http://gateway:8080`. Put its token only in `/srv/familyplaces/.env.production` mode `0600`.

MANUAL OWNER ACTION: enable R2, create private `familyplaces-media-prod` and `familyplaces-backups-prod` buckets, and issue separate least-privilege tokens. Bind `media.playground.com.pl` as the R2 media bucket custom domain. Store the backup age private identity offline; commit and deploy only its public recipient.

MANUAL OWNER ACTION: create the Google production OAuth web client, configure consent branding, and add `https://playground.com.pl` and `https://www.playground.com.pl` as JavaScript origins. Set the client ID in both Google variables.

AUTOMATED BY REPOSITORY: run `sudo scripts/production/bootstrap-host`, clone the released branch into `/srv/familyplaces`, populate `.env.production` from `.env.production.example`, then run `scripts/production/preflight` and `scripts/production/deploy`.

## Operations

Run daily `scripts/production/backup daily`, retain seven daily, four weekly, and three pre-deployment objects using bucket lifecycle rules or the scheduled retention job. Restore only to a fresh database with `AGE_IDENTITY_FILE=/secure/offline/key scripts/production/restore postgres/...age`. Rehearse this on a new VM before declaring disaster recovery ready.

Use `scripts/production/status`, `scripts/production/logs`, and `scripts/production/prune-images` for routine operation. Roll back only using a recorded release environment file: `scripts/production/rollback .production/releases/<recorded>.env`. It intentionally refuses schema downgrade and destructive migration rollback. Configure UptimeRobot HTTPS checks for `/` and `/api/v1/health/live`; rotate R2, Google, tunnel, and application secrets regularly.
