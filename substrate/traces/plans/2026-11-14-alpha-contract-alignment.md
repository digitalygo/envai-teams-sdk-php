---
document_type: mycelium-plan
plan_id: 2026-11-14-alpha-contract-alignment
status: in-progress
created_at: 2026-11-14
planner: orchestrator
baseline_version: 1
execution_owner: orchestrator
execution_started_at: 2026-11-14
last_updated_at: 2026-11-14
---

# Allineamento PHP SDK al contratto alpha

## Current execution snapshot

- **Status:** Allineamento completato; follow-up di pubblicazione della pull request ora autorizzato dall'utente.
- **Baseline identity:** `2026-11-14-alpha-contract-alignment`, versione 1.
- **Execution baseline:** SDK pulito su `feature/sync-teams-alpha-20261113`, creato da main `6957f70c9ca767583ddaa9f3a9a9f4a5be786313`. Fetch e fast-forward ripetuti alla ripresa, nessuna differenza rispetto a `origin/main`.
- **Active phase:** Follow-up V-001: commit, push del solo topic branch e pull request verso main.
- **Last verified checkpoint:** Preflight di pubblicazione: main remoto ancora `6957f70`, nessuna PR o branch remoto omonimo, tutti i 27 hash e il diff approvato invariati. Gate qualità/sicurezza e test precedenti restano riferiti agli stessi byte.
- **Last successful checks:** Composer validate/install/audit, Pint 586 file, PHPStan massimo, 2.343 test di base per PHP 8.2-8.5; Laravel 13 completo 2.343 test e 10.721 asserzioni senza skip; integrazione Laravel 11, 12, 13 su PHP 8.5 con 151 test ciascuna; actionlint, 91 test release tooling, whitespace e confronto storico. Security PASS sul pacchetto congelato.
- **Open blockers:** Nessuno.
- **Required approvals and gates:** L'utente autorizza ora la pull request dell'SDK, quindi commit convenzionale e normale push del topic branch. Gate e copertura 20/20 già verificati; nessun nuovo comportamento. Restano esclusi merge, push su main, tag, release, cambi di policy e modifiche del monorepo o della preview.
- **Next action:** Committare soltanto i 27 file approvati e i due record SDK, pubblicare il topic branch e aprire la PR verso main.

## Planner baseline

### Problem statement

L'SDK conserva snapshot del server `964c11fe72887329e8d06c2afb483442d643b290`, mentre il source alpha e la preview autorizzata sono ora `7133cae3042873073e3897f6733aa55314feca0d`. Occorre aggiornare il client senza espandere implicitamente il suo perimetro autorizzativo o modificare le integrazioni esistenti.

### Research and evidence

- [Istruzioni SDK](../../../AGENTS.md) e [comandi CI](../../../.github/workflows/ci.yml): Composer validate, Pint, PHPStan, audit, PHPUnit e verifiche delle integrazioni applicabili.
- [Provenienza corrente](../../../tests/fixtures/current-server/provenance.json): sei snapshot, sette fixture generate e quattro fixture condivise; 146 operazioni server e 78 operazioni SDK.
- [Guida di compatibilità](../../../docs/CURRENT_SERVER_COMPATIBILITY.md): posizioni dei costruttori stabili, campi opzionali aggiunti dopo `extra`, preservazione delle chiavi sconosciute e nessun retry automatico delle mutazioni.
- Source autorevole: repository locale `../1-1-5-envai-aaas`, commit `7133cae`; contratti in `packages/contracts/` e direttive in `apps/control-plane/substrate/directives/`. DRC streaming, modello chat, orchestrazione, tenancy e client tools governano selezione del modello, scope e opt-in. Le EXP auth, billing, team e storia vietano espansioni di diritti e spesa implicita. L'SDK non possiede DRC o EXP locali, assenza verificata dai locator.
- Analisi del codice: mancano `model` nei percorsi di invio, `selectable` nel DTO LLM e tre metadati artifact. Il trasporto tratta erroneamente ogni `strict_model` come nativo; il server lo supporta indipendentemente dall'estensione sulle normali richieste di messaggio.
- Correzione di un'ipotesi iniziale: il contratto workspace non aggiunge `read_only`. Aggiunge `wiki_sources` e `wiki`, anche sulle entry, con preservazione esplicitamente prevista attraverso `extra` già esistente.
- I vecchi pin di capture HTTP, capabilities e ATP v2 sono evidenze storiche distinte, non etichette da sostituire globalmente.

### Hypotheses, decisions, and rationale

Aggiornare una sola slice coerente, guidata dai contratti e senza race: il comportamento è specificato, non richiede un nuovo algoritmo o architettura. La sicurezza richiede un controllo preciso dell'eccezione per `strict_model`, non l'eliminazione generale dei gate nativi.

- Il nuovo snapshot espone 147 operazioni server, ma il sottoinsieme SDK resta 78, inclusi tre metodi di gestione chiavi disabilitati localmente. Il nuovo endpoint di notifica immagini è un receiver del backend, non un nuovo metodo consumer.
- `model` è un parametro opzionale finale, trasmesso esattamente senza normalizzazioni, lookup obbligatorio o modifica dei default. Null nel costruttore significa omissione sul wire. `strictModel: true` senza modello resta valido come richiesta della policy configurata, con decisione autorevole del server.
- Solo POST canonico ATP v3 `/chats/{chat_id}/messages` ammette `strict_model` senza opt-in o capability nativa. Gli altri campi e percorsi nativi mantengono i propri gate. Negotiation, handshake SSE, idempotency, NeverRetry e legacy read-only non cambiano.
- `selectable` è informativo e distingue false da assenza. `AgentArtifactEvent` espone path, generation ID ed expiry opzionali senza download, storage, rendering o invocazioni automatiche.
- Managed wiki resta metadato preservato attraverso `extra`; nessun flag inventato, servizio amministrativo, bypass Laravel o accesso FFS diretto.
- Nessuna modifica di dipendenze, CI, schema, ruoli, API key, finanze, runtime già verificato o versioni pubblicate.

### Planned phases

#### Phase 1: snapshot e comportamento compatibile

- **Objective:** Allineare snapshot e SDK alla revisione alpha esatta.
- **Planner predictions:** Mirror OpenAPI documentazione/runtime, eventi e wiki; provenienza e inventario derivato; request, tre helper, sender e trasporto; DTO LLM, artifact e relativo decoder. ATP v2 e capture storiche invariati. Schema JSON di successo resta 126 perché il receiver aggiunto risponde 204 senza JSON.
- **Proposed steps:** Copiare byte esatti dei sei snapshot, aggiornare attributi e inventario; implementare model e l'eccezione di trasporto strettamente limitata; aggiungere proprietà opzionali dopo le posizioni esistenti e test di metadati wiki senza nuovi DTO obbligatori.
- **Predicted verification:** Test di mirror e hash/blob/byte, inventario 147/78, firme di costruttore e roundtrip, invio in tutti i percorsi, policy nativa negativa e compatibilità storica.
- **Completion criterion:** Ogni nuovo dato è esposto correttamente o preservato come previsto; nessun comportamento nativo o legacy allargato per errore.

#### Phase 2: documentazione e test completi

- **Objective:** Rendere verificabile e utilizzabile la nuova superficie senza promettere una release pubblicata o compatibilità live non testata.
- **Planner predictions:** Guide di compatibilità e uso, correzione dei conteggi obsoleti; test unitari e di integrazione applicabili, con risorse locali isolate e credenziali sintetiche.
- **Proposed steps:** Eseguire tutti i comandi di qualità e test dichiarati per l'SDK, misurare il delta, verificare le integrazioni upstream e documentare i limiti o skip preesistenti senza introdurne di nuovi.
- **Predicted verification:** Composer validate/Pint/PHPStan/audit/PHPUnit, coverage del delta, confronto storico, Markdown e whitespace. Database soltanto usa-e-getta; nessuna inferenza, chiave o account reale.
- **Completion criterion:** Tutti i test delta passano, copertura 80-100%, documentazione fedele e nessuna risorsa effimera propria attiva.

#### Phase 3: verifica indipendente e gate

- **Objective:** Accettare soltanto il delta verificato e preservare il runtime già aggiornato.
- **Planner predictions:** Ispezione del diff completo, riproduzione indipendente, manifest congelato, judgment qualità e review sicurezza sulla selezione/gating e sui metadati.
- **Proposed steps:** Verificare scope e test, eseguire i due gate e correggere solo rilievi concreti senza cambiare requisiti. Lasciare i cambiamenti sul topic branch, senza pubblicare.
- **Predicted verification:** Comandi riprodotti dal parent, verdict PASS, controllo finale di sorgente e risorse, record operativo compatto.
- **Completion criterion:** SDK aggiornato sul branch richiesto, gate e limiti riportati, nessun intervento sulla preview.

## Execution ledger

### Ledger rules

Solo l'orchestrator aggiorna snapshot e checkpoint. La baseline resta immutabile; deviazioni materiali sono aggiunte al ledger. Gli artefatti sotto `substrate/traces/` restano fuori dal pacchetto di codice dei reviewer.

### Phase 1 execution checkpoints

#### Checkpoint 2026-11-14: baseline e perimetro confermati

- **Event:** Ripresa SDK dopo il completamento della preview richiesto dall'utente.
- **Planner prediction:** Le modifiche sono un adattamento preciso del contratto, non nuova gestione umana o finanziaria.
- **Subagent claims:** Quattro analyzer e un pattern finder individuano le differenze e correggono l'ipotesi non supportata `read_only`.
- **Orchestrator finding:** Le firme e il loop di capability attuali confermano il gap di model e il gate strict eccessivo; `extra` già copre la wiki. Il comportamento del server è autorevole e non verrà sostituito da una lista modelli nel client.
- **Independently verified facts:** SDK clean, base main sincronizzata `6957f70`, branch richiesto; source alpha `7133cae` e preview già verificata. Istruzioni, CI, provenienza e file principali letti. Nessuna modifica SDK preesistente da scartare.
- **Decision and impact:** Inizio di una slice unica, senza dev B e senza altre operazioni runtime.
- **Next action:** Implementazione delegata e verifica indipendente.

#### Checkpoint 2026-11-14: adattamento del contratto verificato

- **Event:** Fase 1 conclusa, 27 file di codice, test, snapshot e guide modificati; nessuna dipendenza, API, CI o diritto aggiunto.
- **Subagent claims:** A consegna una parte dell'implementazione, C completa snapshot, regressioni e documentazione senza dev B.
- **Orchestrator finding:** Tutti i nuovi parametri sono finali, i campi DTO seguono le posizioni precedenti e il gate strict è esentato soltanto sul POST canonico dei messaggi. Le altre estensioni restano opt-in; nessuna selezione persiste fra invii. Metadati wiki passano attraverso `extra` senza inventare `read_only`.
- **Independently verified facts:** Diff reale letto; sei mirror, byte, SHA-256 e Git blob confrontati con `7133cae`; inventario 147 server, 78 SDK, 39 eventi e 126 schemi JSON. ATP v2 e capture storiche invariati. Tutti i 27 file cambiati sono byte-identici nelle sei copie private di verifica, dopo avere sincronizzato un solo test mancante nelle copie Laravel 11 e 12 ed eseguito nuovamente quel file.
- **Decision and impact:** Nessun altro intervento sulla preview verificata, nessun commit o pubblicazione. Conservare il delta e le prove senza espandere il client a receiver, amministrazione o finanze.
- **Next action:** Verifica della matrice dichiarata e gate.

### Phase 2 execution checkpoints

#### Checkpoint 2026-11-14: suite complete e copertura riprodotte dal parent

- **Event:** Suite e analisi indipendenti passano sul codice corrente; quattro abbinamenti CI restano da completare.
- **Independently verified facts:** PHP 8.2, 8.3, 8.4 e 8.5 eseguono ciascuno 2.343 test senza filtri e 10.242 asserzioni. I 120 skip del core dipendono soltanto dai pacchetti Illuminate assenti; sono tutti eseguiti nel run reale Laravel 13 completo con 2.343 test, 10.721 asserzioni e zero skip. Laravel 11-13 su PHP 8.5 passa PHPStan reale e 151 test di integrazione ciascuno. Composer, Pint, PHPStan, audit, actionlint e release tooling passano.
- **Coverage evidence:** Parent raccoglie il proprio Clover con Xdebug e lo incrocia con le righe eseguibili aggiunte dal diff: 20/20, 100%. Le dichiarazioni dei parametri non eseguibili non gonfiano il denominatore. Hash del manifest dei 27 file `9586225a17e223232a1ff21f62e69149e3dd1669e4866e1155cba46e5e2951fd`; diff completo `5ed1f7ed2feadefcdcc6d3f93cfc962df44d876d3487adc6e2e5466994358e13`.
- **Orchestrator finding:** I precedenti errori cURL 56 negli harness Alpine non erano una ragione per escludere i test. Il server TCP di test richiede sockets; predisposte estensioni di test in container usa-e-getta e riprodotte tutte le suite complete, senza modificare un test SDK. Le precedenti limitazioni restano nei log storici, superate dalle nuove prove.
- **Artifacts:** `/home/lorenzo/Documents/agent-evidence/2026-11-14-alpha-sdk/leader-verification-5K0LpFtP`, con log completi, Clover, manifest e diff congelati; `MATRIX_HARNESS_COMPLETION.md` descrive l'ambiente. Database, container e rete propri rimossi; nessuna porta DB pubblicata.
- **Next action:** Completare gli abbinamenti Laravel/PHP mancanti senza cambiare l'implementazione.

#### Checkpoint 2026-11-14: tutti gli abbinamenti CI esatti verificati

- **Event:** Fase 2 conclusa senza modificare un file SDK del pacchetto congelato.
- **Independently verified facts:** Parent legge ed esegue l'harness completo con installazione delle dipendenze CI sotto la versione PHP reale: Laravel 11/PHP 8.2.35; Laravel 12/PHP 8.2.35; Laravel 12/PHP 8.3.36; Laravel 13/PHP 8.4.27. Ogni installazione, PHPStan Laravel e selezione CI con `--fail-on-skipped` esce 0, con 151 test e 580 asserzioni. Laravel 13/PHP 8.5 era già verificato indipendentemente. Nessuna piattaforma ignorata o variazione del Composer SDK.
- **Artifacts:** Run indipendente `ci-laravel-run-dDNHO3x9`, quattro log specifici e `exit-codes.log` nell'evidence SDK; harness `ci-laravel-matrix-run.sh` e `ci-laravel-matrix-container.sh`. Il precedente tentativo sviluppatore con lock incompatibile e marker shell errati resta conservato come invalido e non sostiene alcun PASS.
- **Additional checks:** Parent esegue anche npm ci, patch, 91 test e audit del release tooling su Node 24.20.0, exit 0. Nuovo confronto conferma hash del diff e manifest SDK invariati; database temporaneo e rete rimossi.
- **Decision and impact:** Risolto il rilievo quality con prove, non con un'eccezione. Nessun ulteriore intervento sulla preview.
- **Next action:** Judgment finale sullo stesso pacchetto, poi chiusura.

### Phase 3 execution checkpoints

#### Checkpoint 2026-11-14: sicurezza PASS, matrice CI richiesta dal quality gate

- **Event:** Security-review-specialist-a PASS sul delta congelato di 27 file; quality-gate-a FAIL per quattro abbinamenti non eseguiti.
- **Orchestrator finding:** Il rilievo riguarda la verifica, non una nuova policy prodotto: eseguire Laravel 11/PHP 8.2, Laravel 12/PHP 8.2 e 8.3, Laravel 13/PHP 8.4 con installazione, PHPStan e test CI esatti. Non sostituire quei risultati con Laravel su PHP 8.5 e non reinterpretare il FAIL come advisory.
- **Decision and impact:** Nessuna modifica al delta SDK o ai suoi hash; chiusura sospesa solo sul completamento verificabile della matrice. Test privati senza credenziali reali, provider o runtime preview.
- **Next action:** Handoff di verifica mirato e successiva riproduzione del parent, quindi nuovo judgment.

#### Checkpoint 2026-11-14: gate SDK PASS e chiusura

- **Event:** Fase 3 conclusa; quality-gate-a restituisce PASS dopo la riproduzione dei quattro abbinamenti richiesti.
- **Orchestrator finding:** Il pacchetto resta quello esaminato dalla security review: 27 file, manifest `9586225a17e223232a1ff21f62e69149e3dd1669e4866e1155cba46e5e2951fd`, diff `5ed1f7ed2feadefcdcc6d3f93cfc962df44d876d3487adc6e2e5466994358e13`. Nessuna modifica di codice fra i due esiti; non occorre reinterpretare i precedenti FAIL o una vecchia review come PASS di un altro delta.
- **Independently verified facts:** Parent mantiene copie e log delle suite complete, confronta il proprio Clover col diff e conferma 20/20 righe eseguibili. Confronto storico e snapshot byte/blob conformi; branch da main invariato. Le copie private hanno eseguito le dipendenze effettive, non stub del framework. Risorse di test rimosse, nessun provider o account reale usato.
- **Gate evidence:** Quality-gate-a PASS e security-review-specialist-a PASS sul pacchetto sopra. La sicurezza verifica la stretta eccezione del trasporto, nessun retry di mutazioni, metadati senza effetti collaterali e scope progetto invariato.
- **Documentation checks:** Markdown dei nuovi record, Contract 3 e independent jobs PASS; 29 target relativi verificati. README e guida di compatibilità mantengono 21 diagnostiche Markdownlint preesistenti (MD012 e MD060): confronto con HEAD e mappatura delle righe confermano che tutte sono su righe invariate, nessuna introdotta dal delta. MD013 e duplicati di titoli tra sezioni Mycelium non sono limiti previsti dallo standard e sono esclusi dal comando. Segnale fuori scope, nessuna pulizia di stile estranea o modifica al pacchetto congelato.
- **Decision and impact:** Lavoro completato, file lasciati modificati sul topic branch. Nessun commit, push, tag, release o aggiornamento di un'app consumer.
- **Next action:** Consegna del risultato e del record operativo, senza altre azioni runtime.

#### Checkpoint 2026-11-14: pubblicazione della PR autorizzata

- **Event:** Dopo la consegna, l'utente chiede esplicitamente una pull request dell'SDK.
- **Planner prediction:** La baseline della fase 3 si fermava alla consegna sul branch senza pubblicazione.
- **Orchestrator finding:** Il seguito autorizza commit e push del topic branch, non merge o release. Si riusano piano e operazione esistenti; nessun nuovo piano o prodotto.
- **Independently verified facts:** Chezmoi aggiornato; repository canonico `digitalygo/envai-teams-sdk-php`, default main, topic approvato e base remota `6957f70` invariati. Nessuna PR o ref remoto omonimo. Tutti i 27 file corrispondono al manifest `9586225a...` e il diff al digest `5ed1f7ed...`; indice inizialmente vuoto. Le sole altre modifiche SDK sono i due record di questo task. Il monorepo Envai Teams resta escluso.
- **Decision and impact:** Pubblicazione standard senza force push, bypass, merge o trigger manuale di release. Nessuna nuova esecuzione applicativa o modifica di comportamento; test e coverage nuovi N/A per il solo passaggio Git, con continuità dei precedenti PASS dimostrata dagli hash. La CI remota dovrà validare la PR.
- **Next action:** Commit convenzionale, push del topic branch e creazione della PR.

## Plan-variation ledger

### Variation V-001: pubblicazione della pull request SDK

- **Baseline reference:** Fase 3, proposta di consegna sul topic branch senza pubblicare.
- **Discovered evidence:** Nuova richiesta esplicita dell'utente dopo la consegna verificata; branch e base remota ancora sincronizzati, pacchetto approvato invariato.
- **Decision:** Accettata la sola pubblicazione del lavoro già verificato tramite commit, normale push del topic branch e PR verso main.
- **Scope and downstream impact:** Metadati Git e due record SDK; nessun nuovo file eseguibile, dipendenza, workflow, test o contratto. Nessuna pubblicazione del monorepo o modifica runtime.
- **Approval:** Richiesta utente del 2026-11-14. Merge, tag, release, policy e push su main restano non autorizzati.
- **Resolution:** In corso; chiusura dopo verifica del riferimento remoto e della PR effettiva.

## Closure evidence

### Final outcome

SDK allineato alla revisione alpha `7133cae`: modello per turno, strict normale indipendente dai task nativi, selectability informativa, metadati artifact e snapshot correnti. Conservati i costruttori, lo scope progetto, gli opt-in, la modalità storica e i metadati wiki in `extra`. La preview era già stata aggiornata e verificata prima di riprendere questi cambiamenti.

### Quality and security evidence

Deterministico completo e gate SDK PASS distinti da quelli della preview. PHP 8.2-8.5, tutti i cinque abbinamenti Laravel CI, full Laravel 13 senza skip, 20/20 righe del delta coperte, Composer/Pint/PHPStan/audit/actionlint/release tooling e integrità dei contratti verificati dal parent. I log invalidi o precedenti con filtri non sostengono la chiusura e restano riconoscibili nell'evidence.

### Operation record

[Allineamento alpha del PHP SDK](../operations/2026-11-14-alpha-contract-alignment.md), distinto dal precedente record sugli original chat files.
