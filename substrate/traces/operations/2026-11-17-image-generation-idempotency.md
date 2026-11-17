---
status: completed
created_at: 2026-11-17
files_edited:
  - src/Client/ImagesService.php
  - tests/Client/ImagesIdempotencyTest.php
  - README.md
  - substrate/traces/operations/2026-11-17-image-generation-idempotency.md
rationale:
  - Esporre la chiave di idempotenza opzionale nella facade immagini riutilizzando il requester esistente.
  - Preservare compatibilità, validazione centralizzata e divieto di retry automatico delle generazioni.
supporting_docs:
  - ../../../README.md#image-generation-idempotency
  - ../../../docs/openapi.yaml
  - ../../../AGENTS.md
  - ../../../.github/workflows/ci.yml
---

# Idempotenza opzionale per la generazione immagini

## Summary of changes

`ImagesService::generate()` accetta ora `?string $idempotencyKey = null` come secondo argomento e lo inoltra al parametro dedicato di `TypedRequester::request()`. La risposta rimane `ImageGenerationAcceptedRecord`.

Il lavoro riguarda esclusivamente `digitalygo/envai-teams-sdk-php`, sul branch `feature/idempotency` confermato dall'utente. Baseline pulita e sincronizzata con `origin/main`: `e14f2052fbdff7bd5907169b45dcd3d60f8364d1`. Non esistevano modifiche locali da preservare. `CONTRIBUTING`, `substrate/directives/` e `substrate/expectations/` risultavano assenti.

## Technical reasoning

Il requester esponeva già il parametro opzionale e la validazione richiesta: da 1 a 255 byte ASCII nell'intervallo `0x21`–`0x7e`. La modifica riutilizza questo percorso, come le facade chat e wiki, senza duplicare la validazione o introdurre dipendenze.

- Argomento omesso o `null`: nessun header `Idempotency-Key`.
- Chiave presente: valore esatto del chiamante, senza generazione, normalizzazione o persistenza tra chiamate.
- JSON, query, autenticazione Bearer, validazione locale del payload, risposta tipizzata e mapping degli errori restano invariati.
- `RetrySafety::NeverRetry` resta esplicito anche con chiave e retry policy configurata.
- Il divieto case-insensitive di `Idempotency-Key` negli header generici di `EnvaiConfig` rimane invariato.

I 34 nuovi casi PHPUnit usano il fake esistente. Coprono compatibilità a un argomento, `null`, payload e risposta invariati, chiavi successive indipendenti, ripetizione esplicita del chiamante, limiti inclusivi, caratteri vietati e header riservato. Gli errori di connessione e di risposta mantengono l'eccezione originale; HTTP 409, 429, 500, 502, 503 e 504 mantengono il mapping esistente senza consumare la risposta sentinella di un eventuale secondo POST. È coperta anche la precedenza di `provider_error` su HTTP 503.

## Impact assessment

La nuova firma è retrocompatibile con le chiamate esistenti:

```php
public function generate(
    ImageGenerationRequest $request,
    ?string $idempotencyKey = null,
): ImageGenerationAcceptedRecord
```

La documentazione descrive il contratto server incluso nel repository: stessa operazione con stessa chiave e stesso payload, chiavi diverse per operazioni diverse, possibile 409 con payload diverso, deduplicazione scoped al progetto e alla vita della generazione. Dopo scadenza o purge la chiave può identificare una nuova operazione. Non viene promesso exactly-once indefinito né replay o retry automatico dell'SDK.

Queste garanzie server provengono da `docs/openapi.yaml`, non da una verifica live: un transport fake verifica soltanto il comportamento client. Non sono stati modificati backend, frontend, server Teams, consumer o copie vendor di altri progetti. Nessuna chiamata provider, commit, push, pubblicazione o deployment.

Il consumer Envai dovrà selezionare una futura release revisionata contenente la modifica, verificare il proprio vincolo Composer e aggiornare `composer.lock`. Il passaggio dal commit consumer `4e55537706da258a76dd47949090a5da7f70f3a0` richiede anche la revisione delle altre differenze dell'SDK. La persistenza applicativa della coppia chiave/payload resta responsabilità del consumer; non va modificato direttamente `vendor`.

## Validation steps

L'orchestratore ha ispezionato i file e riprodotto i controlli, indipendentemente dai preflight degli sviluppatori.

- `composer validate --strict`, `composer install --prefer-dist --no-progress --no-interaction`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` e `composer audit`: PASS, inclusa la verifica quality su PHP 8.2 prevista dalla CI.
- `actionlint .github/workflows/*.yml` e `git diff --check`: PASS. Nessuna modifica alla release automation, quindi i test release-tools non erano pertinenti.
- Suite delta e regressioni selezionate: 158 test, 961 asserzioni, PASS. Il nuovo file isolato contiene 34 casi e 182 asserzioni.
- Suite core completa su PHP 8.2.35, 8.3.36, 8.4.27 e 8.5.9: 2.377 test, 10.424 asserzioni e 120 skip per ciascuna esecuzione. Gli skip riguardano le integrazioni Illuminate opzionali assenti nel core.
- Abbinamenti Laravel/PHP della CI: 11/8.2, 12/8.2, 12/8.3, 13/8.4 e 13/8.5. Per ciascuno, `vendor/bin/phpstan analyse -c phpstan.laravel.neon` e `vendor/bin/phpunit tests/Integration/Laravel tests/Sync/State/Pdo/MysqlIdentityTest.php --fail-on-skipped`: PASS, 151 test, 580 asserzioni e zero skip.
- Suite completa PHP 8.5.12, Laravel 13, MySQL 8 e PostgreSQL 16 con PCOV e `--fail-on-skipped`: PASS, 2.377 test, 10.903 asserzioni e zero skip.
- Coverage: 100% del delta eseguibile, ossia la nuova linea di inoltro 102 coperta 34 volte; firma e PHPDoc sono dichiarativi. Il metodo `generate()` ha 24/24 linee eseguibili coperte. Coverage complessiva: 10.467/11.722 statement, 89,29%; `ImagesService`: 123/124, 99,19%.
- Quality judgment indipendente `quality-gate-a`: PASS. Security review indipendente `security-review-specialist-a`: PASS, nessun rilievo bloccante e nessuna deriva dello snapshot.

Le otto copie isolate di verifica avevano codice e test identici ai file revisionati. Una correzione solo testuale del README sul divieto degli header globali è stata sincronizzata prima dei gate; nessuna modifica eseguibile dopo i test. Le prove valide sono nei log `matrix-php*-final.log`; i tentativi iniziali con mount SELinux esclusivo o nome database incompatibile con il fixture Laravel sono conservati come diagnostica, non come evidenza di superamento. La preparazione è stata corretta senza modificare o escludere test.

Log e Clover restano nella directory ignorata `debug/idempotency-verification/`, inclusi `session.patch`, `full-clover.xml` e `full-coverage.txt`. I container, i relativi volumi anonimi e le copie temporanee con dipendenze sono stati rimossi. Le dipendenze normali del checkout e gli artefatti diagnostici ignorati non modificano il pacchetto versionato.

Il pacchetto revisionato comprende esclusivamente README, servizio e nuovo test; questa traccia di chiusura è fuori dallo scope dei gate. SHA-256 del relativo patch completo: `6c34a07e62e326d58081b2c7127394ec04095634065b77ecabeeca14ff477d4e`. Hash dei file confermati invariati dopo entrambi i gate:

```text
3514f702254b0666c6d12fa0075ef2e27d62cc7681ce0a320200235b50778fd1  README.md
0ec985ccb3644e0c5ea1cabe7bbd07e554fd6dfa83f6f11ee860b87bcceac75b  src/Client/ImagesService.php
0411802a63e7a1f7bc07df74a7c2aae31f36727bd335557b4c967e6aef3f222a  tests/Client/ImagesIdempotencyTest.php
```
