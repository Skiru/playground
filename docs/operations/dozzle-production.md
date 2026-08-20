# Dozzle Production Operations Runbook

This document is the definitive operational runbook for deploying, maintaining, auditing, and troubleshooting the Dozzle log management service and Docker Socket Proxy on `https://logs.playground.com.pl`.

---

## 1. Prerequisites Checklist

Before executing a release containing Dozzle changes, verify:

1. **Cloudflare Access Application Created**:
   - Hostname: `logs.playground.com.pl`
   - Application Type: Self-hosted
   - Session Duration: 24h (or 8h with re-authentication)
2. **Identity Provider Configured**:
   - Google OAuth 2.0 Client ID & Secret configured in Cloudflare Zero Trust.
3. **Explicit Allow Policy Enabled**:
   - Action: `Allow`
   - Include rule: `Emails` matching exact administrator email list (e.g. `admin@playground.com.pl`).
   - Default action for all other users: `Deny`.
4. **Cloudflare Tunnel Ingress Configured**:
   - Route `logs.playground.com.pl` -> `http://gateway:80`
5. **Environment Configuration**:
   - `.env.production` has:
     - `DOZZLE_AUTH_PROVIDER=forward-proxy`
     - `DOZZLE_AUTH_HEADER_USER=Cf-Access-Authenticated-User-Email`
     - `DOZZLE_AUTH_HEADER_EMAIL=Cf-Access-Authenticated-User-Email`
     - `DOZZLE_AUTH_HEADER_NAME=Cf-Access-Authenticated-User-Email`
     - `DOZZLE_IMAGE=amir20/dozzle:v8.11.8@sha256:d6b43130cdee36aab01a1a1fae7f83b2b8db63c1ee3b5ef61781cb947967bc9b`
     - `DOZZLE_SOCKET_PROXY_IMAGE=tecnativa/docker-socket-proxy:0.1.1@sha256:6c22b9545adc95258af9deffdde6c0ce0a0a70716771e5a4e02d24d1b6e0dda1`

---

## 2. Deployment Procedure

Deployment is executed fully deterministically via the release deployment script:

```bash
./scripts/production/deploy --release <version>
```

The deployment script automatically:
1. Validates the environment using `./scripts/validate-production-env`.
2. Verifies immutable Docker image digests (`DOZZLE_IMAGE` and `DOZZLE_SOCKET_PROXY_IMAGE`).
3. Starts `dozzle-socket-proxy` and `observability-logs` containers.
4. Executes `./scripts/production/smoke` which includes unauthenticated (403) and authenticated (200) Dozzle checks.

---

## 3. Post-Deployment Verification (Smoke Tests)

Execute the following commands on the host to verify operational health:

### Test 1: Verify Origin Bypass Protection (Unauthenticated request returns 403)
```bash
docker compose -f compose.yaml -f compose.prod.yaml exec -T gateway wget -S -O /dev/null --header="Host: logs.playground.com.pl" 'http://localhost:80/'
# Expected result: HTTP 403 Forbidden
```

### Test 2: Verify Authenticated Request Proxying (Authenticated header returns 200)
```bash
docker compose -f compose.yaml -f compose.prod.yaml exec -T gateway wget -S -O /dev/null --header="Host: logs.playground.com.pl" --header="Cf-Access-Authenticated-User-Email: admin@playground.com.pl" 'http://localhost:80/'
# Expected result: HTTP 200 OK
```

### Test 3: Verify Docker Socket Isolation (No host port exposure)
```bash
docker compose -f compose.yaml -f compose.prod.yaml ps
# Ensure dozzle-socket-proxy and observability-logs show NO published ports on host (e.g. no 0.0.0.0:8081 or 0.0.0.0:2375).
```

### Test 4: Verify Docker Socket Proxy Security Allowlist
```bash
# Executing container stop via socket proxy MUST fail with 403 Forbidden from socket proxy:
docker compose -f compose.yaml -f compose.prod.yaml exec -T observability-logs curl -s -X POST http://dozzle-socket-proxy:2375/containers/database/stop
# Expected result: Access denied by docker-socket-proxy / HTTP 403
```

---

## 4. Revoking Admin Access

To immediately revoke an administrator's access to Dozzle:

1. Open **Cloudflare Zero Trust Dashboard** > **Access** > **Applications**.
2. Select `FamilyPlaces Dozzle Log Management` > **Policies**.
3. Edit the `Admin Log Access Only` policy.
4. Remove the user's email address from the **Include** rule.
5. Save the policy.
6. Open **Access** > **User Sessions** and revoke active sessions for the user. Access is revoked instantly across all edge locations.

---

## 5. Troubleshooting & Diagnostics

### Issue: "Access Denied: Cloudflare Access Authentication Required" (403)
- **Cause**: Request reached Caddy without `Cf-Access-Authenticated-User-Email` header.
- **Fix**: Check Cloudflare Tunnel ingress configuration to ensure requests pass through Cloudflare Access.

### Issue: Dozzle displays "Error connecting to Docker"
- **Cause**: Dozzle cannot connect to `dozzle-socket-proxy:2375` or `observability-internal` network is disconnected.
- **Fix**: Run `docker compose logs dozzle-socket-proxy` and `docker compose logs observability-logs`. Verify both services share `observability-internal` network.

### Issue: Live log streaming disconnects
- **Cause**: Proxy timeout or WebSocket connection dropping.
- **Fix**: Ensure Caddy `encode zstd gzip` is enabled and Cloudflare WebSockets are enabled for the zone.

---

## 6. Rollback Procedure

If Dozzle or Socket Proxy configuration requires rollback:

```bash
./scripts/production/rollback
```

The rollback procedure restores the previous container configuration and validates network/routing health.
