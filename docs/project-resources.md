# Project resources

> [!note]
> Everything on this page is unreleased source on top of tag `v1.3.0`. It is
> not part of any tagged release, and no Packagist version or backend
> deployment is implied. Contract snapshots are pinned to backend commit
> `7f4085dbfdd3f208b82716119cff1860fbd502bb`.

All examples assume `$envai` is an `EnvaiClient` and that IDs belong to the
project of the configured key.

## Project skills

`projectSkills()` manages skills shared by a whole project. They are not files
in a workspace: `workspaceFiles()` works on the workspace filesystem, which has
its own protected `/skills` directory, and never touches project skills. Runtime
agents can read project skills but cannot modify them.

```php
use Digitalygo\Envai\Models\Common\OptionalField;
use Digitalygo\Envai\Models\ProjectSkill\ProjectSkillCreateRequest;
use Digitalygo\Envai\Models\ProjectSkill\ProjectSkillUpdateRequest;

$skill = $envai->projectSkills()->create(
    $projectId,
    ProjectSkillCreateRequest::withDescription(
        'release-notes',
        "# Release notes\n",
        'Drafts release notes',
    ),
);

$skills = $envai->projectSkills()->list($projectId);
$same = $envai->projectSkills()->get($projectId, $skill->id);

$envai->projectSkills()->update(
    $projectId,
    $skill->id,
    ProjectSkillUpdateRequest::clearDescription(),
);
$envai->projectSkills()->update(
    $projectId,
    $skill->id,
    new ProjectSkillUpdateRequest(
        name: OptionalField::of('release-notes-v2'),
        content: OptionalField::of("# Release notes v2\n"),
    ),
);

$envai->projectSkills()->delete($projectId, $skill->id);
```

An absent field is not sent; `OptionalField::of(null)` on `description` sends an
explicit `null`. Markdown content is kept exactly as supplied.

## Chat crons

`chatCrons()` offers four operations: `list()`, `get()`, `update()` and
`delete()`. There is no public create operation: schedules are created only by
the native cron tool during an active turn. Deleting a cron cancels future
occurrences that are not yet admitted; an already admitted turn keeps running.
Cancelled crons are hidden from the API.

```php
use Digitalygo\Envai\Models\ChatCron\ChatCronFilters;
use Digitalygo\Envai\Models\ChatCron\ChatCronStatus;
use Digitalygo\Envai\Models\ChatCron\ChatCronUpdateRequest;
use Digitalygo\Envai\Models\Common\OptionalField;

$crons = $envai->chatCrons()->list($workspaceId, new ChatCronFilters(
    originChatId: $chatId,
    status: ChatCronStatus::Active,
));

$cron = $envai->chatCrons()->get($workspaceId, $cronId);

$envai->chatCrons()->update(
    $workspaceId,
    $cronId,
    ChatCronUpdateRequest::prompt('Send the weekly summary.'),
);
$envai->chatCrons()->update(
    $workspaceId,
    $cronId,
    new ChatCronUpdateRequest(
        intervalMinutes: OptionalField::of(60),
        repeatCount: OptionalField::of(10),
    ),
);

$envai->chatCrons()->delete($workspaceId, $cronId);
```

Updating a cron that is not active returns `409`, surfaced as
`ConflictException`. The SDK does not retry or change state to work around it.
Origin key, turn and call identifiers are never exposed on `ChatCron`.

The matching `chat.cron.wakeup` event decodes as `ChatCronWakeupEvent`.

## Chat rename and deletion

```php
use Digitalygo\Envai\Models\Chat\ChatUpdateRequest;

$chat = $envai->chats()->updateChat(
    $workspaceId,
    $chatId,
    new ChatUpdateRequest(title: 'Quarterly review'),
);
$chat = $envai->workspace($workspaceId)->chats()->update(
    $chatId,
    new ChatUpdateRequest(title: null),
);
```

Title changes made by the server arrive as `ChatTitleUpdatedEvent`
(`chat.title.updated`).

## Deleting workspaces and chats

Both deletions answer HTTP 200 with a `CleanupResult`.

```php
$result = $envai->workspaces()->delete($workspaceId);
$chatResult = $envai->workspace($workspaceId)->chats()->delete($chatId);

foreach ($result->cleanup as $intent) {
    printf(
        "%s %s attempts=%d\n",
        $intent->kind,
        $intent->status,
        $intent->attempts,
    );
}
```

Access is cut at once, but each intent stays pending until content removal is
confirmed. A pending intent is not a physical purge: read the intent status
before telling anyone that content is gone. Accounting rows are retained.
Deletions are never retried automatically.

## Project reports

`projectReports()` reads usage and metadata logs. Pagination returns `Page`
objects with `items`, `links` and `meta` (`currentPage`, `lastPage`, `total`).

```php
use Digitalygo\Envai\Models\Reporting\AnalyticsFilters;
use Digitalygo\Envai\Models\Reporting\EventLogFilters;
use Digitalygo\Envai\Models\Reporting\TypeCatalogFilters;
use Digitalygo\Envai\Models\Reporting\UsageFilters;

$usageFilters = new UsageFilters(
    from: '2026-11-01',
    to: '2026-11-10',
    perPage: 50,
);
$usage = $envai->projectReports()->usage($projectId, $usageFilters);

$rangeFilters = new UsageFilters(from: '2026-11-01', to: '2026-11-10');
$days = $envai->projectReports()->iterateUsage($projectId, $rangeFilters);
foreach ($days as $day) {
    foreach ($day->totals as $money) {
        printf("%s %s %s\n", $day->date, $money->currency, $money->amount);
    }
}

$logs = $envai->projectReports()->logsPage($projectId, 2, new EventLogFilters(
    types: ['turn.completed', 'turn.failed'],
    workspaceIds: [$workspaceId],
));

$analyticsFilters = new AnalyticsFilters(
    from: '2026-11-01',
    to: '2026-11-10',
);
$analytics = $envai->projectReports()->analytics($projectId, $analyticsFilters);

$catalogFilters = new TypeCatalogFilters(
    from: '2026-11-01',
    to: '2026-11-10',
);
$catalog = $envai->projectReports()->filters($projectId, $catalogFilters);
```

`iterateUsage()` and `iterateLogs()` follow `meta.lastPage` and throw
`ProtocolViolationException` when the server does not return the requested page.
`eventTypes()` is an alias of `filters()`. An explicit page number passed to
`usagePage()` or `logsPage()` overrides any `page` set on the filter object.
The iterators take `?int $startPage = null`, which uses the filter's `page`
(or 1 when omitted); an explicit `$startPage` is authoritative and preserves
the remaining filters.

### Usage semantics

- `UsageMoney::$amount` is an exact decimal string and `currency` an uppercase
  three-letter string. Never parse totals as floats, and never add amounts of
  different currencies.
- Totals are listed per currency. Invocations with unknown cost are counted in
  `unknownInvocations`, and media observations with unknown currency in
  `unknownCurrencyObservations`; they are not converted to zero.
- Usage and logs are metadata only. Log entries carry identifiers, sequence,
  type, timestamp and sanitized metadata, never prompts, tool output or
  attachments.

### Query scopes

| Method | Accepted filters |
| --- | --- |
| `usage()`, `usagePage()`, `iterateUsage()` | `from`, `to`, `api_key_id`, `page`, `per_page` |
| `logs()`, `logsPage()`, `iterateLogs()` | `from`, `to`, `api_key_id`, `chat_id`, `type`, `types`, `workspace_ids`, `api_key_ids`, `page`, `per_page` |
| `analytics()` | `from`, `to`, `api_key_id`, `chat_id`; no `type`, arrays or pagination |
| `filters()`, `eventTypes()` | `from`, `to` only |

Dates use `YYYY-MM-DD` in UTC, and a window is limited to 90 days. Array filters
take 1 to 50 distinct non-empty strings. Scalar `type` conflicts with `types`,
and scalar `api_key_id` conflicts with `api_key_ids`. Invalid filters throw
`InvalidArgumentException` before any request.

## Billing errors and events

HTTP 402 maps to `PaymentRequiredException`. A rejected message is not resent
automatically, and nothing is retried for you.

```php
use Digitalygo\Envai\Exceptions\PaymentRequiredException;
use Digitalygo\Envai\Exceptions\ServerException;
use Digitalygo\Envai\Models\Chat\ChatMessageRequest;

try {
    $envai->chats()->sendMessage($chatId, new ChatMessageRequest('Hello'));
} catch (PaymentRequiredException $exception) {
    $code = $exception->apiError()?->code;
} catch (ServerException $exception) {
    $code = $exception->apiError()?->code;
}
```

HTTP 503 `provider_configuration_error` remains a `ServerException` with the
server code preserved. Billing events arrive on the stream:

```php
use Digitalygo\Envai\Streaming\Events\BillingCreditExhaustedEvent;
use Digitalygo\Envai\Streaming\Events\BillingLimitReachedEvent;

foreach ($envai->chat($workspaceId, $chatId)->events() as $event) {
    if ($event instanceof BillingLimitReachedEvent) {
        $remaining = $event->remaining ?? 'unknown';
        printf("%s %s %s\n", $event->scope, $event->currency, $remaining);
    }

    if ($event instanceof BillingCreditExhaustedEvent) {
        printf("%s %s\n", $event->scope, $event->currency);
    }
}
```
