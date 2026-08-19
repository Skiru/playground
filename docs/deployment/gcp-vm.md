# Instrukcja Wdrożenia Produkcyjnego na VM w Google Cloud Platform (GCP Compute Engine)

Niniejsza instrukcja opisuje krok po kroku proces wdrożenia aplikacji **FamilyPlaces** na maszynie wirtualnej w usłudze **Google Cloud Compute Engine (GCP)** w architekturze produkcyjnej Single-VPS z użyciem kontenerów Docker Compose.

---

## 1. Wymagania Sprzętowe i Systemowe VM

- **Typ instancji:** Google Cloud Compute Engine `e2-standard-2` (2 vCPU, 8 GB RAM) lub `t2a-standard-2` (ARM64 Tau).
- **Dysk:** Dysk SSD (pd-ssd) o pojemności minimum **30–50 GB**.
- **System operacyjny:** Ubuntu 24.04 LTS lub Ubuntu 22.04 LTS (x86_64 / amd64 lub arm64).
- **Adres IP:** Statyczny zewnętrzny adres IPv4 (Static External IP).
- **Domeny i DNS:** Skierowany rekord DNS A na zewnętrzny IP instancji.

---

## 2. Krok 1: Tworzenie Instancji VM w Google Cloud CLI (`gcloud`)

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

# 3. Tworzenie maszyny wirtualnej w GCP (Ubuntu x86_64 lub ARM64)
gcloud compute instances create familyplaces-prod-01 \
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
gcloud compute ssh familyplaces-prod-01 --zone=europe-central2-a
```

Sklonuj kod aplikacji do `/opt/familyplaces` i uruchom bootstrap hosta:

```bash
sudo mkdir -p /opt/familyplaces
sudo chown -R $USER:$USER /opt/familyplaces
cd /opt/familyplaces

# Klonowanie repozytorium
git clone https://github.com/skiru/playground.git .

# Wstępna konfiguracja pakietów i silnika Docker
sudo ./scripts/production/bootstrap-host
```

---

## 4. Krok 3: Konfiguracja `.env.production` i Tunelu Cloudflare

Utwórz plik konfiguracyjny `.env.production` oraz plik tokenu Cloudflare:

```bash
cp .env.production.example /opt/familyplaces/.env.production
chmod 600 /opt/familyplaces/.env.production

# Utworzenie pliku tokenu Cloudflare (odczytywalny mode 0400)
sudo mkdir -p /etc/familyplaces
echo "TWOJ_CLOUDFLARE_TUNNEL_TOKEN" | sudo tee /etc/familyplaces/cloudflared-token > /dev/null
sudo chmod 0400 /etc/familyplaces/cloudflared-token
```

Uzupełnij sekrety w `/opt/familyplaces/.env.production` (`APP_SECRET`, `POSTGRES_PASSWORD`, `DATABASE_URL`, itp.).
_Uwaga: Nie musisz wpisywać SHA ani digestów obrazów w `.env.production` – są one automatycznie pobierane z release manifestu podczas wdrożenia._

---

## 5. Krok 4: Produkcyjne Wdrożenie (Jedna Komenda)

Aby wdrożyć nową wersję aplikacji, uruchom:

```bash
./scripts/production/deploy --release <release>
```

Przykład:
```bash
./scripts/production/deploy --release v1.4.0
```

Skrypt automatycznie:
1. Wczyta konfigurację produkcyjną (`.env.production`).
2. Rozpozna i pobierze release manifest dla wskazanej wersji.
3. Wykryje architekturę hosta (`linux/amd64` lub `linux/arm64`) i sprawdzi dostępność wieloplatformowych obrazów OCI.
4. Pobierze immutable obrazy z GHCR według ich unikalnych sum SHA256.
5. Uruchomi bazę PostgreSQL + PostGIS i wykona automatyczne migracje Doctrine.
6. Uruchomi serwisy aplikacji (`api`, `web`, `worker`, `gateway`, `cloudflared`).
7. Przeprowadzi kompletny smoke test (endpointy gateway, liveness API, PostGIS 3.6, storage).
8. Zapisze deskryptor wdrożenia w `.production/releases/current.json`.

---

## 6. Krok 5: Operacje i Status

### Sprawdzenie stanu kontenerów i aktywnego release'u:
```bash
./scripts/production/status
```

### Podgląd logów:
```bash
./scripts/production/logs -f api
```

### Ręczny preflight (opcjonalnie):
```bash
./scripts/production/preflight
```

### Rollback do poprzedniego wydania:
```bash
./scripts/production/rollback .production/releases/<previous-descriptor>.json
```

---

## 7. Podsumowanie

Wdrożenie aplikacji **FamilyPlaces** odbywa się przy użyciu jednej prostej komendy:
`./scripts/production/deploy --release <release>`.
Operator nie musi ręcznie wybierać architektury, kopiować digestów obrazów ani uruchamiać osobnych kroków migracyjnych.
