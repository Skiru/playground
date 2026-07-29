# External checklist

Technical certification has no open blocker. Required owner configuration before first deployment: Oracle A1 host, Cloudflare DNS/Tunnel/R2 buckets and custom media domain, R2 least-privilege keys, Google OAuth production client, GHCR package visibility, production secret file, and external DNS/TLS/login/media validation. Production backup and restore must be rehearsed after those owner-managed services are configured. See `docs/operations/free-production-infrastructure.md`.
