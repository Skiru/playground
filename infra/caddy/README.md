# Caddy configuration

Caddy terminates local HTTP routing and is the future TLS edge. Production
headers and proxy routes belong here; application business logic does not.

The C1 API image uses FrankenPHP's embedded Caddy in classic mode. A dedicated
edge service can consume the same policy when TLS deployment is authorized.
