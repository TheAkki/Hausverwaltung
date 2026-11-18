# Drittkomponenten und ihre Lizenzen

Die Hausverwaltung selbst steht unter der GNU General Public License, Version 2 oder
(nach deiner Wahl) jeder späteren Version (`GPL-2.0-or-later`, siehe [LICENSE](LICENSE)).

Sie nutzt die unten aufgeführten Komponenten anderer Autoren. Deren Lizenztexte liegen
nach der Installation jeweils im Paketordner (`vendor/<paket>/`,
`node_modules/<paket>/`). Diese Liste nennt die Komponenten, die mit der Anwendung
ausgeliefert werden, also nicht die reinen Entwicklungswerkzeuge.

## Wichtig für die Weitergabe

- **mPDF** (`mpdf/mpdf`, PDF-Erzeugung der Betriebskostenabrechnung) steht unter
  **GPL-2.0-only**. Wird die Anwendung zusammen mit mPDF weitergegeben, etwa als
  Docker-Image oder als Archiv mit `vendor/`, gilt für das Gesamtwerk die GPL Version 2.
  Deshalb ist die Hausverwaltung unter `GPL-2.0-or-later` lizenziert und nicht nur unter
  GPLv3.
- **Schriften in mPDF** (`vendor/mpdf/mpdf/ttfonts/`) stehen unter eigenen freien
  Lizenzen: DejaVu (Bitstream Vera), GNU FreeFont (GPL mit Font-Ausnahme) und mehrere
  unter der SIL Open Font License. Die Lizenzhinweise liegen als `*info.txt` bzw.
  `*OFL*.txt` im selben Ordner.
- **Swagger UI** (`swagger-ui-dist`, API-Dokumentation unter `/api-docs`) steht unter
  Apache-2.0 und wird beim Frontend-Build nach `public/build/` gebündelt. Der
  zugehörige Hinweis lautet: „swagger-ui – Copyright 2020-2021 SmartBear Software Inc.“
- **Docker-Image:** Es enthält zusätzlich Debian-Pakete, PHP, nginx und supervisor unter
  ihren jeweiligen Lizenzen. Die Quellen der Debian-Pakete stehen unter
  https://sources.debian.org bereit.

## Grundgerüst

Teile des Projekts (Verzeichnisstruktur, Konfigurationsdateien, Bootstrap) stammen aus
dem Laravel-Anwendungsgerüst `laravel/laravel`:

> The MIT License (MIT)
>
> Copyright (c) Taylor Otwell
>
> Permission is hereby granted, free of charge, to any person obtaining a copy of this
> software and associated documentation files (the "Software"), to deal in the Software
> without restriction, including without limitation the rights to use, copy, modify,
> merge, publish, distribute, sublicense, and/or sell copies of the Software, and to
> permit persons to whom the Software is furnished to do so, subject to the following
> conditions:
>
> The above copyright notice and this permission notice shall be included in all copies
> or substantial portions of the Software.
>
> THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
> INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A
> PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
> HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF
> CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
> OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

## Frontend (in `public/build/` gebündelt)

| Paket | Version | Lizenz |
|---|---|---|
| `alpinejs` | 3.15.12 | MIT |
| `swagger-ui-dist` | 5.17.14 | Apache-2.0 |
| `tailwindcss` | 3.4.19 | MIT |
| `@tailwindcss/forms` | 0.5.11 | MIT |

## PHP-Pakete (Laufzeit)

| Paket | Version | Lizenz |
|---|---|---|
| `brick/math` | 1.0.0 | MIT |
| `carbonphp/carbon-doctrine-types` | 3.2.1 | MIT |
| `dflydev/dot-access-data` | 3.0.3 | MIT |
| `doctrine/inflector` | 2.1.1 | MIT |
| `doctrine/lexer` | 3.0.3 | MIT |
| `dragonmantank/cron-expression` | 3.6.1 | MIT |
| `egulias/email-validator` | 4.0.4 | MIT |
| `fruitcake/php-cors` | 1.4.0 | MIT |
| `graham-campbell/result-type` | 1.2.0 | MIT |
| `guzzlehttp/guzzle` | 8.5.2 | MIT |
| `guzzlehttp/promises` | 3.1.0 | MIT |
| `guzzlehttp/psr7` | 3.5.0 | MIT |
| `guzzlehttp/uri-template` | 2.0.1 | MIT |
| `laravel/framework` | 13.43.0 | MIT |
| `laravel/prompts` | 0.3.27 | MIT |
| `laravel/sanctum` | 4.5.0 | MIT |
| `laravel/serializable-closure` | 2.2.0 | MIT |
| `laravel/tinker` | 3.2.0 | MIT |
| `league/commonmark` | 2.10.7 | BSD-3-Clause |
| `league/config` | 1.2.0 | BSD-3-Clause |
| `league/flysystem` | 3.36.0 | MIT |
| `league/flysystem-local` | 3.35.3 | MIT |
| `league/mime-type-detection` | 1.17.0 | MIT |
| `league/uri` | 7.10.0 | MIT |
| `league/uri-interfaces` | 7.10.0 | MIT |
| `monolog/monolog` | 3.13.0 | MIT |
| `mpdf/mpdf` | 8.3.1 | GPL-2.0-only |
| `mpdf/psr-http-message-shim` | 2.0.1 | MIT |
| `mpdf/psr-log-aware-trait` | 3.0.0 | MIT |
| `myclabs/deep-copy` | 1.14.0 | MIT |
| `nesbot/carbon` | 3.15.1 | MIT |
| `nette/schema` | 1.4.0 | BSD-3-Clause oder GPL-2.0-only oder GPL-3.0-only |
| `nette/utils` | 4.1.6 | BSD-3-Clause oder GPL-2.0-only oder GPL-3.0-only |
| `nikic/php-parser` | 5.9.0 | BSD-3-Clause |
| `nunomaduro/termwind` | 2.4.1 | MIT |
| `paragonie/random_compat` | 9.99.100 | MIT |
| `phpoption/phpoption` | 1.10.0 | Apache-2.0 |
| `psr/clock` | 1.0.0 | MIT |
| `psr/container` | 2.0.2 | MIT |
| `psr/event-dispatcher` | 1.0.0 | MIT |
| `psr/http-client` | 1.0.3 | MIT |
| `psr/http-factory` | 1.1.0 | MIT |
| `psr/http-message` | 2.0 | MIT |
| `psr/log` | 3.0.2 | MIT |
| `psr/simple-cache` | 3.0.0 | MIT |
| `psy/psysh` | 0.12.24 | MIT |
| `ramsey/collection` | 2.1.1 | MIT |
| `ramsey/uuid` | 4.10.0 | MIT |
| `setasign/fpdi` | 2.6.10 | MIT |
| `symfony/clock` | 7.4.21 | MIT |
| `symfony/console` | 7.4.23 | MIT |
| `symfony/css-selector` | 7.4.22 | MIT |
| `symfony/deprecation-contracts` | 3.8.1 | MIT |
| `symfony/error-handler` | 7.4.22 | MIT |
| `symfony/event-dispatcher` | 7.4.17 | MIT |
| `symfony/event-dispatcher-contracts` | 3.8.1 | MIT |
| `symfony/finder` | 7.4.23 | MIT |
| `symfony/http-foundation` | 7.4.23 | MIT |
| `symfony/http-kernel` | 7.4.23 | MIT |
| `symfony/mailer` | 7.4.22 | MIT |
| `symfony/mime` | 7.4.22 | MIT |
| `symfony/polyfill-ctype` | 1.37.0 | MIT |
| `symfony/polyfill-intl-grapheme` | 1.43.3 | MIT |
| `symfony/polyfill-intl-idn` | 1.43.0 | MIT |
| `symfony/polyfill-intl-normalizer` | 1.43.0 | MIT |
| `symfony/polyfill-mbstring` | 1.43.3 | MIT |
| `symfony/polyfill-php80` | 1.43.0 | MIT |
| `symfony/polyfill-php82` | 1.43.0 | MIT |
| `symfony/polyfill-php83` | 1.43.0 | MIT |
| `symfony/polyfill-php84` | 1.43.0 | MIT |
| `symfony/polyfill-php85` | 1.44.0 | MIT |
| `symfony/polyfill-php86` | 1.43.0 | MIT |
| `symfony/polyfill-time` | 1.42.0 | MIT |
| `symfony/polyfill-uuid` | 1.43.0 | MIT |
| `symfony/process` | 7.4.22 | MIT |
| `symfony/routing` | 7.4.23 | MIT |
| `symfony/service-contracts` | 3.8.1 | MIT |
| `symfony/string` | 7.4.22 | MIT |
| `symfony/translation` | 7.4.23 | MIT |
| `symfony/translation-contracts` | 3.8.1 | MIT |
| `symfony/uid` | 7.4.21 | MIT |
| `symfony/var-dumper` | 7.4.22 | MIT |
| `symfony/yaml` | 7.4.23 | MIT |
| `tijsverkoyen/css-to-inline-styles` | 2.4.0 | BSD-3-Clause |
| `vlucas/phpdotenv` | 5.8.0 | BSD-3-Clause |
| `voku/portable-ascii` | 2.1.1 | MIT |

## Liste aktualisieren

Nach einem Update der Abhängigkeiten die PHP-Liste neu erzeugen:

```bash
composer licenses --no-dev
```
