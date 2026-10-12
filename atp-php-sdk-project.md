# ATP PHP SDK

## Documento di progetto

- **Repository previsto:** `digitalygo/envai-teams-sdk-php`
- **Package Composer previsto:** `digitalygo/envai-teams-sdk-php`
- **Stato:** proposta approvata per l'implementazione
- **Versione del documento:** 1.0
- **Data:** 27 agosto 2026
- **Contratto Envai di riferimento:** branch `alpha`, commit `b3e9ae9`
- **Lingua del documento:** italiano
- **Lingua del codice e della documentazione API:** inglese

## 1. Sintesi

`digitalygo/envai-teams-sdk-php` sarà l'SDK PHP ufficiale per Envai.

La libreria offrirà due superfici complementari:

- un client tipizzato per le 52 operazioni HTTP dichiarate dal contratto OpenAPI;
- un runtime ATP v2 per chat, streaming SSE, replay, eventi, client tool e operazioni di controllo del turno.

Prima di creare chat o avviare turni, l'SDK potrà inoltre sincronizzare uno stato dichiarativo locale composto da:

- agenti;
- client tool;
- team.

La sincronizzazione non sarà modellata come una sequenza append-only di migrazioni. Sarà un reconciler dichiarativo che confronta:

- stato locale desiderato;
- ultimo stato applicato;
- stato remoto osservato.

L'esperienza d'uso ricorderà comunque i comandi di migrazione Laravel, con operazioni di validazione, pianificazione, applicazione e controllo dello stato.

I client tool sono user-scoped. Non dipendono dal workspace e sono condivisi fra tutti i workspace posseduti dallo stesso utente Envai.

## 2. Decisioni confermate

Le seguenti decisioni sono normative per il progetto:

- il repository si chiama `digitalygo/envai-teams-sdk-php`;
- il core resta framework-independent;
- il contratto nativo dei client tool appartiene al core;
- l'integrazione Laravel è opzionale;
- gli adapter Laravel AI e Prism sono moduli opzionali previsti dall'architettura, ma esclusi dalla prima implementazione;
- gli agenti sono definiti in documenti Markdown con frontmatter YAML;
- i team sono definiti in file PHP dichiarativi;
- i client tool sono classi PHP registrate esplicitamente;
- i tool sono user-scoped e non workspace-scoped;
- lo stato di sincronizzazione può essere salvato su file oppure in un database;
- il database e il relativo driver sono scelti dall'utilizzatore;
- lo state store è estendibile con implementazioni personalizzate;
- il rilevamento e la gestione dei conflitti sono obbligatori;
- le eliminazioni remote sono disabilitate per impostazione predefinita;
- la sincronizzazione automatica non avviene durante il boot del service provider Laravel;
- in produzione la modalità raccomandata è la sincronizzazione durante il deployment;
- gli ID remoti Envai non vengono salvati nei file dichiarativi versionati.

## 3. Obiettivi

### 3.1 Obiettivi funzionali

L'SDK deve:

- coprire tutte le operazioni del contratto OpenAPI Envai;
- esporre un'API pubblica PHP idiomatica;
- supportare ATP v2 e streaming SSE;
- preservare eventi e campi sconosciuti per forward compatibility;
- offrire modelli tipizzati per richieste, risposte, errori ed eventi;
- supportare client tool firmati e verificati;
- dichiarare agenti, tool e team in file locali versionabili;
- validare tutte le definizioni prima di qualsiasi scrittura remota;
- mostrare un piano delle modifiche prima dell'applicazione;
- applicare le modifiche in ordine di dipendenza;
- rilevare drift e conflitti;
- permettere la scelta dello state store;
- impedire ai helper di alto livello di usare definizioni locali non sincronizzate;
- essere utilizzabile in applicazioni Laravel e PHP generiche.

### 3.2 Obiettivi di qualità

L'SDK deve essere:

- deterministico;
- testabile senza chiamate reali a Envai;
- conservativo nei retry;
- sicuro nella gestione delle credenziali;
- idempotente per quanto consentito dalle API remote;
- trasparente sui limiti dell'implementazione `alpha`;
- compatibile con evoluzioni additive del protocollo;
- indipendente da Laravel nel core.

## 4. Fuori perimetro iniziale

Sono esclusi dalla prima implementazione:

- adapter Laravel AI;
- adapter Prism;
- compatibilità OpenAI;
- sincronizzazione di workspace, chat o completamenti;
- sincronizzazione di wiki, skill o `MEMORY.md` come definizioni applicative;
- dashboard grafica per il piano di sincronizzazione;
- server MCP;
- generazione automatica dei client tool da OpenAPI esterne;
- rollback transazionale distribuito delle modifiche remote;
- garanzia exactly-once per gli effetti esterni dei client tool;
- modifica automatica di risorse remote non precedentemente gestite;
- eliminazione automatica delle risorse remote non dichiarate.

## 5. Architettura del package

L'architettura logica è:

```text
digitalygo/envai-teams-sdk-php
├── core SDK
├── native tool contract
├── optional Laravel integration
├── optional Laravel AI adapter
└── optional Prism adapter
```

La prima release implementerà:

```text
digitalygo/envai-teams-sdk-php
├── core SDK
├── native tool contract
└── optional Laravel integration
```

Gli adapter Laravel AI e Prism saranno riservati nell'architettura e nella nomenclatura, ma non verranno implementati ora.

### 5.1 Moduli interni

```text
src/
├── Client/
├── Configuration/
├── Contracts/
├── Definitions/
│   ├── Agent/
│   ├── Team/
│   ├── Tool/
│   ├── Compiler/
│   ├── Manifest/
│   └── Validation/
├── Exceptions/
├── Http/
├── Models/
├── Pagination/
├── Streaming/
│   ├── Atp/
│   ├── Events/
│   ├── Replay/
│   └── Sse/
├── Sync/
│   ├── Conflict/
│   ├── Diff/
│   ├── Planner/
│   ├── Reconciler/
│   └── State/
├── Tools/
│   ├── Contracts/
│   ├── Execution/
│   ├── Security/
│   └── State/
└── Integration/
    └── Laravel/
```

Le directory future saranno:

```text
src/Integration/LaravelAi/
src/Integration/Prism/
```

## 6. Compatibilità

### 6.1 PHP

- PHP minimo: 8.2
- `strict_types=1` in tutti i file sorgente
- autoload PSR-4
- semantic versioning
- licenza MIT

### 6.2 Framework

Il core non dipende da Laravel.

L'integrazione Laravel è attivata soltanto quando l'applicazione dispone delle dipendenze Illuminate richieste.

La libreria deve poter essere usata:

- in Laravel;
- in Symfony;
- in applicazioni PHP senza framework;
- in worker CLI;
- in processi long-running, purché il consumer gestisca correttamente lifecycle e cleanup dello stream.

## 7. Fonti normative Envai

L'implementazione deve essere verificata contro:

- `packages/contracts/openapi.yaml`;
- `packages/contracts/events.json`;
- `docs/agent-team-protocol.md`;
- `docs/envai-api-sdk-foundation.md`;
- `packages/contracts/README.md`;
- `packages/contracts/EXAMPLES.md`.

Lo snapshot `alpha` di riferimento contiene:

- 36 path OpenAPI;
- 52 operazioni HTTP;
- ATP wire envelope v2;
- lifecycle dei turni basato su `turn.*`;
- client tool user-scoped sotto `/api/v1/tools`.

In caso di disaccordo:

- OpenAPI governa path, request, response, status code e autenticazione HTTP;
- `events.json` governa envelope e payload ATP tipizzati;
- ATP e il documento SDK governano semantica, lifecycle e limiti applicativi;
- il comportamento concreto di `alpha` deve essere documentato quando differisce dal contratto normativo.

## 8. Configurazione del client

La configurazione base comprende:

```php
final readonly class EnvaiConfig
{
    public function __construct(
        public string $baseUrl,
        public SensitiveString $apiKey,
        public ?string $jwksUrl = null,
        public string $connectionName = 'default',
        public int $connectTimeoutSeconds = 10,
        public int $requestTimeoutSeconds = 60,
        public ?int $streamTimeoutSeconds = null,
        public array $headers = [],
        public ?RetryPolicy $retryPolicy = null,
    ) {}
}
```

Requisiti:

- evitare duplicazioni di `/api/v1`;
- derivare JWKS dall'origine del base URL quando `jwksUrl` non è esplicito;
- non stampare mai l'API key;
- non includere credenziali nello state store;
- supportare transport injection;
- supportare logger PSR opzionale;
- supportare più connessioni Envai nella stessa applicazione.

## 9. Client HTTP

### 9.1 Servizi

`EnvaiClient` espone servizi dedicati:

```php
$envai->workspaces();
$envai->tools();
$envai->workspaceFiles();
$envai->chats();
$envai->completions();
$envai->agents();
$envai->teams();
$envai->models();
$envai->apiKeys();
$envai->images();
$envai->capabilities();
```

I nomi pubblici sono idiomatici. Gli `operationId` OpenAPI possono essere mantenuti nel layer generato o interno.

### 9.2 Transport

Il core definisce due astrazioni:

```php
interface HttpTransport
{
    public function send(HttpRequest $request): HttpResponse;
}

interface EventStreamTransport
{
    public function open(StreamRequest $request): EventStreamConnection;
}
```

La separazione è necessaria perché PSR-18 non standardizza adeguatamente tutte le esigenze di streaming e cancellazione.

Il transport deve supportare:

- JSON;
- multipart;
- download binari streaming;
- header di risposta;
- `Retry-After`;
- timeout distinti;
- cancellazione quando disponibile;
- resource cleanup deterministico.

## 10. Errori e retry

La gerarchia parte da `EnvaiException` e comprende almeno:

- `AuthenticationException`;
- `AccessDeniedException`;
- `NotFoundException`;
- `ConflictException`;
- `ValidationException`;
- `RateLimitException`;
- `ProviderException`;
- `FileException`;
- `ServerException`;
- `TransportException`;
- `ProtocolVersionException`;
- `ProtocolViolationException`;
- `ReplayGapException`;
- `UnsupportedCapabilityException`;
- `DefinitionsNotSynchronizedException`;
- `DefinitionConflictException`;
- `DefinitionDriftException`.

I retry automatici sono consentiti soltanto per operazioni classificate come retry-safe.

Non devono essere ritentate automaticamente operazioni ambigue quali:

- creazione di risorse;
- invio di messaggi;
- fork;
- restore;
- generazione immagini;
- risoluzione client tool;
- apply del reconciler quando la risposta remota è ambigua.

## 11. Streaming ATP

La libreria implementa manualmente il parser SSE e il runtime ATP.

API prevista:

```php
$chat->events(after: 0);
$chat->sendMessage($message);
$chat->sendAndStream($message);
$chat->sendAndWait($message);
```

Il parser deve supportare:

- chunk frammentati;
- LF e CRLF;
- commenti SSE;
- frame delimitati da riga vuota;
- più righe `data:`;
- confini UTF-8;
- JSON malformato;
- event type sconosciuti;
- campi additivi;
- cancellazione;
- chiusura deterministica delle risorse.

### 11.1 Compatibilità eventi

- eventi noti validi diventano classi tipizzate;
- eventi sconosciuti diventano `UnknownEvent`;
- eventi noti non conformi diventano `ProtocolViolationEvent`;
- envelope e dati originali vengono preservati.

### 11.2 Ping e cursore

`ping` usa `seq: 0` e non modifica il checkpoint.

Il checkpoint avanza soltanto dopo la consegna applicativa di un evento replayable:

```text
checkpoint = max(checkpoint, event.seq)
```

### 11.3 Limiti replay

L'SDK non promette replay durevole o lossless.

Lo snapshot `alpha` presenta:

- finestra replay in memoria limitata;
- buffer live limitato;
- race fra replay e subscribe;
- reset della sequenza dopo eviction o restart;
- assenza di epoch e segnale `cursor_expired`.

L'SDK deve rilevare i gap e produrre `ReplayGapException` quando non recuperabili.

## 12. Definizioni locali

La cartella predefinita è:

```text
envai/
├── agents/
│   └── *.md
├── teams/
│   └── *.php
└── tools.php
```

Il path può essere sovrascritto dalla configurazione.

La cartella contiene soltanto lo stato desiderato. Non contiene:

- API key;
- ID remoti;
- checkpoint SSE;
- stato delle chiamate tool;
- stato di sincronizzazione applicato.

## 13. Agenti Markdown

### 13.1 Identità

Il nome del file senza `.md` è la chiave locale immutabile.

```text
envai/agents/researcher.md
             └────────┘
             local key
```

I team fanno riferimento alla chiave locale.

Il campo frontmatter `name` è il nome remoto modificabile.

### 13.2 Formato

```markdown
---
format: 1
name: researcher
description: Research specialist
mode: subagent
model: openai/gpt-5-mini

tools:
  server:
    - exa_search
    - exa_contents
  client:
    - query_forecasting_database

permissions:
  read: allow
  write: deny

can_write_knowledge: false
can_write_skills: false

config:
  temperature: 0.2
  timeout_seconds: 180
---

You are a research specialist.

Collect evidence for the assigned task and report uncertainty explicitly.
```

### 13.3 Mapping

| Definizione locale | Payload Envai |
|---|---|
| corpo Markdown | `system_prompt` |
| `name` | `name` |
| `description` | `description` |
| `mode` | `mode` |
| `model` | `model` |
| `tools.server` | `tools_enabled` |
| `tools.client` | `external_tools_enabled` |
| `permissions` | `permissions` |
| `can_write_knowledge` | `can_write_knowledge` |
| `can_write_skills` | `can_write_skills` |
| `config` | `config` |

Gli strumenti devono essere compilati come oggetti `AgentTool`:

```json
{
  "external_tools_enabled": [
    {"name": "query_forecasting_database"}
  ]
}
```

Non devono essere inviati array piatti di stringhe.

### 13.4 Validazione

Il compilatore deve verificare:

- frontmatter valido;
- `format` supportato;
- body Markdown non vuoto;
- nome remoto valido e unico;
- mode `primary` o `subagent`;
- modello presente nel catalogo quando specificato;
- assenza di `max_tokens` in `config` a ogni profondità;
- riferimenti a tool esistenti;
- compatibilità fra mode e uso nel team.

## 14. Client tool nativi

### 14.1 Scope

I client tool appartengono all'utente Envai.

Sono gestiti tramite:

```text
POST   /api/v1/tools
GET    /api/v1/tools
GET    /api/v1/tools/{name}
PUT    /api/v1/tools/{name}
DELETE /api/v1/tools/{name}
```

Una definizione registrata è condivisa fra tutti i workspace dello stesso utente.

La visibilità per un agente richiede:

- tool registrato nel registro dell'utente;
- nome presente in `agent.external_tools_enabled`.

### 14.2 Registro locale

```php
<?php

use App\Ai\Tools\QueryForecastingDatabase;
use App\Ai\Tools\RetrieveMarketPrices;

return [
    'query_forecasting_database' => QueryForecastingDatabase::class,
    'retrieve_market_prices' => [
        'class' => RetrieveMarketPrices::class,
        'timeout_seconds' => 60,
    ],
];
```

La chiave del registro è il nome remoto Envai.

Non si deriva l'identità dal basename della classe.

### 14.3 Contratto nativo

```php
interface ClientTool
{
    public function description(): string;

    public function schema(): array;

    public function handle(ToolInvocation $invocation): string|ToolResult;
}
```

```php
final readonly class ToolInvocation
{
    public function __construct(
        public string $chatId,
        public string $turnId,
        public string $callId,
        public array $arguments,
    ) {}
}
```

Il workspace non fa parte dell'identità o della registrazione del tool. Può essere incluso nel contesto di invocazione soltanto se disponibile e utile all'autorizzazione applicativa.

### 14.4 Schema

Il payload remoto contiene:

- `name`;
- `kind: client`;
- `description`;
- `parameters` come JSON Schema con root `object`;
- `timeout_seconds` fra 1 e 600.

Il compilatore deve normalizzare lo schema e rifiutare root non object.

### 14.5 Esecuzione

Il runner:

1. riceve `agent.tool.request`;
2. verifica firma e chiave JWKS;
3. individua il tool nel registro locale;
4. valida gli argomenti;
5. consulta il call store;
6. registra atomicamente l'invocazione;
7. esegue l'handler;
8. serializza il risultato;
9. invia `tool-output`;
10. registra lo stato finale o ambiguo.

Il `SyncStateStore` e il `ClientToolCallStore` sono contratti distinti.

## 15. Team

### 15.1 Formato

Ogni team è un file PHP dichiarativo:

```php
<?php

use Digitalygo\Envai\Definitions\Team;

return Team::make('forecasting')
    ->name('Forecasting team')
    ->description('Researches and produces forecasts')
    ->orchestrator('orchestrator')
    ->member('researcher', role: 'research')
    ->member('forecaster', role: 'forecasting');
```

La chiave passata a `Team::make()` deve coincidere con il nome del file.

### 15.2 Riferimenti

- `orchestrator()` riceve una chiave locale agente;
- `member()` riceve una chiave locale agente;
- il reconciler risolve le chiavi negli ID `agt_*`;
- nessun ID remoto è scritto nei file PHP;
- l'ordine dei membri è significativo e preservato.

### 15.3 Validazione

- orchestratore esistente;
- orchestratore mode `primary`;
- membri esistenti;
- membri distinti;
- orchestratore non presente fra i membri;
- membri mode `subagent`;
- ruoli team validi;
- nome remoto unico;
- riferimenti tool degli agenti risolti.

## 16. Manifest

Il compilatore produce un `DefinitionManifest` immutabile:

```php
final readonly class DefinitionManifest
{
    public function __construct(
        public string $formatVersion,
        public array $tools,
        public array $agents,
        public array $teams,
        public string $hash,
    ) {}
}
```

L'hash viene calcolato su una rappresentazione canonica che:

- ordina mappe per chiave;
- preserva l'ordine significativo dei membri team;
- normalizza newline Markdown;
- distingue `null`, campo assente e valore vuoto quando semanticamente diversi;
- esclude path locali, timestamp e ID remoti;
- include la versione del formato.

## 17. State store

### 17.1 Contratto

```php
interface SyncStateStore
{
    public function acquireLock(string $connection): SyncLock;

    public function getResource(
        string $connection,
        ResourceType $type,
        string $localKey,
    ): ?ManagedResourceState;

    public function resources(string $connection): iterable;

    public function saveResource(ManagedResourceState $state): void;

    public function deleteResource(
        string $connection,
        ResourceType $type,
        string $localKey,
    ): void;

    public function markManifestReady(
        string $connection,
        string $manifestHash,
    ): void;

    public function readyManifest(string $connection): ?ReadyManifest;
}
```

Lo state store registra per risorsa:

- connessione;
- tipo;
- chiave locale;
- ID remoto;
- nome remoto;
- hash desiderato;
- payload normalizzato ultimo applicato;
- marker remoto osservato, per esempio `updated_at`;
- data dell'ultima sincronizzazione;
- stato `applied`, `drifted`, `conflicted`, `missing` o `ambiguous`.

### 17.2 File state store

Il file driver si ispira al file locale `.blueprint` di Laravel Shift Blueprint.

Path predefinito:

```text
.envai
```

Caratteristiche:

- formato JSON versionato;
- file aggiunto a `.gitignore` per impostazione predefinita;
- scrittura atomica tramite file temporaneo e rename;
- lock esclusivo tramite meccanismo disponibile sulla piattaforma;
- permessi restrittivi quando supportati;
- nessuna credenziale;
- nessun checkpoint SSE;
- nessuno stato di esecuzione dei client tool;
- recovery da file temporaneo incompleto senza corrompere l'ultimo stato valido.

Esempio:

```json
{
  "format": 1,
  "connections": {
    "production": {
      "base_url": "https://api.envai.example",
      "ready_manifest": "sha256:...",
      "resources": {
        "tool:query_forecasting_database": {
          "remote_id": "twl_...",
          "remote_name": "query_forecasting_database",
          "desired_hash": "sha256:...",
          "last_applied": {}
        }
      }
    }
  }
}
```

### 17.3 Database state store

L'utilizzatore può scegliere un database e un driver compatibile.

Implementazioni previste:

- `PdoSyncStateStore` con PDO fornito dal consumer;
- `LaravelDatabaseSyncStateStore` con connection Laravel configurabile;
- state store personalizzato tramite interfaccia.

Il package non impone PostgreSQL.

Il driver database deve poter supportare almeno:

- PostgreSQL;
- MySQL o MariaDB;
- SQLite.

Schema logico:

```text
envai_sync_resources
├── connection
├── resource_type
├── local_key
├── remote_id
├── remote_name
├── desired_hash
├── last_applied_payload
├── remote_marker
├── status
├── synchronized_at
├── created_at
└── updated_at
```

Chiave univoca:

```text
connection + resource_type + local_key
```

Manifest pronti:

```text
envai_sync_manifests
├── connection
├── manifest_hash
├── synchronized_at
└── metadata
```

L'integrazione Laravel può pubblicare una migration, ma il consumer decide:

- connection;
- prefisso tabella;
- database;
- esecuzione della migration.

### 17.4 Store personalizzati

Il consumer può implementare store basati su:

- Redis;
- DynamoDB;
- filesystem remoto;
- database proprietario;
- secretless deployment metadata.

Lo store deve garantire lock e aggiornamento atomico della singola risorsa o dichiarare esplicitamente una capability ridotta.

## 18. Identità e adozione

### 18.1 Identità locale

- agente: filename Markdown;
- team: filename o chiave `Team::make()`;
- tool: chiave di `tools.php`.

### 18.2 Identità remota

- agenti e team vengono aggiornati per ID remoto dopo il primo collegamento;
- i tool sono indirizzati per nome e il nome non è aggiornabile dall'API;
- gli ID ULID restano nello state store;
- i nomi possono essere usati per il bootstrap, ma non sostituiscono lo stato applicato.

### 18.3 Adozione

Se esiste una risorsa remota con lo stesso nome ma non è nello state store:

- il default è conflitto;
- `--adopt` permette di prenderla in gestione;
- l'adozione mostra sempre il diff;
- l'adozione non sovrascrive automaticamente il remoto se differisce;
- `--adopt --overwrite-drift` è necessario per adottare e sovrascrivere nello stesso apply.

### 18.4 Rinomine

Agenti e team possono essere rinominati conservando la chiave locale e l'ID remoto registrato.

Per i tool, il nome è l'identità pubblica e non è aggiornabile. Una rinomina è modellata come:

1. creazione del nuovo tool;
2. aggiornamento degli agenti che lo referenziano;
3. eventuale prune del vecchio tool.

## 19. Planner e conflitti

### 19.1 Confronto a tre vie

Per ogni risorsa il planner confronta:

```text
desired
last applied
remote
```

### 19.2 Classificazione

| Desired | Last applied | Remote | Esito |
|---|---|---|---|
| uguale | uguale | uguale | unchanged |
| diverso | uguale | uguale | update |
| uguale | uguale | diverso | drift |
| diverso | uguale | diverso | conflict |
| presente | assente | assente | create |
| presente | assente | stesso nome non gestito | adoption required |
| presente | presente | assente | missing remote |
| assente | presente | presente | orphan managed |

### 19.3 Policy

Policy predefinita:

```text
on_drift = fail
on_conflict = fail
on_missing_remote = fail
on_unmanaged_name_collision = fail
prune = false
```

Override espliciti:

```text
--adopt
--overwrite-drift
--recreate-missing
--prune
```

Nessun override distruttivo viene attivato implicitamente in ambiente non interattivo.

### 19.4 Risposte HTTP ambigue

Se un apply riceve un errore di trasporto dopo aver inviato una mutazione:

- la risorsa passa a stato `ambiguous`;
- il reconciler non ritenta ciecamente;
- esegue una lettura remota di riconciliazione quando possibile;
- se non può dimostrare l'esito, interrompe l'apply;
- il manifest non viene marcato pronto.

## 20. Procedura di sincronizzazione

### 20.1 Fase locale

1. leggere configurazione e connessione;
2. acquisire il lock;
3. caricare agenti Markdown;
4. caricare il registro tool;
5. caricare i team PHP;
6. istanziare le classi tool;
7. generare gli schema;
8. validare le definizioni;
9. validare le referenze incrociate;
10. produrre il manifest canonico;
11. calcolare l'hash.

Nessuna scrittura remota avviene prima del completamento di questa fase.

### 20.2 Fase remota

1. leggere catalogo modelli;
2. leggere tool utente;
3. leggere agenti utente;
4. leggere team utente;
5. costruire il piano a tre vie;
6. fermarsi se esistono conflitti non autorizzati;
7. mostrare o restituire il piano.

### 20.3 Apply

Ordine di creazione e aggiornamento:

```text
user tools
    ↓
agents
    ↓
teams
```

Motivazione:

- gli agenti fanno riferimento ai client tool per nome;
- i team fanno riferimento agli agenti per ID;
- quando un team risulta sincronizzato tutte le sue dipendenze sono già disponibili.

### 20.4 Commit del manifest

Il manifest viene marcato pronto soltanto se:

- tutte le operazioni necessarie sono concluse;
- ogni risposta remota è stata verificata;
- lo state store è stato aggiornato;
- non rimangono risorse `ambiguous` o `conflicted`;
- l'hash ricompilato coincide con quello applicato.

Un apply parziale non produce readiness.

## 21. Prune

Il default è non distruttivo.

`--prune` considera soltanto risorse presenti nello state store e quindi già gestite dalla stessa connessione.

Ordine:

1. aggiornare i team per rimuovere referenze obsolete;
2. eliminare team gestiti non più dichiarati;
3. eliminare agenti gestiti non più dichiarati;
4. eliminare tool gestiti non più dichiarati.

Vincoli:

- un team con chat può non essere eliminabile;
- un agente ancora referenziato può non essere eliminabile;
- una cancellazione bloccata viene riportata, non aggirata;
- il manifest non viene marcato pronto se il prune era parte obbligatoria dell'apply e resta incompleto;
- una risorsa remota non gestita non viene mai eliminata.

## 22. Readiness

Poiché agenti, team e tool appartengono all'utente, la readiness è per:

```text
connection/account + manifest hash
```

Non è per workspace.

Il workspace continua a essere lo scope di:

- chat;
- file;
- wiki;
- skill;
- memory;
- completamenti.

### 22.1 Gate di alto livello

```php
$definitions = $envai->definitions()
    ->requireSynchronized();

$chat = $envai->workspace($workspaceId)
    ->chats()
    ->create(team: $definitions->team('forecasting'));
```

Se il manifest locale è diverso dall'ultimo manifest pronto:

```text
DefinitionsNotSynchronizedException
```

### 22.2 API low-level

Il client HTTP low-level resta utilizzabile con ID remoti espliciti senza obbligare alla sincronizzazione.

Il gate si applica agli helper che usano:

- chiavi locali agente;
- chiavi locali team;
- registro locale tool;
- autoesecuzione dei client tool.

Questo mantiene l'SDK utile anche per applicazioni che gestiscono le definizioni autonomamente.

## 23. Modalità operative

### 23.1 Deploy

Modalità raccomandata in produzione:

```bash
vendor/bin/envai definitions:validate
vendor/bin/envai definitions:plan --fail-on-drift
vendor/bin/envai definitions:sync
```

Laravel:

```bash
php artisan envai:validate
php artisan envai:plan --fail-on-drift
php artisan envai:sync
```

### 23.2 Lazy

Pensata per sviluppo locale:

```php
'sync' => [
    'mode' => 'lazy',
    'ttl' => 300,
],
```

La prima operazione di alto livello:

1. controlla l'hash locale;
2. controlla il manifest pronto;
3. acquisisce un lock;
4. sincronizza se necessario;
5. prosegue soltanto dopo successo.

### 23.3 Manuale

```php
$plan = $envai->definitions()->plan();
$report = $envai->definitions()->apply($plan);
```

### 23.4 Off

Nessun gate automatico. Le operazioni low-level restano disponibili.

## 24. CLI

Comandi core previsti:

```text
envai definitions:validate
envai definitions:plan
envai definitions:sync
envai definitions:status
envai definitions:state
```

Alias Artisan:

```text
envai:validate
envai:plan
envai:sync
envai:status
envai:state
```

Opzioni:

```text
--connection=
--definitions-path=
--state-driver=
--dry-run
--json
--no-interaction
--only=tools|agents|teams
--adopt
--overwrite-drift
--recreate-missing
--prune
--fail-on-drift
```

Una sincronizzazione `--only` non marca il manifest complessivo come pronto.

### 24.1 Output plan

```text
Connection: production
Manifest:   sha256:...

Tools
  + query_forecasting_database
  ~ retrieve_market_prices

Agents
  = orchestrator
  ~ researcher
  + forecaster

Teams
  ~ forecasting

Conflicts
  ! analyst: remote changed after last apply
```

L'opzione `--json` produce un report machine-readable stabile.

## 25. Integrazione Laravel

L'integrazione opzionale comprende:

- service provider;
- file `config/envai.php` pubblicabile;
- binding del client;
- facade opzionale;
- comandi Artisan;
- risoluzione delle classi tool dal container;
- driver state file;
- driver state database su connection selezionabile;
- migration pubblicabile per lo state store;
- fake per test applicativi.

Non deve:

- sincronizzare durante `register()` o `boot()`;
- aprire stream durante il boot;
- creare tabelle automaticamente;
- imporre il database di default Laravel;
- richiedere Laravel al core.

Configurazione indicativa:

```php
return [
    'default' => 'production',

    'connections' => [
        'production' => [
            'base_url' => env('ENVAI_BASE_URL'),
            'api_key' => env('ENVAI_API_KEY'),
        ],
    ],

    'definitions' => [
        'path' => base_path('envai'),
        'sync_mode' => env('ENVAI_SYNC_MODE', 'deploy'),
    ],

    'state' => [
        'driver' => env('ENVAI_STATE_DRIVER', 'file'),
        'file' => base_path('.envai'),
        'connection' => env('ENVAI_STATE_DB_CONNECTION'),
        'table_prefix' => 'envai_sync_',
    ],
];
```

## 26. Sicurezza dei client tool

Per ogni richiesta client tool:

- richiedere `alg: EdDSA`;
- individuare la chiave Ed25519 via `kid` nel JWKS;
- applicare cache JWKS con TTL;
- eseguire un refresh una sola volta per `kid` sconosciuto;
- verificare il preimage esatto;
- non eseguire mai un handler non verificato.

Preimage:

```text
call_id + "\n" + tool_name + "\n" + canonical_json(args)
```

Soltanto `args` viene canonicalizzato secondo RFC 8785.

La validità della firma prova origine e integrità, non freshness o exactly-once.

## 27. Call store dei client tool

Contratto separato:

```php
interface ClientToolCallStore
{
    public function begin(ToolCallIdentity $identity): BeginResult;

    public function markSubmitted(ToolCallIdentity $identity): void;

    public function markResolved(ToolCallIdentity $identity): void;

    public function markAmbiguous(ToolCallIdentity $identity): void;
}
```

Deve esistere:

- implementazione in-memory per test e sviluppo;
- implementazione file opzionale;
- implementazione database opzionale;
- estensione personalizzata.

L'handler può essere invocato automaticamente al massimo una volta nei limiti garantiti dallo store configurato.

Dopo un crash fra side effect e registrazione, lo stato può essere ambiguo. Gli effetti esterni devono usare idempotency key, transazioni o riconciliazione applicativa.

## 28. Capabilities e funzionalità parziali

`GET /capabilities` è discovery positiva e non esaustiva.

L'SDK non deve trattare `event_types` come allowlist.

### 28.1 Approval

- modelli evento supportati per forward compatibility;
- helper automatici disabilitati finché la capability non è esplicitamente utilizzabile;
- endpoint `alpha` attualmente non operativo.

### 28.2 Steer

- operazione low-level esposta;
- evento `turn.steered` esposto;
- nessuna promessa che `alpha` modifichi realmente il loop LLM.

### 28.3 Restore

- helper esposto;
- accepted non interpretato come durability acknowledgement;
- limiti della persistenza best-effort documentati.

## 29. Testing

### 29.1 Core HTTP

- inventario completo delle 52 operazioni;
- request e response tipizzate;
- autenticazione;
- redazione credenziali;
- error mapping;
- retry safe e unsafe;
- paginazione;
- upload multipart;
- download streaming;
- immagini;
- API key.

### 29.2 ATP

- chunk frammentati;
- LF e CRLF;
- più righe data;
- commenti;
- JSON malformato;
- versione ATP non supportata;
- evento sconosciuto;
- evento noto invalido;
- ping escluso dal cursore;
- duplicati;
- gap recuperabile;
- gap non recuperabile;
- reset sequenza;
- race replay-subscribe;
- interleaving orchestratore e subagent;
- terminale correlato per `turn_id`.

### 29.3 Definizioni

- parsing Markdown e YAML;
- body vuoto;
- format non supportato;
- agent key duplicate;
- tool key duplicate;
- team key duplicate;
- tool mancante;
- agente mancante;
- orchestratore non primary;
- membro non subagent;
- schema tool non object;
- `max_tokens` annidato;
- hash deterministico;
- newline multipiattaforma;
- ordine membri preservato.

### 29.4 Reconciler

- create in ordine tool, agenti, team;
- update;
- unchanged;
- adozione;
- drift;
- conflitto;
- missing remote;
- risposta HTTP ambigua;
- apply parziale;
- manifest non pronto dopo fallimento;
- rename agente;
- rename team;
- sostituzione tool rinominato;
- prune con dipendenze;
- risorsa non gestita mai eliminata;
- lock concorrente.

### 29.5 State store

La stessa contract test suite deve essere eseguita contro:

- file;
- PDO SQLite;
- PDO PostgreSQL quando disponibile in CI;
- PDO MySQL quando disponibile in CI;
- Laravel database store.

Test specifici file:

- atomic rename;
- file troncato;
- lock concorrente;
- permessi;
- upgrade formato;
- assenza di credenziali.

### 29.6 Client tool

- firma valida;
- firma invalida senza esecuzione;
- refresh JWKS;
- RFC 8785;
- tool sconosciuto;
- validazione schema;
- timeout;
- replay senza doppia invocazione;
- crash boundary;
- stato ambiguo;
- `409` nel turno attivo;
- `404` dopo il terminale.

## 30. Quality gate

Il progetto configura:

- PHPUnit o Pest;
- PHPStan al livello massimo praticabile;
- PHP CS Fixer o Pint;
- Composer validate;
- Composer audit;
- test matrix PHP;
- test matrix transport;
- test contract per state store;
- verifica automatica inventario OpenAPI;
- scansione segreti nel diff;
- `git diff --check`.

Nessun risultato viene dichiarato verde senza esecuzione reale.

## 31. Documentazione utente

Il README deve includere:

- installazione;
- configurazione framework-independent;
- configurazione Laravel;
- client HTTP;
- definizioni locali;
- agenti Markdown;
- tool nativi;
- team PHP;
- file state store;
- database state store;
- plan e sync;
- drift e conflitti;
- deployment;
- creazione workspace e chat;
- streaming;
- client tool;
- error handling;
- capability detection;
- limiti `alpha`;
- testing con fake.

La documentazione pubblica del codice è in inglese.

## 32. Versioning

La libreria resta pre-1.0 finché non sono stabilizzati:

- ATP;
- replay e recovery;
- approval;
- steer;
- contratto di persistenza e restore;
- sincronizzazione idempotente server-side.

Politica:

- SemVer;
- cambi additive compatibili in minor;
- breaking change in minor durante `0.x` con changelog esplicito;
- formato manifest e state file versionati separatamente;
- migrazione state file automatica soltanto quando lossless;
- backup prima di una migrazione non reversibile.

## 33. Evoluzioni server consigliate

Il reconciler iniziale può funzionare con nomi e state store, ma l'API dovrebbe in futuro supportare:

- `source_key` o `client_ref` immutabile;
- unicità per utente su agenti, team e tool;
- upsert idempotente per source key;
- revision o ETag;
- precondition `If-Match`;
- batch plan o batch apply atomico, se sostenibile;
- risposta esplicita per mutazione già applicata.

Esempio futuro:

```text
PUT /api/v1/agents/by-source-key/{key}
PUT /api/v1/teams/by-source-key/{key}
PUT /api/v1/tools/by-source-key/{key}
```

Queste evoluzioni non bloccano la prima versione PHP.

## 34. Fasi di implementazione

### Fase 1. Fondazione

- repository e Composer;
- configurazione;
- transport abstractions;
- error model;
- modelli base;
- fixture contratti.

### Fase 2. API HTTP

- tutte le 52 operazioni;
- servizi;
- paginazione;
- file e immagini;
- test inventario.

### Fase 3. ATP

- SSE parser;
- envelope ed eventi;
- stream;
- replay e gap;
- helper turn.

### Fase 4. Definizioni

- agenti Markdown;
- tool contract e registro;
- team PHP;
- validatore;
- manifest.

### Fase 5. Sincronizzazione

- planner;
- conflitti;
- reconciler;
- file state store;
- PDO state store;
- readiness gate;
- CLI core.

### Fase 6. Client tool runtime

- JWKS;
- Ed25519;
- RFC 8785;
- call store;
- runner;
- timeout e ambiguità.

### Fase 7. Laravel

- provider;
- config;
- Artisan;
- database state store;
- migration pubblicabile;
- fake e test Laravel.

### Fase 8. Stabilizzazione

- documentazione;
- esempi;
- test matrix;
- security review;
- review fail-closed;
- prima release pre-1.0.

Gli adapter Laravel AI e Prism saranno pianificati soltanto dopo la stabilizzazione della prima release.

## 35. Criteri di accettazione

Il progetto è pronto per la prima release quando:

- tutte le 52 operazioni risultano implementate e testate;
- ATP v2 è supportato;
- eventi sconosciuti vengono preservati;
- gap replay vengono rilevati;
- agenti Markdown vengono compilati correttamente;
- tool user-scoped vengono sincronizzati tramite `/api/v1/tools`;
- team vengono risolti attraverso le chiavi locali agente;
- file state store passa la contract suite;
- database state store passa la contract suite;
- planner rileva drift e conflitti;
- nessuna risorsa unmanaged viene modificata senza adozione;
- prune è opt-in;
- apply parziale non produce readiness;
- gli helper locali rifiutano manifest non sincronizzati;
- il runtime client tool verifica sempre la firma prima dell'handler;
- Laravel resta opzionale;
- Laravel AI e Prism non sono dipendenze;
- quality gate e security audit sono verdi;
- README ed esempi sono completi.

## 36. Rischi principali

### 36.1 API senza source key

Rischio: rinomina e adozione dipendono dallo state store.

Mitigazione:

- chiavi locali stabili;
- confronto a tre vie;
- adozione esplicita;
- proposta futura `source_key` server-side.

### 36.2 Apply non transazionale

Rischio: sincronizzazione parziale.

Mitigazione:

- ordine delle dipendenze;
- stato per risorsa;
- riconciliazione ripetibile;
- manifest pronto soltanto a fine apply;
- nessun retry cieco delle mutazioni ambigue.

### 36.3 File state su più nodi

Rischio: ogni nodo possiede uno stato differente.

Mitigazione:

- raccomandare database state store nei deployment multi-node;
- documentare che il file driver è adatto a singolo host, CI o sviluppo;
- permettere state store personalizzati.

### 36.4 Drift manuale

Rischio: dashboard o altri client modificano risorse gestite.

Mitigazione:

- confronto desired, last applied e remote;
- fail-closed;
- overwrite soltanto esplicito.

### 36.5 Client tool con side effect

Rischio: crash dopo side effect e prima della conferma.

Mitigazione:

- call store durevole;
- idempotency key basata su `call_id`;
- stato ambiguo;
- riconciliazione applicativa.

### 36.6 Limiti replay

Rischio: perdita eventi non recuperabile.

Mitigazione:

- gap detection;
- `ReplayGapException`;
- nessuna promessa lossless;
- policy applicativa esplicita.

## 37. Risultato atteso

`digitalygo/envai-teams-sdk-php` fornirà un'unica libreria PHP modulare che permette di:

- configurare Envai;
- dichiarare agenti, tool e team nel repository applicativo;
- verificare e pianificare le differenze remote;
- sincronizzare le definizioni con gestione del drift;
- scegliere liberamente come persistere lo stato;
- creare chat usando chiavi locali stabili;
- consumare ATP v2;
- eseguire client tool verificati;
- usare Laravel soltanto quando desiderato;
- aggiungere in seguito bridge Laravel AI e Prism senza contaminare il core.

La libreria deve trattare le definizioni locali come source of truth dichiarativa, senza nascondere divergenze, conflitti o limiti del server.

## 38. Riferimenti esterni

- OpenCode agents: https://opencode.ai/docs/agents/
- Pi extensions: https://pi.dev/docs/latest/extensions
- Laravel Shift Blueprint commands e file `.blueprint`: https://blueprint.laravelshift.com/docs/available-commands/
- Laravel AI SDK, riferimento futuro: https://github.com/laravel/ai
- Prism, riferimento futuro: https://github.com/prism-php/prism
