# Security Baseline

The implementation follows applicable OWASP ASVS controls and reviews the OWASP
API Security Top 10. Public query DTOs constrain coordinate, radius, bbox, text,
pagination, and sort values. Queries bind parameters and expose only explicit
resource DTOs. Place text remains plain text and React escaping is retained.

Admin uses session authentication, `ROLE_ADMIN`, CSRF protection, secure cookie
settings in production, and allowlisted actions. Public search is rate and
resource limited. Website links accept only allowed HTTP(S) schemes. The backend
does not fetch supplied URLs, preventing an SSRF surface.

Production responses set CSP without `unsafe-eval`, `X-Content-Type-Options`,
`Referrer-Policy`, and `Permissions-Policy`; HSTS is enabled only behind verified
HTTPS. Problem Details omit stack traces. Logs are structured, carry a
correlation ID, omit PII and raw query strings, and return the correlation ID to
clients. Secret, dependency, Composer, pnpm, and image scans run in CI.

Future extension points are an error tracker scrubbed of PII, external uptime
checks against liveness/readiness, a metrics exporter, and trace propagation.
They are documented seams, not bundled observability infrastructure.
