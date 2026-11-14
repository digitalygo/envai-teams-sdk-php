---
status: completed
created_at: 2026-11-14
updated_at: 2026-11-14
files_edited:
  - README.md
  - docs/CONTRACT_V3.md
  - docs/CURRENT_SERVER_COMPATIBILITY.md
  - docs/events.json
  - docs/independent-jobs.md
  - docs/openapi.yaml
  - docs/wiki.json
  - resources/http/openapi.yaml
  - src/Client/ChatRuntime.php
  - src/Client/Internal/ChatsMessageSender.php
  - src/Http/Client/ContractTransport.php
  - src/Models/Chat/ChatMessageRequest.php
  - src/Models/Model/LlmModelEntry.php
  - src/Streaming/Atp/AtpEventDecoder.php
  - src/Streaming/Events/AgentArtifactEvent.php
  - tests/Background/BackgroundHttpTest.php
  - tests/Client/ChatRuntimeTest.php
  - tests/Client/CurrentServerCompatibilityTest.php
  - tests/Client/InventoryTest.php
  - tests/Http/Client/StrictValidationTransportTest.php
  - tests/Models/ChatTest.php
  - tests/Models/ModelTest.php
  - tests/Models/WorkspaceFileTest.php
  - tests/Streaming/Atp/CurrentServerContractTest.php
  - tests/Streaming/Events/AtpEventDecoderTest.php
  - tests/fixtures/current-server/operations.json
  - tests/fixtures/current-server/provenance.json
  - substrate/traces/plans/2026-11-14-alpha-contract-alignment.md
rationale:
  - Aggiornare il client al contratto alpha corrente dopo la preview VPN richiesta dall'utente.
  - Conservare costruttori, scope progetto, opt-in e sicurezza delle mutazioni.
supporting_docs:
  - ../plans/2026-11-14-alpha-contract-alignment.md
  - ../../../docs/CURRENT_SERVER_COMPATIBILITY.md
  - ../../../docs/CONTRACT_V3.md
  - ../../../tests/fixtures/current-server/provenance.json
---

# Allineamento PHP SDK al contratto alpha

## Summary of changes

L'SDK sul branch `feature/sync-teams-alpha-20261113`, creato da main
`6957f70c9ca767583ddaa9f3a9a9f4a5be786313`, è allineato al server
`7133cae3042873073e3897f6733aa55314feca0d`. L'utente aveva richiesto prima
l'aggiornamento della preview VPN; il parent lo ha concluso e verificato prima
di riprendere l'SDK. Il [living plan](../plans/2026-11-14-alpha-contract-alignment.md)
conserva baseline, gate e checkpoint.

Le modifiche restano nel worktree. Nessun commit, push, tag o release è stato
eseguito e nessuna applicazione consumer è stata aggiornata implicitamente.

## Technical reasoning

- `model` è un parametro finale opzionale nel DTO, nei tre helper e nel sender.
  Null omette il campo, la stringa resta esatta e la scelta non persiste tra invii.
  `strict_model: true` senza modello resta una richiesta valida della policy
  configurata nel server.
- Il trasporto esenta dal gate strict nativo solo il POST canonico ATP v3 dei
  messaggi. Restano i gate delle altre operazioni e dei campi nativi, la
  negotiation, gli acknowledgment e l'opt-in disattivato per default.
- `selectable` è metadato informativo opzionale. I nuovi campi artifact sono
  stringhe opzionali aggiunte dopo `extra`; non provocano download, rendering,
  storage o richieste HTTP. Envelope grezzi e campi sconosciuti restano accessibili.
- `wiki_sources` e `wiki` restano nei metadati `extra`, anche sulle entry figlie.
  Il contratto non aggiunge `read_only`: non è stato inventato un flag o un bypass.
- I sei snapshot correnti e il mirror OpenAPI runtime coincidono byte per byte
  con il commit alpha. Le 147 operazioni del contratto server non espandono il
  sottoinsieme SDK di 78: tre metodi di gestione chiavi falliscono localmente,
  75 restano potenzialmente richiamabili secondo credenziali e capability.
  Il nuovo receiver di notifica immagini non è un metodo consumer.

## Impact assessment

Le firme precedenti, inclusa la posizione di `extra`, restano compatibili.
Attachment ordinati, idempotency key, barriera SSE prima dell'invio, NeverRetry
per le mutazioni e lettura storica ATP v2 non cambiano. I vecchi pin e le capture
storiche non sono stati rietichettati.

Nessun nuovo diritto umano o finanziario, endpoint, provider, dipendenza SDK,
workflow CI, schema di piattaforma o versione di storage è stato aggiunto.
La preview già aggiornata è rimasta invariata durante l'SDK. Non sono state
usate chiavi reali, richieste Envai a pagamento o database applicativi.
Database e server di test erano usa-e-getta, senza porte pubblicate, e sono
stati rimossi; copie di verifica e log restano disponibili.

## Validation steps

Il parent ha letto il diff e riprodotto personalmente i controlli:

- Suite complete e senza filtri su PHP 8.2, 8.3, 8.4 e 8.5: 2.343 test per run.
  I 120 skip del core sono integrazioni Illuminate opzionali, tutte eseguite
  nella suite completa Laravel 13/PHP 8.5: 2.343 test, 10.721 asserzioni e zero skip.
- Tutti i cinque abbinamenti Laravel della CI: 11/PHP 8.2, 12/PHP 8.2,
  12/PHP 8.3, 13/PHP 8.4 e 13/PHP 8.5. Ogni installazione delle dipendenze reali,
  PHPStan Laravel e selezione CI con `--fail-on-skipped` passa: 151 test e
  580 asserzioni per abbinamento.
- Composer validate/install/audit, Pint su 586 file, PHPStan massimo, actionlint,
  release tooling su Node 24 con 91 test e audit, whitespace e confronto dei
  mirror passano. Il confronto include byte, SHA-256, blob Git, inventario
  147/78, 39 eventi e 126 schemi JSON di successo.
- Xdebug raccolto dal parent e confrontato con il diff: 20/20 righe eseguibili
  aggiunte o modificate, 100%. I parametri dichiarativi non eseguibili non
  gonfiano il denominatore.
- Il problema cURL 56 dei primi harness Alpine è stato risolto predisponendo
  sockets, pcntl e driver PDO nei soli container temporanei. Tutti i test cURL
  sono poi passati, senza esclusioni o modifiche di test. I log intermedi
  invalidi o incompleti restano distinti dalle prove finali.
- Quality-gate-a e security-review-specialist-a PASS sullo stesso pacchetto
  congelato: manifest di 27 file
  `9586225a17e223232a1ff21f62e69149e3dd1669e4866e1155cba46e5e2951fd`,
  diff `5ed1f7ed2feadefcdcc6d3f93cfc962df44d876d3487adc6e2e5466994358e13`.
  Il precedente FAIL sulla matrice è stato risolto con gli abbinamenti esatti,
  senza deroga né modifica del delta.

Markdown dei nuovi record e delle altre guide controllate passa; 29 target
relativi sono validi. README e guida di compatibilità mantengono 21 diagnostiche
preesistenti MD012/MD060. Il confronto con HEAD mappa ogni diagnostica a una
riga invariata: nessuna è introdotta dal delta. Restano segnalazioni fuori
scope, senza pulizia di stile estranea. MD013 e titoli ripetuti tra sezioni
Mycelium sono esclusi perché non sono limiti imposti dallo standard condiviso.

Le prove indipendenti sono in
`/home/lorenzo/Documents/agent-evidence/2026-11-14-alpha-sdk/leader-verification-5K0LpFtP`.
I quattro abbinamenti aggiuntivi sono nel run `ci-laravel-run-dDNHO3x9` dello
stesso evidence root; i relativi script conservano i comandi riproducibili.
Queste prove certificano il client e il contratto, non una nuova versione
Packagist o un turno reale su provider.

## Follow-up 2026-11-14: pull request autorizzata

L'utente chiede ora la PR dell'SDK verso `main`. La pubblicazione riusa il
branch `feature/sync-teams-alpha-20261113`, i 27 file già verificati e i due
record SDK. Il preflight conferma main remoto `6957f70`, nessun ref o PR
omonimo, manifest e diff invariati, nessuna modifica staged preesistente.

Sono autorizzati commit convenzionale e normale push del solo topic branch.
Non sono autorizzati merge, tag, release, push su main, modifiche della policy
o pubblicazione delle modifiche nel monorepo Envai Teams. Test e copertura
nuovi sono N/A per il passaggio Git; i precedenti PASS restano applicabili agli
stessi byte. L'esito remoto e la PR saranno registrati dopo la creazione.
