# BWC Gotenberg (DOCX → PDF mit Avenir)

Eigenständiges Teilprojekt: Gotenberg 8 mit eingebackenen Avenir-Fonts, damit das
Einladungsschreiben serverseitig korrekt (in Avenir) nach PDF konvertiert wird.

```
gotenberg/
├─ Dockerfile            # gotenberg/gotenberg:8 + Avenir-Fonts
├─ fonts/*.ttf           # 12 Avenir-Schnitte (self-contained)
├─ deploy.sh             # Build + Deploy nach Google Cloud Run
├─ docker-compose.yml    # lokal / VPS starten
└─ README.md
```

Build-Kontext ist **dieser Ordner** (Fonts liegen in `./fonts`, keine Abhängigkeit ausserhalb).

## A) Google Cloud Run (empfohlen)
Einmalig:
```bash
gcloud auth login
gcloud config set project DEINE-PROJEKT-ID
gcloud services enable run.googleapis.com cloudbuild.googleapis.com artifactregistry.googleapis.com
gcloud artifacts repositories create bwc --repository-format=docker --location=europe-west6
```
Bauen + deployen (aus diesem Ordner):
```bash
bash deploy.sh
```
Gibt am Ende die URL aus → in Hostpoint-`.env`:
```
OUTPUT_FORMAT=pdf
PDF_CONVERTER=gotenberg
GOTENBERG_URL=https://gotenberg-xxxx.europe-west6.run.app
```

Health-Check:
```bash
curl -i https://gotenberg-xxxx.../health      # HTTP 200
```

## B) Lokal / eigener VPS (Docker)
```bash
docker compose up --build -d        # http://localhost:3000
```

## Test der Konvertierung
```bash
curl -s -o out.pdf \
  -F "files=@irgendein.docx;type=application/vnd.openxmlformats-officedocument.wordprocessingml.document" \
  http://localhost:3000/forms/libreoffice/convert
head -c 5 out.pdf     # muss "%PDF-" sein
```

## Prüfen, ob Avenir wirklich im Image ist
Im Cloud-Build-Log erscheint `=== installed Avenir faces ===` mit der Font-Liste.
Lokal:
```bash
docker run --rm bwc-gotenberg fc-list | grep -i avenir
```

## Sicherheit
`deploy.sh` nutzt `--allow-unauthenticated` (öffentlich) + `--max-instances 3` (Kosten-Cap).
Für Absicherung: Basic-Auth-Proxy davor, dann in der App `GOTENBERG_USER`/`GOTENBERG_PASS` setzen.

> **Hinweis:** Die Avenir-Schriftdateien (`gotenberg/fonts/*.ttf`) sind lizenzpflichtig und nicht im Repository. Vor `docker build` dort ablegen (siehe `gotenberg/fonts/README.md`); ohne Schriften schlägt `COPY fonts/*.ttf` fehl.
