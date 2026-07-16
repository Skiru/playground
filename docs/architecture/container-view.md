# Container View

| Container | Responsibility | Network/data |
| --- | --- | --- |
| Caddy | edge routing and production security headers | `edge` |
| Web | Node 24 React Router SSR and static assets | `edge`, calls API |
| API | PHP 8.5 FrankenPHP classic-mode Symfony API/admin | `edge`, `internal` |
| Worker | Symfony Messenger consumer using the API image | `internal` |
| PostgreSQL/PostGIS | authoritative catalogue and admin identities | `internal`, volume |
| Redis (optional) | cache, locks, rate limits; never business truth | `internal` |
| Mailpit (dev only) | local email inspection | dev profile |

Development exposes database access on `127.0.0.1` only. Production Compose
exposes only the edge and requires explicit map configuration. Health liveness
checks process availability; readiness checks PostgreSQL but not optional Redis.
