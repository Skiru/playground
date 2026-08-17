# Oracle Cloud Always Free ARM64 Deployment Guide

This guide details the complete production setup and deployment procedure for `Skiru/playground` on **Oracle Cloud Infrastructure (OCI) Always Free Compute**.

---

## 1. System Topology & Architecture

* **Provider**: Oracle Cloud Infrastructure (OCI)
* **Shape**: `VM.Standard.A1.Flex` (ARM64 / Ampere Altra)
* **Resource Allocation**: 2 OCPU, 12 GB RAM, 100 GB Boot Volume
* **Operating System**: Ubuntu 24.04 LTS ARM64 (`aarch64`)
* **Network & Ingress**: Direct HTTPS ingress via Caddy Gateway (Ports 80 & 443). PostgreSQL and internal services are never exposed publicly.
* **Persistent Storage**:
  * PostgreSQL DB: Docker named volume `database-data` (`/var/lib/postgresql`)
  * Caddy TLS Certs/State: Docker named volume `caddy-data` (`/data`) and `caddy-config` (`/config`)
  * Media: Cloudflare R2 / S3-compatible object storage (`STORAGE_DRIVER=s3`)
  * Database Backups: Encrypted dumps uploaded to Cloudflare R2 / S3 object storage

---

## 2. OCI Cloud Infrastructure Provisioning

### 2.1 Region & Compartment
1. Log into the OCI Console.
2. Select your Home Region (e.g., Frankfurt `eu-frankfurt-1`).
3. Select or create a compartment (e.g., `familyplaces-prod`).

### 2.2 VCN & Subnet
1. Create a Virtual Cloud Network (VCN) named `familyplaces-vcn` (IPv4 CIDR: `10.0.0.0/16`).
2. Create an **Internet Gateway** and attach it to `familyplaces-vcn`.
3. Add a default route (`0.0.0.0/0` -> Internet Gateway) to the VCN's Default Route Table.
4. Create a **Public Subnet** named `familyplaces-public-subnet` (CIDR: `10.0.1.0/24`).

### 2.3 Network Security Group (NSG) / Security List Rules
Configure the NSG or Security List attached to `familyplaces-public-subnet` with the following **Inbound Rules**:

| Protocol | Source | Destination Port | Purpose |
| :--- | :--- | :--- | :--- |
| TCP | `0.0.0.0/0` | `80` | HTTP Ingress / ACME Challenge |
| TCP | `0.0.0.0/0` | `443` | HTTPS Public Ingress |
| TCP | `<ADMIN_IP_CIDR>` | `22` | SSH Administration |

> **CRITICAL**: Do **NOT** expose ports `5432` (PostgreSQL), `6379` (Redis), `3000` (Node Web), or `8000/8080` (FrankenPHP) in the OCI Security Rules.

---

## 3. Host System Setup & Bootstrapping

### 3.1 Ubuntu 24.04 Firewall Adjustment
OCI Ubuntu images include `iptables` rules that block incoming traffic on ports 80/443 by default even if OCI Security Lists permit it.

On the VM host, run:
```bash
sudo iptables -I INPUT 6 -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT 6 -p tcp --dport 443 -j ACCEPT
sudo netfilter-persistent save
```

### 3.2 Docker Engine & Dependencies Installation
Install Docker Engine, Docker Compose v2, `jq`, `flock`, and `age`:

```bash
sudo apt-get update && sudo apt-get install -y \
  ca-certificates \
  curl \
  gnupg \
  lsb-release \
  jq \
  util-linux \
  age \
  awscli

# Install Official Docker Engine & Compose v2
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Enable and start Docker
sudo systemctl enable --now docker
sudo usermod -aG docker $USER
```

---

## 4. Directory Structure & Deployment Setup

Establish the production root at `/opt/familyplaces`:

```bash
sudo mkdir -p /opt/familyplaces
sudo chown -R $USER:$USER /opt/familyplaces
cd /opt/familyplaces

# Clone repository
git clone https://github.com/Skiru/playground.git .
```

Standard Directory Tree:
```text
/opt/familyplaces/
├── compose.oracle.arm64.yaml
├── .env.production
├── infra/
│   └── deployment/
│       └── Caddyfile.oracle
├── scripts/
│   ├── deploy-oracle.sh
│   ├── rollback-oracle.sh
│   ├── healthcheck-oracle.sh
│   ├── backup-oracle.sh
│   └── restore-oracle.sh
└── .production/
    ├── deploy.lock
    ├── backups/
    └── releases/
        ├── current.json
        └── <timestamp>-<sha>.json
```

---

## 5. GHCR Authentication & Secrets Configuration

### 5.1 Authenticate to GitHub Container Registry (GHCR)
Generate a GitHub Personal Access Token (PAT) with `read:packages` scope:

```bash
echo "YOUR_GITHUB_PAT" | docker login ghcr.io -u YOUR_GITHUB_USERNAME --password-stdin
```

### 5.2 Populate `.env.production`
Copy `.env.oracle.example` to `.env.production` and substitute all secrets:

```bash
cp .env.oracle.example .env.production
chmod 0600 .env.production
nano .env.production
```

Mandatory variables to populate:
* `APP_SECRET`: Random 32+ character string
* `DOMAIN_NAME`: e.g. `playground.com.pl`
* `POSTGRES_PASSWORD`: Random 32+ character password
* `MEDIA_PUBLIC_BASE_URL`: e.g. `https://media.playground.com.pl`
* `STORAGE_S3_KEY` & `STORAGE_S3_SECRET`: S3/R2 credentials
* `API_IMAGE`, `WEB_IMAGE`, `POSTGIS_IMAGE`: Immutable GHCR image digests (containing `@sha256:...`)

---

## 6. First Production Deployment

To execute the initial deployment:

1. **Verify DNS A Record**: Point `playground.com.pl` to your OCI Instance Public IPv4.
2. **Execute Deployment Script**:

```bash
./scripts/deploy-oracle.sh
```

### Deployment Sequence Executed:
1. Validates environment variables & immutable `@sha256:` digests
2. Acquires deployment lock (`/opt/familyplaces/.production/deploy.lock`)
3. Validates Compose configuration (`compose.oracle.arm64.yaml`)
4. Pulls immutable ARM64 container images from GHCR
5. Starts PostgreSQL database container and waits for liveness
6. Runs Doctrine migrations (`doctrine:migrations:migrate --no-interaction`) using the new API image
7. Starts/recreates API, Web SSR, and background worker containers
8. Starts Caddy Gateway listening on ports 80 & 443
9. Executes liveness and public smoke tests (`./scripts/healthcheck-oracle.sh`)
10. Records release metadata in `.production/releases/` and releases deployment lock

---

## 7. Normal Releases & Rollback Procedure

### 7.1 Releasing a New Version
To deploy a new release tag:

```bash
git fetch --tags
git checkout tags/v1.0.1 -b release-1.0.1

# Update immutable digests in .env.production
nano .env.production

# Deploy
./scripts/deploy-oracle.sh
```

### 7.2 Rolling Back a Release
If a code issue is detected post-deployment, rollback to a recorded release descriptor:

```bash
# Dry-run validation
./scripts/rollback-oracle.sh --validate-only .production/releases/<previous_release>.json

# Execute Rollback
./scripts/rollback-oracle.sh .production/releases/<previous_release>.json
```

> **IMPORTANT**: Rollbacks restore the previous immutable image digests (`API_IMAGE`, `WEB_IMAGE`, `POSTGIS_IMAGE`). Database schema migrations are **NEVER** automatically downgraded or reversed.

---

## 8. Backup & Restore Procedures

### 8.1 Database Backup
To create a daily backup (and upload to Cloudflare R2 / S3 if configured):

```bash
./scripts/backup-oracle.sh daily
```

Backups produce an age-encrypted database dump (`.dump.age`), SHA256 checksum, and a JSON manifest.

### 8.2 Database Restore
To test or execute a restore into a fresh database target:

```bash
AGE_IDENTITY_FILE=/path/to/offline-key.txt ./scripts/restore-oracle.sh familyplaces/postgres/daily/familyplaces-daily-20260817T120000Z.dump.age
```

---

## 9. Troubleshooting & Diagnostics

### Liveness and Health Checks
Run healthchecks at any time:
```bash
./scripts/healthcheck-oracle.sh
```

### Viewing Container Logs
```bash
docker compose -f compose.oracle.arm64.yaml logs -f --tail=100 gateway
docker compose -f compose.oracle.arm64.yaml logs -f --tail=100 api
```

### Disk Space Maintenance
Clean up old unused Docker images:
```bash
docker image prune -a -f --filter "until=72h"
```
