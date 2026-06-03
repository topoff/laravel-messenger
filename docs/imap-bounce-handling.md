# IMAP Bounce / Complaint / Reply Handling

This package can complement SES/SNS bounce tracking by reading the **reply-to inbox(es)** over IMAP and parsing RFC 3464 DSNs, RFC 5965 ARF reports, and genuine human replies.

The IMAP path fires the **same events** as the SNS path for bounces and complaints — host applications listening on `MessagePermanentBouncedEvent`, `MessageTransientBouncedEvent`, and `MessageComplaintEvent` get notified through either pipe without any code change. Genuine replies fire the new `MessageReplyReceivedEvent`.

## When you need it

Use IMAP processing when one or more of the following are true:

- Some bounces arrive at the **Reply-To** address (`info@…`) instead of via SES `Return-Path`/SNS. This happens when an accepting MTA returns `250 OK` and only later sends an asynchronous DSN to the `Reply-To` header.
- You send via a transport without SNS (e.g. SMTP through a third-party MTA) but still want bounce visibility.
- You want to capture customer replies and route them into downstream logic (lead conversations, support tickets, etc.).

SES/SNS remains authoritative for events it produces. The IMAP path is additive — it never **removes** state from a message, only **adds** failure / complaint records.

## SES Suppression

**The IMAP path intentionally does NOT write to the SES suppression list.** IMAP DSNs are parsed heuristically; a misinterpreted bounce must not globally block a real recipient. If you need to suppress an address after an IMAP-detected hard bounce, subscribe to `MessagePermanentBouncedEvent` in your host application and apply your own domain-specific blocklist. The bounce source is recorded as `tracking_meta.failures[].source = 'imap'` so you can distinguish it from SNS events.

## Setup

### 1. Install the optional dependency

```bash
composer require webklex/laravel-imap
```

Without it, the IMAP scheduler is dormant and `messenger:imap:fetch` raises a clear error.

### 2. Configure the inbox

In your application's `config/messenger.php` (or env vars feeding it):

```php
'imap' => [
    'enabled' => env('MESSENGER_IMAP_ENABLED', true),

    'inboxes' => [
        'topoffer_info' => [
            'host'          => env('MESSENGER_IMAP_INFO_HOST'),
            'port'          => 993,
            'encryption'    => 'ssl',
            'validate_cert' => true,
            'username'      => env('MESSENGER_IMAP_INFO_USERNAME'),  // info@top-offerten.ch
            'password'      => env('MESSENGER_IMAP_INFO_PASSWORD'),
            'folder'        => 'INBOX',

            'max_messages_per_run' => 200,
            'fetch_since_days'     => 14,
        ],
    ],
],
```

### 3. Link the inbox to one or more configuration sets

```php
'ses_sns' => [
    'configuration_sets' => [
        'outreach' => [
            // …
            'identity'   => 'outreach',
            'imap_inbox' => 'topoffer_info',   // ← inbox key from above
        ],
        'support' => [
            'identity'   => 'outreach',
            'imap_inbox' => 'topoffer_info',   // shared inbox is OK
        ],
        'transactional' => [
            'identity'   => 'default',
            'imap_inbox' => null,              // not IMAP-monitored
        ],
    ],
],
```

Multiple configuration sets can share an inbox. The scheduler iterates **inboxes**, not configuration sets, so a shared inbox is polled only once per cron tick.

### 4. Verify

```bash
php artisan messenger:imap:fetch                       # list configured inboxes
php artisan messenger:imap:fetch topoffer_info --dry-run
php artisan messenger:imap:fetch topoffer_info         # one full sweep
```

The scheduler entry runs automatically once `messenger.imap.enabled` is `true` (default cron: `*/10 * * * *`).

## How matching works

When a bounce or reply lands, we look up the originating `Message` row in this order:

1. **`tracking_correlation_id`** (UUID) — read from `X-Topoff-Message-Id` or our stamped RFC 5322 `Message-ID` in the returned-message part of the DSN. Highest confidence.
2. **`tracking_message_id`** (SES message ID) — if visible in the bounced message's headers.
3. **`tracking_recipient_contact` + recent send window** (last 240 hours) — best-effort fallback when neither ID is available.

`tracking_correlation_id` is stamped onto every outgoing email by `MailTracker::injectCorrelationId()`. The corresponding `messages.tracking_correlation_id` column is added by migration `0015_add_tracking_correlation_id_to_messages.php`.

If no match is found, the event is logged as an orphan with diagnostic context (correlation id seen, recipients, subject) and skipped — no event is fired.

## Bounce classification

Mapping from RFC 3464 status code → event:

| Status code | Classification | Event fired | Sub-type examples |
|---|---|---|---|
| `5.x.x` | Hard bounce | `MessagePermanentBouncedEvent` | `NoEmail` (5.1.1), `BadDomain` (5.1.2), `MailboxFull` (5.2.2), `MessageTooLarge` (5.3.4), `Suppressed` (5.7.1) |
| `4.x.x` | Soft bounce | `MessageTransientBouncedEvent` | `MailboxFull` (4.2.2), `ConnectionFailure` (4.4.1) |
| `2.x.x` | (success DSN) | none | logged only |
| missing / unparseable | Soft bounce (conservative) | `MessageTransientBouncedEvent` | `General` |

Diagnostic-code fallback: an SMTP code of `550`/`553`/`521`/`556` in the diagnostic line is treated as hard if no status is available; `421`/`450`/`451`/`452` as soft.

ARF feedback reports (`message/feedback-report`) fire `MessageComplaintEvent` with `complaint_type = feedback-type` (typically `abuse`).

## Idempotency

Every processed inbound message is recorded in the `messenger_imap_processed` table, keyed by `(inbox_key, sha256(raw_message[0..2048]))`. Re-fetching the same message — for any reason, including a worker that crashed before flagging — is a no-op.

## What lands in `tracking_meta`

The IMAP path mirrors the SNS bounce/complaint shape:

- `failures[]` — appended on every IMAP bounce; entries carry `source: 'imap'`, `status`, `diagnostic_code`, `recipient`, `sub_type`, `arrived_at`.
- `success` — flipped to `false` only if no prior success exists (mirrors the SES "accept-then-bounce" handling).
- `imap_message_bounce` — diagnostic record with subject, from, and parsed DSN fields.
- `complaint` / `complaint_type` / `imap_message_complaint` — for ARF complaints.

## Genuine replies

For inbound mail that is not a DSN or ARF, the processor fires `MessageReplyReceivedEvent` with:

| Field | Meaning |
|---|---|
| `message` (nullable) | matched originating `Message`, or `null` if no match (unsolicited inbound) |
| `inboxKey` | the inbox key that received it (useful for routing replies to different teams per inbox) |
| `fromAddress` | sender email (lowercased) |
| `subject` / `textBody` / `htmlBody` | content |
| `rawHeaders` | full lowercased header map for advanced consumers |
| `attachments` | manifest only (`filename`, `mime`, `size`) — no payloads, so the event is queueable |

The default `after_process.reply` action is `seen` (flag as read). Override per-inbox via `messenger.imap.after_process` if you want move/delete behavior.

## Troubleshooting

- **Bounce arrived but no `MessagePermanentBouncedEvent` fired** → check the log for `"ImapBounceProcessor: no matching tracked message"`; the orphan context shows whether the correlation id was visible and which recipients the DSN named.
- **A bounce was processed but isn't reflected in `messages.bounced_at`** → check `tracking_meta.imap_message_bounce` for the parsed DSN. The `bounced_at` column is only set if the message has not previously been flagged bounced.
- **Duplicate events** → the idempotency table (`messenger_imap_processed`) should prevent this. If you see one, check the unique constraint and the row count for the `(inbox_key, fingerprint)` of the affected message.
- **Reply events with `message === null`** → expected for unsolicited inbound; host application must decide what to do.
