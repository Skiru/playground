# Production environment contract

`scripts/validate-production-env .env.production` is the release gate and never prints values. Keep `.env.production` mode `0600`, owned by the deployment user, outside Git. It requires digest references for all three images, valid media configuration (`STORAGE_DRIVER=local` or `STORAGE_DRIVER=s3`), HTTPS media URLs, a PostgreSQL 18 database DSN, distinct 32-character minimum secrets, disabled development auth, and disabled discovery.

Production media supports two drivers:
1. `STORAGE_DRIVER=local`: Media files are stored on a persistent volume (`media-data` mounted at `/data/familyplaces-media`) and served via `/media/*` routes through the gateway to the API container.
2. `STORAGE_DRIVER=s3`: Media files are stored in Cloudflare R2 / S3 object storage and served directly via `STORAGE_S3_PUBLIC_URL` (e.g., `https://media.playground.com.pl`).

Database backups always use dedicated Cloudflare R2 / S3 storage (`BACKUP_S3_*`), independent of media storage driver. `PLACE_DISCOVERY_ENABLED=false` is required for the deployment; discovery services are additionally hidden behind the `discovery` Compose profile.

The application has no public host ports. Cloudflare Tunnel routes the public application hostname to `http://gateway:8080`; gateway routes `/api/*` and `/media/*` to `api` and all other requests to `web`.
