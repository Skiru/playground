# Instrukcja Wdrożenia Produkcyjnego na VM w Google Cloud Platform (GCP Compute Engine)

Niniejsza instrukcja opisuje krok po kroku proces wdrożenia aplikacji **FamilyPlaces** na maszynie wirtualnej w usłudze **Google Cloud Compute Engine (GCP)** w architekturze produkcyjnej Single-VPS z użyciem kontenerów Docker Compose i odwrotnego proxy Caddy.

---

## 1. Wymagania Sprzętowe i Systemowe VM

- **Typ instancji:** Google Cloud Compute Engine `e2-standard-2` (2 vCPU, 8 GB RAM) lub `t2a-standard-2` (ARM64 Tau).
- **Dysk:** Dysk SSD (pd-ssd) o pojemności minimum **30–50 GB**.
- **System operacyjny:** Ubuntu 24.04 LTS lub Debian 12 (Bookworm).
- **Adres IP:** Statyczny zewnętrzny adres IPv4 (Static External IP).
- **Domeny i DNS:** Skierowany rekord DNS A na zewnętrzny IP instancji dla domeny głównej (`playground.com.pl`) oraz subdomen.

---

## 2. Krok 1: Tworzenie Instancji VM w Google Cloud CLI (`gcloud`)

Przed uruchomieniem upewnij się, że masz skonfigurowany `gcloud CLI` i aktywny projekt w GCP.

```bash
# 1. Rezerwacja statycznego zewnętrznego adresu IP
gcloud compute addresses create familyplaces-static-ip \
    --region=europe-central2

# Pobranie zarezerwowanego adresu IP
export IP_ADDRESS=$(gcloud compute addresses describe familyplaces-static-ip --region=europe-central2 --format='get(address)')
echo "Statyczny IP: $IP_ADDRESS"

# 2. Tworzenie reguł zapory sieciowej (Cloud Firewall) dla ruchu HTTP, HTTPS i SSH
gcloud compute firewall-rules create allow-http-https-ssh \
    --allow=tcp:80,tcp:443,tcp:22 \
    --target-tags=http-server,https-server \
    --description="Zezwalaj na ruch HTTP, HTTPS i SSH"

# 3. Tworzenie maszyny wirtualnej w GCP
gcloud compute instances create familyplaces-production-vm \
    --zone=europe-central2-a \
    --machine-type=e2-standard-2 \
    --image-family=ubuntu-2404-lts-amd64 \
    --image-project=ubuntu-os-cloud \
    --boot-disk-size=40GB \
    --boot-disk-type=pd-ssd \
    --address=$IP_ADDRESS \
    --tags=http-server,https-server
```

---

## 3. Krok 2: Przygotowanie Środowiska na Instancji VM

Połącz się z utworzoną instancją przez SSH:

```bash
gcloud compute ssh familyplaces-production-vm --zone=europe-central2-a
```

Wykonaj wstępną konfigurację i instalację wymaganych pakietów:

```bash
# Aktualizacja pakietów systemowych
sudo apt-get update && sudo apt-get upgrade -y

# Instalacja podstawowych narzędzi systemowych
sudo apt-get install -y curl git jq ufw ca-certificates gnupg age fail2ban

# Konfiguracja pliku SWAP (2GB) dla zapewnienia stabilności przy skokach pamięci
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab

# Instalacja oficjalnego silnika Docker i Docker Compose v2
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Dodanie bieżącego użytkownika do grupy docker
sudo usermod -aG docker $USER
newgrp docker

# Konfiguracja lokalnego firewalla UFW
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw --force enable
```

---

## 4. Krok 3: Klonowanie Repozytorium i Konfiguracja `.env.production`

Stwórz produkcyjny katalog aplikacji i sklonuj kod z gałęzi `main`:

```bash
sudo mkdir -p /opt/familyplaces
sudo chown -R $USER:$USER /opt/familyplaces
cd /opt/familyplaces

# Klonowanie repozytorium
git clone https://github.com/skiru/playground.git .
git checkout main

# Utworzenie produkcyjnego pliku środowiskowego
cp .env.production.example .env.production
chmod 600 .env.production
```

Edytuj plik `.env.production` (`nano .env.production`) i uzupełnij kluczowe parametry:

```ini
APP_ENV=prod
APP_DEBUG=0

# Wytgeneruj unikalne tajne klucze (np. command: openssl rand -hex 32)
APP_SECRET=TWOJ_WYGENEROWANY_APP_SECRET_MIN_32_ZNAKI
POSTGRES_DB=family_places
POSTGRES_USER=family_places
POSTGRES_PASSWORD=TWOJE_BEZPIECZNE_HASLO_POSTGRES
DATABASE_URL="postgresql://family_places:TWOJE_BEZPIECZNE_HASLO_POSTGRES@database:5432/family_places?serverVersion=18&charset=utf8"

# Domeny i uwierzytelnianie
APP_PUBLIC_ORIGIN=https://playground.com.pl
TRUSTED_AUTH_ORIGINS=https://playground.com.pl,https://www.playground.com.pl
GATEWAY_DOMAIN=playground.com.pl
ACME_EMAIL=admin@playground.com.pl

# Logowanie Google OAuth (opcjonalnie)
GOOGLE_IDENTITY_ENABLED=true
PUBLIC_GOOGLE_CLIENT_ID=TWOJ_GOOGLE_CLIENT_ID
GOOGLE_CLIENT_ID=TWOJ_GOOGLE_CLIENT_ID

# Konfiguracja Obiektowej Pamięci Masowej (S3 / Cloudflare R2)
STORAGE_DRIVER=s3
STORAGE_S3_ENDPOINT=https://TWOJ_ACCOUNT_ID.r2.cloudflarestorage.com
STORAGE_S3_KEY=TWOJ_R2_ACCESS_KEY
STORAGE_S3_SECRET=TWOJ_R2_SECRET_KEY
STORAGE_S3_BUCKET=familyplaces-media-prod
STORAGE_S3_REGION=auto
STORAGE_S3_PUBLIC_URL=https://media.playground.com.pl
MEDIA_PUBLIC_BASE_URL=https://media.playground.com.pl

# Przechowywanie szyfrowanych kopii zapasowych (S3 + Age Key)
BACKUP_S3_ENDPOINT=https://TWOJ_ACCOUNT_ID.r2.cloudflarestorage.com
BACKUP_S3_BUCKET=familyplaces-backups-prod
BACKUP_S3_KEY=TWOJ_BACKUP_ACCESS_KEY
BACKUP_S3_SECRET=TWOJ_BACKUP_SECRET_KEY
AGE_RECIPIENT=age1TWOJ_KLUCZ_PUBLICZNY_AGE

# Cloudflare Tunnel Token (jeśli używany)
CLOUDFLARE_TUNNEL_TOKEN_FILE=/etc/familyplaces/cloudflared-token

# Obrazy wydań (przypisane konkretne digest z GHCR)
API_IMAGE=ghcr.io/skiru/family-places-api@sha256:REPLACE_WITH_MANIFEST_DIGEST
WEB_IMAGE=ghcr.io/skiru/family-places-web@sha256:REPLACE_WITH_MANIFEST_DIGEST
POSTGIS_IMAGE=ghcr.io/skiru/family-places-postgis@sha256:REPLACE_WITH_MANIFEST_DIGEST
RELEASE_SHA=REPLACE_WITH_FULL_GIT_SHA
RELEASE_VERSION=1.0.0
```

Po uzupełnieniu pliku uruchom weryfikację poprawności zmiennych:

```bash
./scripts/validate-production-env
```

---

## 5. Krok 4: Autoryzacja GHCR i Pierwszy Deployment

Zaloguj się do GitHub Container Registry, aby umożliwić pobieranie obrazów:

```bash
# Utwórz GitHub Personal Access Token (PAT) z uprawnieniem read:packages
echo "TWOJ_GITHUB_PAT_TOKEN" | docker login ghcr.io -u TWOJ_GITHUB_USERNAME --password-stdin
```

Uruchom automatyczny skrypt produkcyjnego wdrożenia:

```bash
COMPOSE_FILE=compose.prod.yaml ./scripts/deploy-oracle.sh
```

Skrypt automatycznie:
1. Zweryfikuje plik środowiskowy i przypisania sum kontrolnych `@sha256:`.
2. Pobierze immutable obrazy z GHCR.
3. Uruchomi i przetestuje stan bazy danych PostgreSQL + PostGIS.
4. Wykona migracje struktury bazy danych Doctrine.
5. Uruchomi kontenery API, Web SSR, Worker oraz Gateway (Caddy).
6. Przetestuje punkty kontrolne stanu zdrowia (`/api/v1/health/live`) i wykona testy dymne (`./scripts/smoke`).
7. Zapisze atomowe metadane wydania w `.production/releases/current.json`.

---

## 6. Krok 5: Automatyzacja Kopii Zapasowych i Konserwacji Bazy

Skonfiguruj codzienne tworzenie szyfrowanych kopii zapasowych w cronie:

```bash
# Edycja tabeli zadań cron dla użytkownika
crontab -e
```

Dodaj następujący wpis uruchamiający kopię zapasową bazy co noc o godzinie 02:30:

```cron
0 2 * * * cd /opt/familyplaces && COMPOSE_FILE=compose.prod.yaml ./scripts/backup-oracle.sh >> /var/log/familyplaces-backup.log 2>&1
30 2 * * * cd /opt/familyplaces && COMPOSE_FILE=compose.prod.yaml ./scripts/backup-verify >> /var/log/familyplaces-backup-verify.log 2>&1
```

---

## 7. Krok 6: Podstawowe Komendy Operacyjne, Monitoring i Rollback

### Sprawdzanie stanu kontenerów i usługi
```bash
docker compose -f compose.prod.yaml ps
```

### Podgląd logów aplikacji w czasie rzeczywistym
```bash
# Logi wszystkich kontenerów
docker compose -f compose.prod.yaml logs -f --tail=100

# Logi wybranego kontenera (np. API lub Gateway)
docker compose -f compose.prod.yaml logs -f api
docker compose -f compose.prod.yaml logs -f gateway
```

### Ręczne sprawdzenie stanu zdrowia (Healthcheck)
```bash
curl -i https://playground.com.pl/api/v1/health/live
```

### Szybkie cofnięcie wydania (Rollback)
W przypadku wykrycia problemów po wdrożeniu nowej wersji, wykonaj cofnięcie do poprzedniej stabilnej wersji:

```bash
COMPOSE_FILE=compose.prod.yaml ./scripts/rollback-oracle.sh
```

---

## 8. Podsumowanie Wdrożenia

Aplikacja **FamilyPlaces** jest gotowa do ciągłego działania w GCP Compute Engine na poziomie produkcyjnym, spełniając rygorystyczne kryteria bezpieczeństwa, idempotencji migracji oraz zerowego przestoju (zero-downtime deployment).
