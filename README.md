# Mailspur – Email Log

Leichtgewichtiges WordPress-Plugin, das alle ausgehenden E-Mails protokolliert: Live-Suche, Filter, Status, erneut senden. Sichere Vorschau in einer Sandbox ohne Tracking-Pixel, maskierte Reset-Links, DSGVO-Export.

- **Plugin-Code:** [`mailspur-email-log/`](mailspur-email-log/) – genau dieser Ordner wird ausgeliefert (ZIP / wordpress.org).
- **Beschreibung für wordpress.org:** [`mailspur-email-log/readme.txt`](mailspur-email-log/readme.txt)
- **Verzeichnis-Grafiken (Icon, Banner, Screenshots):** [`.wordpress-org/`](.wordpress-org/) → landen im SVN unter `/assets`.

Alles außerhalb von `mailspur-email-log/` ist Entwicklungswerkzeug und wird nie mit ausgeliefert.

## Lokale Entwicklung

Voraussetzung: Node ≥ 24 (unter Node 22 stürzt das php-wasm von Playground mit PHP 7.4 ab). PHP muss nicht installiert sein.

```bash
npm ci
```

```bash
npm run setup:php
```

`setup:php` lädt unter Windows ein portables PHP 7.4 + Composer nach `.cache/` (Prüfsummen werden verifiziert, nichts wird systemweit installiert). PHP 8.4 lässt sich mit `npm run setup:php -- --php 8.4` ergänzen – Windows Smart App Control blockiert manche Builds; die Werkzeuge nehmen automatisch die neueste lauffähige Version.

| Befehl | Was |
| --- | --- |
| `npm run verify` | alles, was die CI prüft (inkl. WordPress-Laufzeit- und Browsertests) |
| `npm run verify -- --fast` | statische Prüfungen + Unit-Tests (≈ 30 s, auch als pre-push-Hook) |
| `npm run playground` | WordPress mit Plugin und Beispiel-Mails auf http://127.0.0.1:9400 |
| `npm run test:e2e -- --php 7.4 --wp 6.5` | Laufzeittests gegen eine bestimmte PHP-/WP-Version |
| `npm run phpcs` / `npm run phpcbf` | Coding Standards prüfen / automatisch korrigieren |
| `npm run i18n` | Übersetzungen aus `i18n/*.json` neu erzeugen |
| `npm run build` | Release-ZIP nach `dist/` |
| `node scripts/wporg-assets.mjs` | Icon, Banner und Screenshots für wordpress.org neu erzeugen |

Pre-push-Hook einmalig aktivieren:

```bash
git config core.hooksPath .githooks
```

## Pipeline

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) läuft bei jedem Push auf `main`, in Pull Requests, wöchentlich und manuell. Jeder Job blockiert – das Release-ZIP entsteht nur, wenn alle grün sind.

| Bereich | Prüfung |
| --- | --- |
| **Lauffähigkeit** | `php -l` auf PHP 7.4 – 8.5 · PHPUnit (Brain Monkey) auf 7.4 und 8.4 · Integrations-Selbsttest in echtem WordPress (Aktivierung, alle Versandwege, REST inkl. Rechte, Aufräumen, DSGVO, Deinstallation) · Browsertests (Playwright) – jeweils auf der ältesten (PHP 7.4 / WP 6.5) und neuesten Kombination |
| **Sicherheit** | PHPCS `WordPress.Security`/`DB` + VIP-Security-Sniffs · ESLint `no-unsanitized` (DOM-XSS) · Browsertest: eingeschleustes `<script>`, Tracking-Pixel und Formular in Mails bleiben wirkungslos · Plugin Check (offizielle Action + gepinnte PHPCS-Regeln) · gitleaks über die ganze Historie · `composer audit` / `npm audit` · actionlint + zizmor für die Workflows |
| **Codequalität** | PHPCS WordPress-Extra ohne Baseline · PHPStan Level 8 gegen PHP 7.4 – 8.5 · PHPCompatibility |
| **wordpress.org** | Readme/Header/Versionen/Changelog/Assets (`scripts/repo-checks.mjs`) · „Tested up to“ gegen die aktuelle WP-Version · Übersetzungen vollständig |
| **ZIP** | reproduzierbar (gleicher Commit → gleiche SHA-256), nur erlaubte Dateitypen, wird nach dem Bauen geprüft (Struktur, Version, Direktzugriffs-Schutz, 10-MB-Limit) |

Actions sind auf Commit-SHAs gepinnt, Werkzeuge werden mit fester Version und SHA-256 geladen, Dependabot hält beides aktuell (mit 7 Tagen Abkühlzeit).

## Release

1. Version in `mailspur-email-log/mailspur-email-log.php` (Header **und** `const VERSION`) und `readme.txt` (`Stable tag`) anheben, Changelog-Eintrag `= x.y.z =` ergänzen. `npm run check` meldet jede Abweichung.
2. Tag pushen:

   ```bash
   git tag v1.0.1
   ```

   ```bash
   git push origin v1.0.1
   ```

3. [`.github/workflows/release.yml`](.github/workflows/release.yml) führt die komplette CI aus, prüft Tag = Version, erstellt ein GitHub-Release mit ZIP + SHA-256 und – sobald freigeschaltet – deployt genau dieses ZIP nach wordpress.org.

## Veröffentlichung auf wordpress.org (einmalig)

1. Konto auf [wordpress.org](https://login.wordpress.org/register) anlegen und den Benutzernamen in `readme.txt` unter `Contributors:` eintragen.
2. Zwei-Faktor-Authentifizierung im Profil aktivieren (für Plugin-Autoren Pflicht).
3. ZIP bauen (`npm run build`) und unter [wordpress.org/plugins/developers/add](https://wordpress.org/plugins/developers/add/) hochladen. Slug: `mailspur-email-log`.
4. Prüfung durch das Plugin-Team abwarten (Mail kommt an die Konto-Adresse; Rückfragen dort beantworten).
5. Nach der Freigabe: SVN-Passwort unter *Profil → Konto & Sicherheit* erzeugen, im GitHub-Repo die Secrets `SVN_USERNAME` und `SVN_PASSWORD` sowie die Variable `WPORG_DEPLOY=true` setzen. Optional die Umgebung `wordpress-org` mit Freigabe absichern.
6. Ab dann veröffentlicht jeder Tag automatisch – inklusive Icon, Banner und Screenshots aus `.wordpress-org/`.

Übersetzungen: wordpress.org baut Sprachpakete über translate.wordpress.org; die mitgelieferte deutsche Übersetzung ist nur der Fallback, solange es kein Sprachpaket gibt.

## Lizenz

GPLv2 oder später – siehe [LICENSE](LICENSE).
