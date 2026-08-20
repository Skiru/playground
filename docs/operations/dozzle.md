# Dozzle Cloudflare Access & Security Configuration Guide

This runbook documents the one-time, operational setup required in Cloudflare Zero Trust and Google Cloud Platform (GCP) to secure the Dozzle log management interface at `https://logs.playground.com.pl`.

---

## 1. High-Level Security Architecture

```
User (Browser)
  ↓ HTTPS
Cloudflare Access (Identity-Aware Proxy with Google IdP & Explicit Allow Policy)
  ↓ Authenticated Request with `Cf-Access-Authenticated-User-Email` Header
Cloudflare Tunnel (cloudflared)
  ↓ Private Docker Network (`edge`)
Caddy Gateway (Strips client spoofed headers & verifies `Cf-Access-Authenticated-User-Email`)
  ↓ Private Docker Network (`internal`)
Dozzle (DOZZLE_AUTH_PROVIDER=forward-proxy, ACTIONS=DISABLED, SHELL=DISABLED)
  ↓ Private Docker Network (`observability-internal`)
Docker Socket Proxy (tecnativa/docker-socket-proxy: READ-ONLY API: LOGS/CONTAINERS/EVENTS only)
  ↓ Bound to /var/run/docker.sock:ro
Docker Engine
```

---

## 2. Cloudflare Zero Trust Setup

### Step A: Configure Google as Identity Provider (IdP)

1. Log in to **Cloudflare Zero Trust Dashboard** (`https://one.dash.cloudflare.com`).
2. Navigate to **Settings** > **Authentication** > **Identity providers**.
3. Click **Add new** and select **Google**.
4. Configure the Google OAuth 2.0 Client Credentials:
   - **App ID (Client ID)**: Obtained from GCP Console > APIs & Services > Credentials.
   - **Client Secret**: Obtained from GCP Console > APIs & Services > Credentials.
   - **Authorized Redirect URI**: Copy the URI provided by Cloudflare (e.g., `https://<your-team-name>.cloudflareaccess.com/cdn-cgi/access/callback`).
5. Save the configuration and click **Test**.

### Step B: Create Self-Hosted Access Application

1. In Cloudflare Zero Trust, navigate to **Access** > **Applications**.
2. Click **Add an application** > **Self-hosted**.
3. Enter Application Details:
   - **Application name**: `FamilyPlaces Dozzle Log Management`
   - **Session Duration**: `24 hours` (or `8 hours` for strict re-authentication)
   - **Subdomain**: `logs`
   - **Domain**: `playground.com.pl` (Final URL: `https://logs.playground.com.pl`)
4. Under **Identity providers**, select **Google** (deselect accept any IdP).
5. Enable **Protect with Access** option.

### Step C: Define Explicit Allow Access Policy

> **CRITICAL SECURITY RULE**: Do NOT use wildcard domain policies (e.g. `@gmail.com` or `@playground.com.pl`). You MUST enforce an explicit list of authorized administrator emails or an admin group.

1. Under **Application Policies**, click **Add a policy**.
2. Policy Configuration:
   - **Policy name**: `Admin Log Access Only`
   - **Action**: `Allow`
3. Configure Assign Rules (Include):
   - **Selector**: `Emails` (or `Access Groups`)
   - **Value**: `admin@playground.com.pl` (Add each authorized administrator's explicit email).
4. Require MFA / Re-authentication if configured.
5. Save the policy.

---

## 3. Cloudflare Tunnel Ingress Configuration

Ensure your Cloudflare Tunnel (`cloudflared`) configuration maps the public hostname `logs.playground.com.pl` to the internal Caddy gateway service:

```yaml
ingress:
  - hostname: logs.playground.com.pl
    service: http://gateway:80
  - hostname: playground.com.pl
    service: http://gateway:80
  - service: http_status:404
```

---

## 4. Origin Protection & Header Assertion

To prevent origin bypass (direct requests to VM IP or Caddy with custom Host headers):

1. **Caddy Origin Guard**: Caddy inspects requests on `logs.playground.com.pl` and requires the presence of `Cf-Access-Authenticated-User-Email`. If missing, Caddy responds `403 Forbidden`.
2. **Client Header Sanitization**: Caddy strips any client-supplied `Remote-User`, `Remote-Email`, `Remote-Name`, and `Remote-Roles` headers before proxying requests to Dozzle.
3. **Firewall Requirement**: The production VM must only accept inbound web traffic from Cloudflare IP ranges or Cloudflare Tunnel connections. Direct port 8081 or 2375 exposures are strictly forbidden.

---

## 5. Verification & Smoke Test Checklist

- [ ] Unauthenticated `curl -H "Host: logs.playground.com.pl" http://<VM_IP>/` returns `403 Forbidden`.
- [ ] Direct access to host port 8081 / 2375 is refused (`connection refused` / no open ports).
- [ ] Authenticated browser flow to `https://logs.playground.com.pl` redirects to Google Login via Cloudflare Access.
- [ ] Non-admin Google account is rejected with `Access Denied` by Cloudflare.
- [ ] Authorized admin Google account successfully loads Dozzle dashboard.
- [ ] Log streaming (WebSockets / SSE) works seamlessly in Dozzle.
- [ ] Container shell / exec buttons are disabled in Dozzle UI.
- [ ] Container stop/restart/delete operations through API are blocked by Docker Socket Proxy.
