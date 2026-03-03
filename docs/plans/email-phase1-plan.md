# Phase 1 Implementation Plan: Webhook Audit & Duplicate Email Bug

## Summary

Phase 1 fixes two bugs (duplicate inbound emails, webhook 500 errors) and hardens the webhook pipeline. All changes are backend-only across 4 files + 1 new migration + 1 new test file.

---

## Bug 1: Duplicate Inbound Emails

### Root Cause

`processInboundEmail()` in `EmailService.php:436` checks for duplicate **webhook events** (`provider_event_id`) but never checks for duplicate **emails** (`message_id`). If Mailgun redelivers the same email with a different request token (retry after timeout, reprocessing, or catchall + specific route both firing), a second email record is created.

The `emails.message_id` column is indexed but has **no unique constraint** — the database allows duplicates.

### Fix (3 steps)

**Step 1 — Add `message_id` dedup check in `EmailService::processInboundEmail`**

File: `backend/app/Services/Email/EmailService.php` (~line 456, inside the transaction, before `Email::create`)

```php
// After resolving mailbox, before the DB::transaction:
if (!empty($parsed->messageId)) {
    $existingEmail = Email::where('mailbox_id', $mailbox->id)
        ->where('message_id', $parsed->messageId)
        ->first();
    if ($existingEmail) {
        Log::info('Duplicate email ignored', [
            'message_id' => $parsed->messageId,
            'mailbox_id' => $mailbox->id,
        ]);
        $this->logWebhook($provider, $parsed->providerEventId, 'inbound', $parsed, 'duplicate');
        return null;
    }
}
```

Why `mailbox_id` scope: the same `Message-Id` can legitimately appear in different mailboxes (e.g., user has two addresses CC'd on the same email). Dedup should be per-mailbox.

**Step 2 — Add unique composite index via migration**

New file: `backend/database/migrations/2026_03_01_000001_add_unique_mailbox_message_id_to_emails_table.php`

```php
$table->unique(['mailbox_id', 'message_id'], 'emails_mailbox_message_id_unique');
// Drop the old standalone index since the composite unique index covers it
$table->dropIndex(['message_id']);
```

This provides database-level protection against race conditions where two webhook requests arrive simultaneously.

**Step 3 — Wrap `Email::create` in a try-catch for unique violation**

In the same transaction block, catch `QueryException` with SQLSTATE 23000 (integrity constraint) and treat it as a duplicate — return null instead of throwing.

---

## Bug 2: Webhook Event 500 Errors

### Root Cause

The `handleEvent` endpoint (`EmailWebhookController.php:62`) calls `parseDeliveryEvent()` which accesses nested array keys like `$eventData['message']['headers']`. When Mailgun sends event types that don't include a `message.headers` structure (e.g., `complained`, `unsubscribed`), the parser either throws or returns malformed data. The outer try-catch returns 500, causing Mailgun to retry indefinitely.

### Fix (2 steps)

**Step 1 — Harden `MailgunProvider::parseDeliveryEvent`**

File: `backend/app/Services/Email/MailgunProvider.php:131`

Add null-safe access and handle events that don't have message headers:

```php
public function parseDeliveryEvent(Request $request): array
{
    $eventData = $request->input('event-data', []);
    $event = $eventData['event'] ?? '';
    $messageHeaders = $eventData['message']['headers'] ?? [];

    $statusMap = [
        'delivered' => 'delivered',
        'accepted' => 'queued',
        'permanent_fail' => 'bounced',
        'temporary_fail' => 'failed',
        'failed' => 'failed',
        'opened' => 'delivered',
        'clicked' => 'delivered',
        'complained' => 'delivered',
        'unsubscribed' => 'delivered',
    ];

    return [
        'event_type' => $statusMap[$event] ?? $event,
        'provider_message_id' => $messageHeaders['message-id'] ?? ($eventData['message']['headers']['message-id'] ?? null),
        'timestamp' => $eventData['timestamp'] ?? null,
        'recipient' => $eventData['recipient'] ?? null,
        'error_message' => $eventData['delivery-status']['message'] ?? ($eventData['reason'] ?? null),
    ];
}
```

Key changes:
- Add `complained` and `unsubscribed` to `$statusMap` so unmapped events don't pass raw event names as delivery status
- The null coalescing is already present but verify nested access doesn't throw on missing `message` key

**Step 2 — Guard against missing `event-data` entirely in the controller**

File: `backend/app/Http/Controllers/Api/EmailWebhookController.php:75`

Before calling `parseDeliveryEvent`, validate the payload has minimum required fields:

```php
if (!$request->has('event-data') || !is_array($request->input('event-data'))) {
    // Malformed payload — acknowledge to prevent retries
    EmailWebhookLog::create([...status => 'failed', error_message => 'Missing event-data']);
    return response()->json(['message' => 'accepted'], 200);
}
```

Return 200 (not 500) for payloads we can't parse — retrying won't fix malformed data.

---

## Hardening: Webhook Log Retention

### Problem

`email_webhook_logs` has no cleanup. At scale, this table grows unbounded.

### Fix

Add a scheduled command to prune old webhook logs.

File: `backend/app/Console/Commands/PruneWebhookLogsCommand.php`

```php
// Delete webhook logs older than 30 days
EmailWebhookLog::where('created_at', '<', now()->subDays(30))->delete();
```

Register in `backend/routes/console.php`:
```php
Schedule::command('email:prune-webhook-logs')->daily();
```

---

## Tests

New file: `backend/tests/Feature/EmailWebhookTest.php`

| Test | Validates |
|------|-----------|
| `it processes inbound email successfully` | Happy path: webhook → email record created |
| `it rejects duplicate emails by message_id` | Same `message_id` + `mailbox_id` → second request returns null, no duplicate record |
| `it allows same message_id in different mailboxes` | Same `message_id` in mailbox A and B → both created (legit CC scenario) |
| `it handles duplicate provider_event_id` | Same webhook token → early return, no processing |
| `it returns 200 for unrecognized event types` | Unknown delivery event type → 200, logged as failed |
| `it returns 200 for malformed event payloads` | Missing `event-data` → 200, not 500 |
| `it handles complained and unsubscribed events` | These event types → 200, status mapped correctly |
| `it returns 401 for invalid webhook signature` | Bad signature → 401 |

---

## Files Changed

| File | Change |
|------|--------|
| `backend/app/Services/Email/EmailService.php` | Add `message_id` dedup check before email creation; catch unique constraint violation |
| `backend/app/Http/Controllers/Api/EmailWebhookController.php` | Add payload validation guard in `handleEvent` |
| `backend/app/Services/Email/MailgunProvider.php` | Add `complained`/`unsubscribed` to status map; harden null access |
| `backend/database/migrations/2026_03_01_000001_add_unique_...` | **New** — composite unique index on `(mailbox_id, message_id)` |
| `backend/app/Console/Commands/PruneWebhookLogsCommand.php` | **New** — scheduled cleanup of old webhook logs |
| `backend/routes/console.php` | Register prune command schedule |
| `backend/tests/Feature/EmailWebhookTest.php` | **New** — 8 test cases covering dedup, event handling, error paths |

## Execution Order

1. Migration (unique index) — must run first so dedup has DB-level backing
2. `EmailService.php` — dedup logic
3. `MailgunProvider.php` — event type mapping fix
4. `EmailWebhookController.php` — payload validation
5. `PruneWebhookLogsCommand.php` + schedule registration
6. Tests — verify everything
