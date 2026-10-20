# Chat attachments

Native chat-file uploads require contract-3 capabilities advertising `chat_attachments` and the boolean feature `features.chat_attachment_originals: true`. The SDK checks these capabilities before sending the multipart upload. It does not fall back to OCR or send the message text without its attachment IDs.

A native upload becomes ready after the server stores the original. Its conversion status is `not_required` and `derived` is null. `wait()` returns for this state when the lifecycle is `ready`, without an extra status request. It continues to recognize converted `completed`, terminal `failed`, recoverable failures, and withdrawn resources from older attachment records. Send ordered attachment IDs with a separate stable message idempotency key. A message acknowledgment must contain those same IDs. Mutations are never automatically retried, even with a key. Reconcile an uncertain response before explicitly replaying the same payload and key.

The legacy conversion path supports `.md`, `.txt`, `.csv`, `.xlsx`, `.pdf`, `.docx`, `.mp3`, and `.mp4`. PDF/DOCX use existing Mistral OCR; MP3 and the mixed audio tracks of MP4 use direct ElevenLabs Scribe. MP4 visual frames are not analyzed. Native uploads retain their originals without invoking these converters. The server and selected model determine whether a native format is supported. There is no automatic Wiki indexing, guaranteed Office editing/export, or automatic mounting of original files in tools.

The complete textual projection must fit the server and model context budgets; oversized input fails explicitly and the converted attachment stays available. No silent truncation occurs. Originals and derivatives remain private and scoped to the owning user, workspace and chat. Forked/restored text does not grant access to originals. Status/list/download/retry/delete are separate operations. Download selects `kind=original` or `kind=derived`.

Draft retention defaults to 24 hours; accepted attachments and input snapshots default to 14 days. Idempotent replay is guaranteed while the retained input snapshot exists. Withdrawal blocks new reads immediately; physical cleanup may wait for live conversion leases. Deletion does not erase already accepted conversation content.

Conversion cost is exposed in the attachment's `usage_cost`; inference is attributed to the turn. Missing tariffs and uncertain outcomes stay pending with null totals. An explicit conversion retry creates a linked accounting cycle, preserving previous cost evidence. Wiki file outcomes may now include `deferred` for infrastructure failures; applications should display that outcome without describing the original document as defective.

```php
$uploaded = $client->attachments()->upload($workspaceId, $chatId, 'invoice.pdf', 'upload-invoice-1', 'application/pdf');
$ready = $client->attachments()->wait($workspaceId, $chatId, $uploaded->attachmentId);
if ($ready->lifecycle !== 'ready' || ! in_array($ready->conversion->status, ['completed', 'not_required'], true)) {
    throw new RuntimeException('Resolve or remove the failed attachment before sending');
}
$result = $client->chat($workspaceId, $chatId)->sendAndWait('Summarize the invoice.', [$ready->attachmentId], 'message-invoice-1');
```

`attachments()` exposes `upload`, `all`, `get`, `retry`, `delete`, `download`, and `wait`. Uploads are path-backed multipart files. `wait` accepts a bounded timeout, polling interval and cooperative cancellation token. Low-level `chats()->sendMessage($chatId, new ChatMessageRequest($text, $ids), $messageKey)` retains the IDs and key. `ChatRuntime` exposes those same optional parameters on `sendMessage`, `sendAndStream`, and `sendAndWait`.

See the matching backend [attachment guide](https://github.com/digitalygo/envai-teams/blob/6a4e45bafe6f5856ef92a57b37db08769738b412/docs/guides/chat-attachments.md) for limits, operator settings, and retention. Publishing this SDK does not deploy the backend.

With a stable message key, turn helpers retain the initial replay until the acknowledgment identifies the accepted turn. A previously completed turn can therefore finish from retained events without another inference call. The pre-send replay buffer is limited to 16 MiB; exceeding it fails before submitting the message. Resume a known turn with its event cursor when the retained stream is larger or a replay gap is reported.
