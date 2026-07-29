# Production environment contract

`scripts/validate-production-env .env.production` is the release gate and never prints values. Keep `.env.production` mode `0600`, owned by the deployment user, outside Git. It requires digest references for all three images, S3/R2 media, HTTPS media URLs, a PostgreSQL 18 database DSN, distinct 32-character minimum secrets, disabled development auth, and disabled discovery.

Production media uses the R2 public custom domain `https://media.playground.com.pl`; the VM never proxies that hostname. `ALLOW_LOCAL_STORAGE_EMERGENCY=true` is an operator-only break-glass mode and is rejected by default. `PLACE_DISCOVERY_ENABLED=false` is required for the first deployment; discovery services are additionally hidden behind the `discovery` Compose profile.

The application has no public host ports. Cloudflare Tunnel routes the public application hostname to `http://gateway:8080`; gateway routes `/api/*` to `api` and all other requests to `web`. Configure media directly to the R2 custom domain.
