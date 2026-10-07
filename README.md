# Berne World Cup – Visa Request Service

Ein PHP-REST-Webservice, der eine Excel-Antragsliste und Passport-Kopien entgegennimmt,
die Pässe ausliest (MRZ + Place of Birth) und mit der Liste abgleicht, das
**Word-Einladungsschreiben** mit den geprüften Daten befüllt und es als **PDF** zurückgibt –
zusammen mit einer Liste der erfolgreichen und fehlerhaften Einträge.

Die Passanalyse ist über `PASSPORT_ENGINE` umschaltbar:

| Engine | `PASSPORT_ENGINE` | API-Key nötig? | Stärke |
|--------|-------------------|----------------|--------|
| **Lokales OCR** (Tesseract + MRZ-Parser) | `ocr` | **nein** | Datenschutz, keine Kosten, MRZ mit Prüfziffer-Validierung |
| **Google Gemini Flash** | `gemini` *(Default im Code)* | ja (`GEMINI_API_KEY`) | robust bei schlechten Fotos/Spiegelungen, liest Geburtsort zuverlässig; mehrere Pässe pro Datei/PDF |
| **Claude Vision** | `vision` | ja (`ANTHROPIC_API_KEY`) | robust bei schlechten Fotos, liest Geburtsort zuverlässig |

**Fail-closed-Prüfung (`src/MrzVerifier.php`):** Bei `gemini`/`vision` liefert das Modell strukturierte
Felder *und* die rohen MRZ-Zeilen. Der Verifier leitet die Felder deterministisch aus den Zeilen ab
(`MrzParser`), prüft die ICAO-Prüfziffern und vergleicht mit den strukturierten Feldern.
- **Pflicht-Prüfziffern:** Passnummer, Geburtsdatum, Ablaufdatum. Bei bestandener Prüfziffer gilt der aus den
  Zeilen abgeleitete Wert (korrigiert z. B. 0/O und falsche Jahrhunderte). Composite-Prüfziffer und
  Zeilenlänge warnen nur (LLMs fügen gern ein überzähliges `<` an).
- **Nicht eindeutig oder nicht prüfbar** (Prüfziffer falsch, Namen/Geschlecht/Nationalität widersprüchlich,
  kein Reisepass-Dokumentcode, fehlende MRZ) → Status **`review`**: der Antragsteller kommt **nicht** in den
  Brief, sondern in die `failed`-Liste (`status: "review"`, Zähler `summary.needs_review`).
- **Weitere Regeln (Matcher):** Pass muss `PASSPORT_MIN_VALIDITY_MONTHS` (6) über die Abreise hinaus gültig sein;
  Ankunft/Abreise müssen vorhanden sein und Abreise ≥ Ankunft; ausserhalb von `EVENT_START`/`EVENT_END` nur Warnung.
- **Visual-Zone-Werte** (Geburtsort, Ausstellungsdatum) werden bereinigt und auf Plausibilität geprüft, sonst verworfen.

**MRZ-Zeilenlänge (`MrzCheck::classifyLines`):** Vor dem Vertrauen auf die Felder wird die
ICAO-Zeilenlänge geprüft (TD3 2×44, TD2 2×36, TD1 3×30). Die fixe **Datenzeile** (Zeile 2 bei
TD2/TD3) muss exakt der Spec-Länge entsprechen — sonst verschieben sich alle Feld-Offsets
(z. B. Ablaufdatum). Stimmt sie nicht, wird der Pass im Log als `INVALID` markiert
(`check_digits.mrz_lines=false`) + Warnung. Die Namenszeile darf Füll-`<` weglassen/zusätzlich
haben (unkritisch). Im `passport-reads.log` steht pro Zeile `len N [OK/FAIL]`.

> Die MRZ-Felder (Name, Geburtsdatum, Passnummer, Geschlecht, Nationalität, Ablaufdatum)
> liest die OCR-Engine zuverlässig inkl. Prüfziffern. Der **Geburtsort** steht *nicht* in der MRZ –
> lokal wird er nur „best effort" über Stichwortsuche gelesen und bleibt darum eine Warnung
> (`PLACE_OF_BIRTH_WARN_ONLY=true`).

#### MRZ-Robustheit (`src/MrzParser.php`)
Typische OCR-Fehler werden gezielt abgefangen:
- **„0"↔„O" u. ä.** – feldweise Typ-Korrektur: numerische Felder (Datum, Prüfziffern) erzwingen
  Ziffern (`O→0, I→1, S→5, B→8 …`), alphabetische Felder (Name, Ländercode) erzwingen Buchstaben.
- **Verschobenes Ablaufdatum** – die richtige MRZ-Zeile *und* ihr linker Versatz werden über die
  Maximierung der gültigen ICAO-Prüfziffern (inkl. Composite) gewählt; führende Störzeichen oder
  eingemischter Klartext aus der visuellen Zone verschieben die Feldpositionen damit nicht mehr.
- **Passnummer** (alphanumerisch, daher nicht blind korrigierbar) – wird über ihre Prüfziffer
  repariert: es werden nur OCR-verwechselbare Substitutionen gesucht, bis die Prüfziffer stimmt.
- Die MRZ wird nur aus dem **zeichensatz-beschränkten** OCR-Durchlauf geparst; ein zweiter,
  freier Durchlauf liefert ausschliesslich den Geburtsort.
- **OCR-B-Schrift**: die MRZ wird mit dem dedizierten OCR-B-Modell gelesen (`TESSERACT_MRZ_LANG`,
  Default `mrz`), nicht mit `eng` — sonst Fehllesungen. Fallback auf `eng` nur wenn Modell fehlt.
- **Hintergrund-Entfernung / Spiegelungen / Glanz** (`src/MrzImagePrep.php`): vor der OCR wird der
  MRZ-Hintergrund lokal geschätzt und per **adaptiver Schwellwertbildung** „weggerechnet" – eine
  Glanzfläche hebt den lokalen Hintergrund, sodass die Reflexion zu Weiss wird und nur die (etwas
  dunkleren) Zeichen schwarz bleiben (ein globaler Kontrast kann das nicht). Zusätzlich wird das
  MRZ-Band über die Schwarz-Dichte der Zeilen lokalisiert und eng zugeschnitten. Es werden mehrere
  Bildvarianten (adaptive Schwelle, Flat-Field, Kontrast) ge-OCR-t; gewählt wird die mit den meisten
  gültigen Prüfziffern. Im Log (`passport-reads.log`) steht in Klammern, welche Variante gewonnen hat,
  z. B. `engine: ocr (adaptive-threshold)`.

Tests (ohne PHPUnit, nur synthetische Testdaten, Gemini wird gemockt):
```bash
docker compose exec visa sh tests/run_all.sh
```

```
Excel (Tabelle "Applicants")  ─┐
Textfelder (Verband)          ─┼─►  /api/visa-request  ─►  { Report-JSON + PDF (base64) }
bis 24 Passbilder (png/jpg/   ─┘                            oder reines PDF (?format=pdf)
 gif/pdf)
```

---

## 1. Funktionsweise (Pipeline)

1. **Excel lesen** – die Tabelle `Applicants` wird gelesen (`src/ExcelReader.php`). Excel-Seriendaten
   werden in `YYYY-MM-DD` konvertiert.
2. **Bilder vorbereiten** – Bilder werden ggf. verkleinert; PDFs werden mit `pdftoppm` seitenweise
   zu PNG gerastert (`src/ImagePreparer.php`).
3. **Passanalyse** – je nach `PASSPORT_ENGINE`:
   - `ocr`: Tesseract liest die MRZ-Zeilen, ein eigener Parser (`src/MrzParser.php`) zerlegt sie und
     validiert die ICAO-Prüfziffern (`src/OcrPassportReader.php`).
   - `vision`: jedes Bild geht an die Claude-Vision-API, ein erzwungener Tool-Call liefert das JSON
     (`src/PassportAnalyzer.php`).
   Gelesen werden: Name/Vorname, Geburtsdatum, Passnummer, Geschlecht, Nationalität, Ablaufdatum
   (alle aus der MRZ) + Geburtsort (visuell).
4. **Abgleich & Prüfung** – jede Excel-Zeile wird einem erkannten Pass zugeordnet (primär über die
   Passnummer, sonst Name + Geburtsdatum) und Feld für Feld geprüft (`src/Matcher.php`).
5. **Word befüllen** – für jeden erfolgreichen Eintrag wird eine Tabellenzeile in
   `BWC Visa Invitation Letter.docx` eingefügt; die Verbands-Kontaktangaben füllen die in der
   Vorlage vorgesehenen Platzhalter `<National Federation> <Address> <Country> <Name> <Firstname>
   <E-Mail>` (auch wenn Word sie über mehrere Runs splittet). PLZ/Ort wird an `<Address>` angehängt
   (`src/WordFiller.php`).
6. **PDF erzeugen** – LibreOffice headless konvertiert das DOCX zu PDF (`src/PdfConverter.php`).
7. **Antwort** – JSON mit Report + PDF (base64), oder reines PDF bei `?format=pdf`.

### Geprüfte Felder (aus der MRZ, ausser Geburtsort)

| Feld | Quelle | Pflicht für „erfolgreich“ |
|------|--------|---------------------------|
| Name + Vorname | MRZ | ✅ |
| Geburtsdatum | MRZ | ✅ |
| Passnummer | MRZ | ✅ |
| Geschlecht | MRZ | ✅ |
| Nationalität | MRZ (alpha-3) | ✅ |
| Ablaufdatum | MRZ | ✅ |
| Geburtsort | Visuelle Zone | ⚠️ (per Default nur Warnung – `PLACE_OF_BIRTH_WARN_ONLY=true`) |

> Hinweis: Geburtsort und Ausstellungsdatum stehen **nicht** in der MRZ; sie werden aus der visuell
> bedruckten Zone gelesen und sind daher fehleranfälliger. Deshalb ist der Geburtsort standardmässig
> nur eine Warnung. Auf `false` setzen, wenn er hart geprüft werden soll.

---

## 2. Spaltenzuordnung Excel → Word

| Excel `Applicants` | Word-Tabelle |
|--------------------|--------------|
| (laufende Nr.) | N° |
| Full name (as in passport) | Full name |
| Gender | Gender |
| Nationality | Nat |
| Date of Birth | Date of birth |
| Place of Birth | Place of Birth |
| Passport number | PP No |
| Passport Issue Date + Passport expiry date | PP iss. and exp. dates (kombiniert) |
| Role at the event | Position |
| Date of arrival + Date of departure | Arrival + departure date (kombiniert) |

In das Word-Dokument werden die **aus dem Pass verifizierten** Werte geschrieben (MRZ), mit Rückfall
auf den Excel-Wert, wo das Feld nicht in der MRZ steht.

---

## 3. API

### `POST /api/visa-request`

`multipart/form-data`:

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `name` | text | Nachname Kontaktperson |
| `firstname` | text | Vorname Kontaktperson |
| `address` | text | Strasse / Adresse |
| `zip` | text | PLZ |
| `city` | text | Ort |
| `country` | text | Land |
| `federation` | text | Nationaler Verband |
| `excel` | file | `.xlsx` mit Tabelle `Applicants` |
| `images[]` | file (mehrfach) | bis 24 Dateien (png, jpg, gif, pdf) |

Query: `?format=json` (Default) oder `?format=pdf` (liefert reines PDF).

**Antwort (JSON):**
```json
{
  "ok": true,
  "summary": { "applicants_total": 11, "passports_detected": 5, "successful": 5, "failed": 6 },
  "successful": [ { "name": "Mustermann Erika Maria", "date_of_birth": "1971-08-08" } ],
  "failed":     [ { "name": "Rossi Mario", "date_of_birth": "1979-04-11",
                    "reason": "Mismatch in: expiry_date (Excel: '2023-04-14' ≠ Pass: '2023-04-15')",
                    "mismatches": [
                      { "field": "expiry_date", "expected": "2023-04-14", "found": "2023-04-15" }
                    ] } ],
  "details":    [ /* pro Antragsteller: matched, source_image, checks{feld:{expected,found,ok,required}} */ ],
  "pdf_filename": "BWC_Visa_Invitation.pdf",
  "pdf_base64": "JVBERi0xLjcK..."
}
```

### `GET /health`
Liveness-Check.

### Eingabe per URL (statt Multipart)
Neben multipart (`excel`, `images[]`) akzeptiert der Service die Dateien auch als **URL** — nötig
für Aufrufer, die keine Binärdaten senden (z. B. Gravity Flow):
- `excel_url` — URL der Excel-Datei
- `images_urls` — URL-Liste der Passbilder: JSON-Array, komma- oder zeilengetrennt

Der Service lädt die Dateien selbst herunter. Optional per `ALLOWED_FETCH_HOSTS` auf eure Domain
beschränken (SSRF-Schutz). Die URLs müssen für den Server öffentlich erreichbar sein.

---

## 3b. Gravity Flow (WordPress) – Webhook-Schritt

Gravity Flow sendet keine Datei-Binärdaten, sondern die **URLs** der hochgeladenen Dateien →
`excel_url` / `images_urls` verwenden.

**Outgoing-Webhook-Schritt konfigurieren:**

| Einstellung | Wert |
|-------------|------|
| URL | `https://visa.<domain>/api/visa-request?document=none` |
| Method | `POST` |
| Format | `JSON` |
| Request Headers | `X-API-Key` = Wert aus `SERVICE_API_KEYS` |

> `?document=none` lässt das grosse PDF-base64 aus der Antwort weg (PDF kommt per Mail; Antwort
> bleibt klein und gut in Felder speicherbar).

**Request Body (Custom / JSON) — Feld-IDs anpassen:**
```json
{
  "name":        "{Nachname:1}",
  "firstname":   "{Vorname:2}",
  "address":     "{Adresse:3}",
  "zip":         "{PLZ:4}",
  "city":        "{Ort:5}",
  "country":     "{Land:6}",
  "federation":  "{Verband:7}",
  "email":       "{E-Mail:8}",
  "excel_url":   "{Excel-Datei:9}",
  "images_urls": "{Passfotos:10}",
  "send_email":  "1",
  "mail_to":     "{E-Mail:8}"
}
```
- `excel_url` / `images_urls`: Merge-Tag der **Datei-Upload-Felder** (liefert URL bzw. bei
  Multi-File eine Liste/JSON-Array — der Service zerlegt beides).
- `send_email=1` + `mail_to`: verschickt das PDF direkt an die angegebene Adresse (optional; sonst
  greift `email` bzw. `MAIL_DEFAULT_TO`).

**Response Header Mapping:** `None`.

**Response Body Mapping → „Select Fields"**, dann unter *Response Body Field Values* die
**Top-Level-Keys** (kein Pfad nötig) einem Formularfeld zuordnen:

| Key | Bedeutung |
|-----|-----------|
| `ok` | Gesamtstatus (true/false) |
| `successful_count` | Anzahl verifiziert |
| `failed_count` | Anzahl fehlerhaft |
| `applicants_total` | Antragsteller total |
| `passports_detected` | erkannte Pässe |
| `email_sent` | Mail versandt (true/false) |
| `email_error` | SMTP-Fehler (leer bei Erfolg) |
| `email_to` | Empfänger der Mail (kommagetrennt) |
| `email_subject` | Betreff, exakt wie an den Antragsteller gesendet |
| `email_body` | Mail-Text, exakt wie an den Antragsteller gesendet |
| `email_attachment` | Dateiname des Anhangs (leer, wenn nichts angehängt wurde) |
| `document_filename` | Dateiname des Schreibens |

`document_base64` **nicht** mappen (zu gross). Bei Fehler steht die Ursache in `error` (400/500).

**Voraussetzungen:** Datei-URLs öffentlich erreichbar; `ALLOWED_FETCH_HOSTS` in `.env` ggf. auf
die WP-Domain setzen; Request-Timeout beachten (viele Bilder → parallelisiert, s. `GEMINI_CONCURRENCY`).

---

## 4. Authentisierung (Empfehlung für automatisierten Aufruf)

Für unbeaufsichtigte (Server-zu-Server-)Aufrufe ist **HTTPS + API-Key im Header** der pragmatische
Standard, optional gehärtet mit einer **Zeitstempel-HMAC-Signatur** gegen Replay.

**Layer 1 – API-Key (immer aktiv).** Langer Zufallsschlüssel in `SERVICE_API_KEYS` (kommagetrennt für
Rotation). Der Aufrufer sendet:
```
Authorization: Bearer <key>            # oder
X-API-Key: <key>
```
Vergleich erfolgt zeitkonstant (`hash_equals`).

**Layer 2 – HMAC-Zeitstempel (optional, empfohlen für Cron/CI).** `SERVICE_HMAC_SECRET` setzen; der
Aufrufer sendet zusätzlich:
```
X-Timestamp: 1750000000
X-Signature: hex(hmac_sha256("1750000000", SERVICE_HMAC_SECRET))
```
Der Zeitstempel muss innerhalb ±300 s der Serverzeit liegen → schützt gegen Wiedereinspielung.

Key generieren:
```bash
openssl rand -hex 32
```

Beispiel-Aufruf mit beiden Layern:
```bash
TS=$(date +%s)
SIG=$(printf '%s' "$TS" | openssl dgst -sha256 -hmac "$HMAC_SECRET" | awk '{print $2}')
curl -X POST "https://visa.example.com/api/visa-request" \
  -H "Authorization: Bearer $API_KEY" \
  -H "X-Timestamp: $TS" -H "X-Signature: $SIG" \
  -F "name=Muster" -F "firstname=Max" -F "address=Talgut-Zentrum 27" \
  -F "zip=3063" -F "city=Ittigen" -F "country=Switzerland" -F "federation=Swiss Fencing" \
  -F "excel=@Visa Request List Test.xlsx" \
  -F "images[]=@PassDataPageErikaMustermann.png" \
  -F "images[]=@Passport Mario Rossi.jpg" \
  -o response.json
```

**Alternativen / Upgrade-Pfad**
- *OAuth2 Client-Credentials*: wenn ein zentraler Identity-Provider (z. B. Keycloak, Azure AD) genutzt
  werden soll – der Aufrufer holt ein kurzlebiges Bearer-Token, der Service validiert es per JWKS.
  Sinnvoll, sobald mehrere Clients / zentrale Rechteverwaltung nötig sind.
- *mTLS*: gegenseitige Zertifikate auf Reverse-Proxy-Ebene (nginx) für maximale Härtung im B2B-Setup.

Für diesen Service genügt in der Praxis **Layer 1 + Layer 2 hinter HTTPS**.

---

## 5. Voraussetzungen (eigener Server / Docker)

- PHP ≥ 8.1 mit `zip`, `dom`, `mbstring`, `intl`, `gd`
- **LibreOffice** (`soffice`) – DOCX → PDF
- **poppler-utils** (`pdftoppm`) – PDF → PNG
- **tesseract-ocr** – nur bei `PASSPORT_ENGINE=ocr`. MRZ wird mit dem OCR-B-Modell gelesen
  (`mrz`/`ocrb`, vom Dockerfile installiert); Hintergrund wird vor der OCR entfernt.
- Composer-Abhängigkeiten (Slim 4, PhpSpreadsheet, Guzzle, phpdotenv, Monolog)
- Anthropic API-Key – **nur** bei `PASSPORT_ENGINE=vision`

### OCR-B-Modell für die MRZ (wichtig)
Die MRZ ist in **OCR-B** gesetzt; das generische `eng`-Modell liest diese Schrift fehlerhaft.
Das Docker-Image lädt daher die OCR-B-Modelle automatisch als `mrz` (DoubangoTelecom) **und**
`ocrb` (Shreeshrii) in den tessdata-Ordner. Der MRZ-Lesedurchlauf nutzt standardmässig
`TESSERACT_MRZ_LANG=mrz`; fehlt das Modell, fällt er auf `eng` + Zeichen-Whitelist zurück
(mit Log-Warnung). Der Visual-Zone-Durchlauf (Geburtsort) nutzt `TESSERACT_LANG=eng`.

---

## 6. Start

### Mit Docker (empfohlen)
```bash
cp .env.example .env          # SERVICE_API_KEYS setzen; PASSPORT_ENGINE=ocr braucht KEINEN API-Key
docker compose up --build
# Service:    http://localhost:8080/health
# Testseite:  http://localhost:8080/test/   (Datei public/test/index.html)
```

### Lokal ohne Docker
```bash
composer install
cp .env.example .env          # Werte eintragen; soffice/pdftoppm müssen im PATH sein
php -S 0.0.0.0:8080 -t public
```

> Die Testseite liegt unter `public/test/index.html` und ruft das API per `fetch` auf
> (Base-URL + API-Key dort eintragen). CORS ist im Service aktiviert.

---

## 7. Projektstruktur

```
visa-service/
├─ public/
│  ├─ index.php            # Slim-Front-Controller + Auth + Routen
│  └─ test/index.html      # statische Testseite
├─ src/
│  ├─ Config.php           # ENV-Zugriff
│  ├─ Auth.php             # API-Key + optional HMAC-Zeitstempel
│  ├─ ExcelReader.php      # liest Tabelle "Applicants"
│  ├─ ImagePreparer.php    # PDF→PNG, Downscale
│  ├─ PassportReaderInterface.php  # gemeinsame Schnittstelle
│  ├─ OcrPassportReader.php # lokale Engine: Tesseract OCR (Default)
│  ├─ MrzImagePrep.php     # MRZ-Bildvorverarbeitung (Glanz/Spiegelung)
│  ├─ MrzParser.php        # MRZ TD1/TD2/TD3 + Prüfziffern
│  ├─ GeminiPassportReader.php # Google-Gemini-Flash-Engine (optional)
│  ├─ PassportAnalyzer.php # Claude-Vision-Engine (optional)
│  ├─ ExtractionLog.php    # lesbares Protokoll der gelesenen Passdaten
│  ├─ Matcher.php          # Zuordnung + Feldprüfung
│  ├─ WordFiller.php       # DOCX-Tabelle befüllen (OOXML)
│  ├─ PdfConverter.php     # LibreOffice DOCX→PDF
│  └─ VisaController.php   # Orchestrierung
├─ resources/
│  └─ BWC Visa Invitation Letter.docx   # Vorlage
├─ storage/                # tmp + output + Logs (gitignored)
│  ├─ visa.log             # technisches Log (Fehler, Pipeline)
│  └─ passport-reads.log   # lesbares Protokoll: was pro Pass gelesen wurde
├─ Dockerfile, docker-compose.yml
└─ .env.example
```

---

## 7b. Logging / Nachvollziehbarkeit

Pro Pass-Kopie wird in `storage/passport-reads.log` festgehalten, **was** gelesen wurde –
roher OCR-Text, verwendete MRZ-Zeilen und jedes Einzelfeld mit Prüfziffer-Status:

```
──────────────────────────────────────────────────────────────────────
PASSPORT READ  ·  file: Ivanov Alexey.png (page 1)  ·  engine: ocr
Raw OCR text (MRZ pass):
    | P<KAZIVANOV<<ALEXEY<<<<<<<<<<<<<<<<<<<<<<<<<
    | K123456784KAZ0603216M3605129<<<<<<<<<<<<<<06
Raw MRZ lines (used):
    P<KAZIVANOV<<ALEXEY<<<<<<<<<<<<<<<<<<<<<<<<<
    K123456784KAZ0603216M3605129<<<<<<<<<<<<<<06
Parsed fields:
    Surname        : IVANOV
    Given names    : ALEXEY
    Passport no.   : K12345678      [check: OK]
    Nationality    : KAZ
    Date of birth  : 2006-03-21     [check: OK]
    Sex            : M
    Expiry date    : 2036-05-12     [check: OK]
    Composite      : (whole line 2) [check: OK]
    Place of birth : KAZAKHSTAN (visual zone)
    Issue date     : –
Check digits valid: 4/4
```

Live mitlesen: `docker compose exec visa tail -f storage/passport-reads.log`
(bzw. lokal `tail -f storage/passport-reads.log`).

> **Datenschutz:** Dieses Log enthält Klartext-Passdaten. Es ist gitignored; in der
> Produktion Zugriff einschränken bzw. rotieren/löschen gemäss euren Aufbewahrungsregeln.

---

## 7c. Fehlersemantik (HTTP)

| Status | Bedeutung |
|--------|-----------|
| 200 | Verarbeitet. `file_diagnostics` listet Dateien mit Problem (`error`, `no_passport`, `warning`), `incomplete=true` wenn Dateien technisch scheiterten. Einzelne defekte Dateien brechen den Batch **nicht** mehr ab. |
| 400 | Fehler in der Anfrage (fehlende Felder/Dateien, ungültige Excel). Meldung ist für den Aufrufer bestimmt. |
| 502 | Analyse- oder PDF-Dienst nicht erreichbar bzw. alle Dateien technisch gescheitert. |
| 500 | Interner Fehler. Details nur im Log, die Antwort enthält eine `request_id`. |

Gemini-Aufrufe werden bei Netzfehlern, 429 und 5xx bis zu 3× mit Backoff wiederholt. Der API-Key geht im Header
`x-goog-api-key`, nie in der URL. Excel: die **erste Zeile unter der Kopfzeile ist die Beispielzeile und wird
bewusst übersprungen**; mehrdeutige Datumsformate (`08/09/1971`) werden nicht geraten, sondern als Abweichung gemeldet.

**Mail-Garantie:** Wird eine Mail erzeugt, enthält die JSON-Antwort immer `ok:true` (HTTP 200) und unter
`email` bzw. `email_*` genau den Inhalt, der an den Antragsteller ging (`from`, `to`, `subject`, `body`, `attachment`).
Das gilt auch, wenn der SMTP-Versand scheiterte (dann `email_sent:false` + `email_error`, aber weiterhin kein Fehlerstatus)
und wenn die Antwort für die JSON-Kodierung gekürzt werden muss (`document_base64`/`details` entfallen, `truncated` nennt es).
Mail-Platzhalter: `{federation}` (Alias `{verband}`), `{name}`, `{firstname}`, `{filename}`, `{applicants_total}`,
`{passports_detected}`, `{successful}`, `{failed}`, `{failed_list}`.

**Logging (`LOGGING_ENABLED=true`):** Pro Request wird **jede** empfangene Datei protokolliert (Name, Grösse, analysierte
Seiten, Ergebnis: gelesene Pässe / davon `review` / kein Pass / Fehler / abgewiesen) – in `visa.log` (`Files analysed`)
und lesbar in `passport-reads.log` (Block `REQUEST <id>`), zusätzlich der Detailblock pro gelesenem Pass.

## 8. Hinweise & Grenzen

- **Datenschutz:** Passbilder werden zur Analyse an die Anthropic-API gesendet. Für besonders
  sensible Daten ggf. lokale OCR oder einen Auftragsverarbeitungsvertrag prüfen.
- **Genauigkeit:** Die MRZ-Felder sind robust; Geburtsort/Ausstellungsdatum (visuelle Zone) können
  bei schlechten Scans abweichen → daher Geburtsort standardmässig als Warnung.
- **Kosten:** ein Vision-Aufruf pro Bild/Seite. Bei vielen PDFs `MAX_PDF_PAGES` niedrig halten.
- **Skalierung:** Bilder werden sequenziell analysiert; für höheren Durchsatz könnten die
  Vision-Aufrufe parallelisiert werden.
```
