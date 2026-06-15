# laravel-messenger — Architecture Notes

## Package Overview

Mail management package: template-driven sending via SES, SES event tracking (opens, clicks, delivery, bounce, complaint, reject), Nova integration, audit logging.

### Event transport (SNS HTTP vs. SQS)

SES delivers events to an SNS topic. How those events then reach the application is selectable via `messenger.tracking.event_transport`:

| Transport | Value | Path | Notes |
|---|---|---|---|
| SNS HTTP webhook | `sns_http` (default) | SES → SNS → HTTPS `POST` → `MailTrackingSnsController` | Zero extra infra; needs a public HTTPS endpoint. Optional SNS signature verification. |
| SQS poll | `sqs` | SES → SNS → SQS → `messenger:tracking:sqs-poll` → `SqsTrackingPoller` | No public endpoint; durable buffer (up to 14 days); native DLQ + redrive. |

SES cannot target SQS directly, so the SQS transport is **SES → SNS → SQS** and reuses the same SNS topic and provisioning. Both transports funnel raw payloads through one **`SnsNotificationProcessor`** (`src/Services/SesSns/SnsNotificationProcessor.php`) so envelope parsing, topic verification, test-detection and job routing live in a single place. The processor handles both the SNS envelope shape and raw-message-delivery bodies.

## Models

### Message (`src/Models/Message.php`)
- Polymorphic relations: `receiver`, `sender`, `messagable` (MorphTo), `messageType` (BelongsTo)
- Status timestamps: `scheduled_at`, `reserved_at`, `error_at`, `sent_at`, `failed_at`, `delivered_at`, `bounced_at`
- Tracking fields: `tracking_hash`, `tracking_message_id`, `tracking_recipient_contact`, `tracking_sender_contact`, `tracking_subject`, `tracking_opens`, `tracking_clicks`, `tracking_meta`, `tracking_content`, `tracking_content_path`
- Tracking timestamps: `tracking_opened_at`, `tracking_clicked_at`
- Error fields: `error_code`, `error_message`, `attempts`
- Scopes: `hasErrorAndIsNotSent()` (excludes `failed_at IS NOT NULL`), `isScheduledButNotSent()`
- SoftDeletes enabled

### MessageType (`src/Models/MessageType.php`)
- Defines: `channel`, `notification_class`, `single_handler`, `bulk_handler`, `direct` flag
- Config: `dev_bcc`, `error_stop_send_minutes`, `required_*` fields (sender, messagable, company_id, scheduled, text, params), `configuration_set`, `max_retry_attempts` (default: 10)
- Cached via `MessageTypeRepository` (30-day TTL, `messageType` cache tag)

### MessageLog (`src/Models/MessageLog.php`)
- Unified audit trail model for all outgoing communications (emails & notifications)
- Configurable table (`messenger.logs.message_log_table`) & connection (`messenger.logs.connection`)
- Key fields: `channel` (mail, vonage, etc.), `to`, `type`, `subject`, `cc`, `bcc`, `has_attachment`, `notifyable_id`, `notification_id`

## Mail Sending Flow

1. **MessageService** — fluent builder: `setSender()`, `setReceiver()`, etc.
   - `create()` — persists Message to DB; sending is deferred to `SendMessageJob`
   - `createAndSendNow()` — persists Message and immediately invokes the mail handler synchronously (bypasses queue). Use for time-sensitive emails like password resets or email verification where the user expects instant delivery. Forces `scheduled_at = null`.
2. **SendMessageJob** — processes direct (single handler) & indirect (bulk handler) messages
   - Chunks of 250, exponential backoff retry
   - Supports MySQL, PostgreSQL, SQLite, SQL Server date calculations
3. **MainMailHandler** — single message: reserve → send → mark sent
4. **MainBulkMailHandler** — groups by receiver → sends BulkMail

### Retry Mechanism

**Exponential backoff** via `SendMessageJob::buildBackoffWhereRaw()`:

| Attempt | Backoff |
|---|---|
| 1 | 15 minutes |
| 2 | 30 minutes |
| 3 | 1 hour |
| 4 | 2 hours |
| 5 | 4 hours |
| 6 | 8 hours |
| 7+ | 16 hours (capped) |

Formula: `min(2^(attempts-1) * 15, 960)` minutes. Database-specific SQL for MySQL, PostgreSQL, SQLite, SQL Server.

Retries stop when:
- `attempts >= message_type.max_retry_attempts` (default: 10)
- `created_at` exceeds `error_stop_send_minutes` (default: 3 days)
- `failed_at IS NOT NULL` (permanent failure — never retried)

### Permanent Failure Detection (`MainMailHandler::isPermanentFailure()`)

| Code | Meaning |
|---|---|
| 550 | Mailbox doesn't exist / unroutable |
| 553 | Mailbox name not allowed |
| 521 | Host does not accept mail |
| 556 | Domain does not accept mail |
| — | Exception message contains "MessageRejected" (SES rejection) |

Permanent failures set `failed_at = now()` and skip `onError()`. Transient failures set `error_at = now()` and call `onError()`.

### CustomMessageMail (`src/Mail/CustomMessageMail.php`)

A generic Mailable for ad-hoc emails with markdown content. Used by `SendCustomMailAction` (Nova) and can be used programmatically via `MessageService`.

**Params** (stored in `Message.params`):

| Key | Required | Description |
|-----|----------|-------------|
| `subject` | yes | Email subject line |
| `text` | yes | Markdown body content |
| `mailer` | no | Mail transport override (e.g. `smtp`, `ses`). Persisted per message, so it works correctly when sent via queued `SendMessageJob` / Horizon. |
| `ses_configuration_set` | no | SES configuration set key override (must match a key in `messenger.ses_sns.configuration_sets`). Overrides the MessageType's default. Used by MailTracker to inject `X-SES-CONFIGURATION-SET` header. |

**Example — sending programmatically:**

```php
resolve(MessageService::class)
    ->setReceiver(Company::class, $company->id)
    ->setMessageTypeClass(CustomMessageMail::class)
    ->setParams([
        'subject' => 'Important update',
        'text' => '**Hello!** This is a markdown email.',
        'mailer' => 'ses',
        'ses_configuration_set' => 'transactional',
    ])
    ->create(); // queued, or ->createAndSendNow() for immediate
```

### Message Resending (`src/Tracking/MessageResender.php`)

Creates a new message copying all data from original, resets all status fields (`scheduled_at`, `reserved_at`, `error_at`, `sent_at`, `failed_at`), resets tracking fields, stores `resent_from_message_id` in `tracking_meta`. Dispatches `ResendMessageJob`. If original had `error_at` or `failed_at`, it is soft-deleted.

## Notification Sending Flow (SMS / Vonage)

The notification channel mirrors the mail channel for non-email delivery (currently SMS via Vonage).

### Handler: `MainNotificationHandler`

Lifecycle:
1. Constructor sets `reserved_at = now()`
2. `send()`: validates receiver has `notify()` (Notifiable trait), instantiates notification class, tags it with `$notification->messengerMessageId = $this->message->id`, calls `$receiver->notify($notification)`
3. On success: sets `sent_at = now()`, calls `onSuccessfulSent()` hook

Overridable methods: `shouldBeSentNow()`, `shouldBeSentInThisEnvironment()`, `abortAndDeleteWhen()`, `onSuccessfulSent()`, `onError()`, `getNotificationParameters()`.

Key differences from `MainMailHandler`:
- No bulk handler variant (SMS is always direct/single)
- No automatic tracking injection (no pixel/link tracking for SMS)
- No content storage (`tracking_content`) — SMS body lives in `params`
- No BCC guard needed (SMS is point-to-point)
- Permanent failure detection happens via DLR webhook, not at send-time

### Capturing Vonage Response at Send-Time

`RecordNotificationSentListener` listens to `NotificationSent` and:
- Skips non-messenger notifications (no `messengerMessageId` on the notification)
- Skips non-vonage channels
- Extracts from the Vonage `Collection` response: message ID, recipient phone, status, network, price
- Stores: `tracking_message_id`, `tracking_recipient_contact`, `tracking_meta` (vonage_status, vonage_network, vonage_message_price)

### Vonage DLR (Delivery Receipt) Webhook

Route: `GET|POST {prefix}/vonage-dlr` — receives Vonage delivery receipts.

Controlled by `config('messenger.tracking.vonage_dlr.enabled')` (default: `false`). Controller returns early if disabled.

`RecordVonageDlrJob` processes the raw webhook payload:
- Finds Message by `tracking_message_id` = Vonage `messageId`
- Updates `tracking_meta`: `dlr_status`, `dlr_err_code`, `dlr_timestamp`, `dlr_price`, `dlr_network_code`
- On `delivered` status: sets column `delivered_at` (from `message-timestamp`), plus `tracking_meta.success = true`
- On `failed`/`rejected`/`expired`: sets `failed_at = now()` (permanent, no retry)

### Mail vs SMS Tracking Comparison

| Aspect | Mail (SES) | SMS (Vonage) |
|--------|-----------|--------------|
| Send response capture | `MailTracker::messageSent()` | `RecordNotificationSentListener` |
| Tracking ID source | SES `X-SES-Message-ID` header | Vonage `SentSMS::getMessageId()` |
| Delivery confirmation | SNS → `RecordDeliveryJob` | DLR → `RecordVonageDlrJob` |
| Bounce/complaint | SNS → `RecordBounceJob` / `RecordComplaintJob` | DLR status `failed`/`rejected` → `failed_at` |
| Pixel/link tracking | Yes (injected into HTML) | N/A |
| Content storage | `tracking_content` / `tracking_content_path` | N/A (body in `params`) |
| BCC guard | Yes (per-recipient filtering) | N/A (point-to-point) |

## Tracking Flow

### Injection (`src/Tracking/MailTracker.php`)
- Hooks into `MessageSending` event
- Generates 32-char tracking hash
- Injects pixel `<img>` + rewrites links to signed tracking URLs
- Injects `X-SES-CONFIGURATION-SET` and `X-SES-MESSAGE-TAGS` headers
- Can override From address based on identity config
- Persists tracking metadata to Message (including `tracking_recipient_contact` from TO address)
- On `MessageSent`: updates `tracking_message_id` from SES response header
- Respects `X-No-Track` header to skip tracking

### SES Event Processing

Ingestion is transport-agnostic. The HTTP controller (`MailTrackingSnsController`) and the SQS poller (`SqsTrackingPoller`) both delegate to `SnsNotificationProcessor::processEnvelope()`, which:
- confirms SNS `SubscriptionConfirmation` handshakes (HTTP path),
- unwraps the SNS envelope (or accepts a raw-delivery SES body),
- optionally rejects payloads whose `TopicArn` ≠ `messenger.tracking.sns_topic`,
- dispatches the matching `Record*Job` onto `messenger.tracking.tracker_queue` (synchronously for messenger test notifications).

**SNS signature verification (HTTP path only):** when `messenger.tracking.sns.verify_signature` is `true`, `MailTrackingSnsController` validates the SNS message signature via `Aws\Sns\MessageValidator` before processing, rejecting forged events. Requires the suggested `aws/aws-php-sns-message-validator` package; degrades to "allow" when the package is absent. The SQS transport does not need this (queue access is IAM-authenticated).

Jobs in `src/Jobs/`:
- `RecordDeliveryJob` — sets column `delivered_at`, plus `tracking_meta.success = true`, `tracking_meta.smtpResponse`, `tracking_meta.sns_message_delivery`
- `RecordBounceJob` — sets column `bounced_at`, appends to `tracking_meta.failures[]`, dispatches Permanent/Transient events. Only sets `tracking_meta.success = false` if no prior delivery — never overwrites a previous `success: true` (handles SES "accept-then-bounce" where the recipient MTA returns `250 OK` and later sends an async DSN)
- `RecordComplaintJob` — sets `tracking_meta.complaint: true`, `tracking_meta.success: false`, `tracking_meta.complaint_type`
- `RecordRejectJob` — sets `tracking_meta.success: false`, sets `failed_at = now()`
- `RecordOpenJob` / `RecordLinkClickJob` — increments opens/clicks counters, sets `tracking_opened_at`/`tracking_clicked_at` on first event

All use `ExtractsSesMessageTags` trait for SES tag extraction.

### SNS Payload Structure

| Job | Recipient path | Type |
|-----|---------------|------|
| `RecordDeliveryJob` | `delivery.recipients` | `string[]` |
| `RecordBounceJob` | `bounce.bouncedRecipients` | `object[]` with `emailAddress` |
| `RecordComplaintJob` | `complaint.complainedRecipients` | `object[]` with `emailAddress` |
| `RecordRejectJob` | N/A | No per-recipient data |

### IMAP Bounce Processing (additive to SNS)

Full setup, classification table, troubleshooting → [imap-bounce-handling.md](imap-bounce-handling.md).

Summary:
- Optional path that reads the reply-to inbox(es) over IMAP (`webklex/laravel-imap`, suggested) and parses RFC 3464 DSNs / RFC 5965 ARF / replies.
- Fires the **same** `MessagePermanentBouncedEvent` / `MessageTransientBouncedEvent` / `MessageComplaintEvent` as the SNS pipe — consumers do not branch on source. The IMAP source is recorded as `tracking_meta.failures[].source = 'imap'`.
- Genuine replies fire a new `MessageReplyReceivedEvent` (nullable `Message` for unmatched inbound).
- Matching uses `tracking_correlation_id` (UUID stamped by `MailTracker` as `X-Topoff-Message-Id` and RFC 5322 `Message-ID`), then `tracking_message_id`, then a recipient-time-window fallback.
- Inbox ↔ configuration-set link is explicit: set `ses_sns.configuration_sets[<key>].imap_inbox` to one of `imap.inboxes` keys. Multiple config sets may share an inbox.
- **Never** writes to the SES suppression list — that remains exclusive to the SNS pipe.
- Idempotency via `messenger_imap_processed` table keyed by `(inbox_key, sha256(raw_message[0..2048]))`.
- Scheduler: one `ProcessImapInboxJob($inboxKey)` per inbox, cron-driven (`messenger.imap.schedule.cron`), `withoutOverlapping` + `onOneServer` by default.

### BCC Recipient Guard

**Problem:** BCC recipients share the same SES message ID. SNS events for BCC would corrupt the TO recipient's tracking data.

**Solution:** Delivery/Bounce/Complaint jobs compare event recipient(s) against `Message.tracking_recipient_contact`. Skip if no match. Null-safe (null = process all). Case-insensitive via `mb_strtolower()`.

## Events

- `MessageOpenedEvent`, `MessageLinkClickedEvent` — user interaction
- `MessageDeliveredEvent`, `MessagePermanentBouncedEvent`, `MessageTransientBouncedEvent` — delivery status (fired by both SNS and IMAP paths)
- `MessageComplaintEvent`, `MessageRejectedEvent` — negative outcomes
- `MessageReplyReceivedEvent` — genuine human reply observed in an IMAP reply-to inbox
- `MessageTrackingValidActionEvent` — generic valid action
- `SesSnsWebhookReceivedEvent` — raw SNS webhook
- `ImapMessageProcessedEvent` — low-level per-inbound-message event for observability (one per IMAP fetch)

## Listeners

- `LogEmailToMessageLogListener` — logs to `message_log` table on `MessageSent` (channel=mail)
- `LogNotificationToMessageLogListener` — logs to `message_log` table on `NotificationSent` (queued)
- `AddBccToEmailsListener` — adds BCC on `MessageSending` (respects `dev_bcc` flag per MessageType)
- `RecordNotificationSentListener` — captures Vonage SMS response on `NotificationSent` (message ID, phone, status, network, price)

## SES/SNS Provisioning

Services in `src/Services/SesSns/`:
- `AwsSesSnsProvisioningApi` — AWS SDK wrapper (config sets, event destinations, SNS topics, HTTPS + SQS subscriptions, SQS queues/DLQ, Route53 records). Uses `AwsClientConfig::shared()` for credentials.
- `InfomaniakDnsApi` — Infomaniak DNS API wrapper (zone lookup, record list/create/update/delete, upsert with reconciliation)
- `SesSnsSetupService` — orchestrates full setup/teardown, provides `check()` with health validation. Branches on `messenger.tracking.event_transport`: HTTPS subscription for `sns_http`, SQS queue + DLQ + queue policy + subscription for `sqs`.
- `SnsNotificationProcessor` — transport-agnostic processing of SES event notifications (used by the HTTP controller and the SQS poller)
- `SqsTrackingPoller` — drains the SQS queue and feeds bodies through `SnsNotificationProcessor`; failed messages are left for SQS redrive to the DLQ
- `SesSendingSetupService` — SES identities, DKIM verification, MAIL FROM domains, DMARC, DNS record retrieval, DNS automation (Infomaniak or Route53)
- `SesEventSimulatorService` — simulates SES events for testing

### DNS Automation

`SesSendingSetupService` can upsert the generated DNS records (DKIM CNAMEs, MAIL FROM MX/TXT, DMARC TXT) at the user's DNS provider after each `setup()`. Order of precedence:

1. **Infomaniak** — `messenger.ses_sns.sending.infomaniak.enabled` + `INFOMANIAK_API_TOKEN`. Uses `/2/domains/{domain}/zones` to discover the managed zone and `/2/zones/{zone}/records` to reconcile. Source values are sent relative to the zone; MX priority goes in `description.priority.value`; TXT values are sent unquoted.
2. **Route53** — `messenger.ses_sns.sending.route53.enabled` + `auto_create_records=true`. Uses the AWS SDK's `changeResourceRecordSets`.
3. None — records are printed for manual entry.

The DMARC record is built from per-identity `dmarc` config (string = full TXT value; `false` = skip; omitted = default `v=DMARC1; p=none;`).

### SesSnsSetupService::check()

Returns comprehensive validation:
- `ok` — all checks passing
- `checks[]` — SNS topic, subscription, config set, event destination status
- `configuration` — current config values
- `aws_console` — 7 direct links to AWS Console (SES dashboard, identities, config sets, reputation, tenants; SNS topics, subscriptions)

### SesSendingSetupService::check()

Returns identity & DNS status:
- `ok` — all identities verified
- `checks[]` — identity exists, verified, MAIL FROM status, tenant & config set associations
- `dns_records[]` — DKIM CNAMEs, MAIL FROM MX/TXT, DMARC TXT (with identity name and status)
- `identities_details{}` — per-identity DKIM (status, tokens, signing key length), MAIL FROM (domain, status, MX failure behavior), DMARC record

## Artisan Commands

| Command | Purpose |
|---|---|
| `messenger:ses-sns:setup-all` | Provision all SES identities + SNS tracking in one go |
| `messenger:ses-sns:setup-tracking` | Set up SNS topic, subscription, config set, event destination |
| `messenger:ses-sns:check-tracking` | Validate tracking infrastructure health |
| `messenger:ses-sns:setup-sending` | Set up SES identities with DKIM + MAIL FROM |
| `messenger:ses-sns:check-sending` | Validate identity verification and DNS records |
| `messenger:ses-sns:test-events` | Simulate SES events (bounce, complaint, delivery) for testing |
| `messenger:ses-sns:teardown` | Remove all provisioned SES/SNS resources, incl. the SQS queue + DLQ when the SQS transport is active (requires `--force`) |
| `messenger:imap:fetch [inbox] [--dry-run] [--limit=N]` | List or sweep IMAP inboxes for bounces/complaints/replies (see [imap-bounce-handling.md](imap-bounce-handling.md)) |
| `messenger:tracking:sqs-poll [--once] [--max-messages=N] [--max-time=S]` | Drain the SQS queue that SNS fans SES events into (SQS transport). Auto-scheduled when `event_transport = sqs`. |

## Nova Integration

### Resources (`src/Nova/Resources/`)

**Message** — full CRUD with 37 fields across all message properties:
- Core: ID, receiver/sender (type + id), message_type_id, messagable (type + id), params (KeyValue)
- Status: scheduled_at, reserved_at, error_at, sent_at, failed_at, attempts, error_code, error_message
- Tracking: all tracking_* fields (hash, message_id, subject, sender/recipient contact/name, opens, clicks, opened_at, clicked_at, content_path, meta)
- Timestamps: created_at, updated_at, deleted_at

**MessageType** — manages message type definitions:
- Core: channel, notification_class, single_handler, bulk_handler, direct (Boolean)
- Config: dev_bcc, error_stop_send_minutes, max_retry_attempts, configuration_set
- Required flags: required_sender, required_messagable, required_company_id, required_scheduled, required_text, required_params
- Template: bulk_message_line (Blade template for bulk emails)

### Actions (`src/Nova/Actions/`)

| Action | Purpose |
|---|---|
| `ResendAsNewMessageAction` | Resend failed/errored/sent message as new copy; soft-deletes original if errored/failed |
| `ShowRealSentMessageAction` | View rendered HTML of actually sent message (signed URL, 15 min expiry) |
| `PreviewMessageInBrowserAction` | Preview message template rendering (signed URL, 10 min expiry) |
| `PreviewMessageTypeInBrowserAction` | Preview a message type's template (requires selecting a message) |
| `SendCustomMailAction` | Compose ad-hoc emails with markdown editor, supports preview-only, scheduling, mailer selection, and SES configuration set override |
| `SendNotificationAction` | Send SMS/email notifications via AnonymousNotifiable (mail & vonage channels) |
| `OpenSesSnsSiteAction` | Open SES/SNS dashboard (standalone action, signed URL, 30 min expiry) |

### Filters (`src/Nova/Filters/`)

| Filter | Type | Purpose |
|---|---|---|
| `DateFilter` | Date range | Generic date filter for created_at, sent_at, error_at |
| `MessagesStatusFilter` | Select | Filter by status field: Scheduled, Reserved, Sent, Error, Failed |
| `MessagesMessageTypeFilter` | Select | Filter by message_type_id |
| `MessagesReceiverTypeFilter` | Select | Filter by receiver_type (polymorphic) |
| `MessagesMessageableTypeFilter` | Select | Filter by messagable_type (polymorphic) |

### Lenses (`src/Nova/Lenses/`)

| Lens | Purpose |
|---|---|
| `MessagesByTypeTrackingLens` | Aggregate tracking stats (sent, opens, clicks, rates) grouped by message type |
| `MessagesByDomainTrackingLens` | Aggregate tracking stats grouped by recipient email domain |
| `MessagesTrackingLens` | Per-message tracking details (individual opens/clicks) |

### SES/SNS Dashboard

Web-based dashboard at `/emessenger/nova/ses-sns-dashboard` (signed URL via `OpenSesSnsSiteAction`):
- **Status overview cards** — sending, tracking, mail transport health
- **Health checks tables** — detailed SES and SNS check results with ok/fail/warn badges
- **DNS records table** — required DKIM, MAIL FROM, DMARC records with copy-to-clipboard buttons
- **Identity detail cards** — per-identity DKIM status, MAIL FROM config, DMARC record
- **Actions** — grouped command buttons for setup, checks, tests, teardown
- **AWS Console links** — direct links to SES (dashboard, identities, config sets, reputation, tenants) and SNS (topics, subscriptions)
- **Reference sections** — collapsible env vars, config snapshot, artisan commands
- **Event transport badge** — shows the active transport (`sns_http` / `sqs`) and the relevant next steps; the same Setup/Check Tracking buttons provision the SQS queue + DLQ when the SQS transport is active.

## Filament Integration

The package ships a Filament panel integration in `src/Filament/Resources/` mirroring the Nova resources, for apps on Filament (the Nova lenses map to Filament custom Pages).

### Resources
- `MessageResource` — table (all message columns), filters (date range, status, accept-then-bounce, channel, message type, receiver/messageable type, trashed), row actions (show sent, preview, resend) + bulk resend/delete, header actions (send custom email, send notification).
- `MessageTypeResource` — table + filters (channel, trashed), preview action, and the **SES/SNS Dashboard** header link (opens the shared signed dashboard route — also reachable from Filament).
- `MessageLogResource` — audit-log listing.

### Tracking Pages (Filament counterparts of the Nova lenses)
| Filament Page | Nova Lens |
|---|---|
| `MessageResource/Pages/TrackingByType` | `MessagesByTypeTrackingLens` |
| `MessageResource/Pages/TrackingByDomain` | `MessagesByDomainTrackingLens` |
| `MessageResource/Pages/TrackingByBounceSource` | `MessagesByBounceSourceLens` |
| `MessageResource/Pages/TrackingPerMessage` | `MessagesTrackingLens` |
| `MessageResource/Pages/CompanyTrackingMetrics` | `CompanyTrackingMetricsLens` |

All five are linked as header actions on the message list and registered in `MessageResource::getPages()`. `CompanyTrackingMetrics` adds the company status / deleted filters only when a host `companies` table exists, so the page stays usable in apps without one. The Filament resources are excluded from the package's PHPStan run (Filament is only installed in consuming apps); they're validated where Filament runs.

## Configuration Reference (`config/messenger.php`)

| Section | Key Settings |
|---|---|
| `models.*` | Configurable model classes: message, message_type, message_log |
| `database.*` | Connection name, table names for all models |
| `cache.*` | Tag: `messageType`, TTL: 30 days |
| `cleanup.*` | Delete messages/logs after 24 months; null tracking_content after 60 days; schedule cron `17 3 * * *` |
| `mail.*` | default_bulk_mail_class, bulk_mail_view/subject/url, custom_message_view |
| `sending.*` | `check_should_send` callable (null = production only) |
| `bcc.*` | `check_should_add_bcc` callable (null = always add when provided) |
| `tracking.*` | inject_pixel, track_links, nova resource class, preview route config, content storage, vonage_dlr (enabled), `sns.verify_signature` (HTTP signature check), `event_transport` (`sns_http`\|`sqs`) |
| `ses_sns.*` | AWS region/credentials, configuration_sets (keyed by name with identity + event_destination + optional `imap_inbox`), topic name, event types, callback endpoint, `sqs.*` (queue/DLQ names, raw_message_delivery, long-poll/visibility tuning, schedule), `create_sqs_subscription_if_missing`, tenant config, Route53 automation |
| `imap.*` | Optional IMAP bounce/complaint/reply ingestion: `enabled`, `inboxes` (keyed), `after_process`, `folders`, `schedule` |

## Migrations (10 total)

1. Create messages + message_types tables
2. Add tracking_* columns to messages
3. Custom message mail type
4. Locale column
5. Email log table (legacy)
6. Notification log table (legacy)
7. SES configuration_set on message_types
8. Retry improvements (failed_at on messages, max_retry_attempts on message_types)
9. Rename columns for channel support (mail_class→notification_class, email_error→error_message, etc.) + add `channel` field
10. Unified message_log table (replaces email_log + notification_log)
11. `delivered_at` + `bounced_at` on messages (0013) + backfill (0014)
12. `tracking_correlation_id` UUID on messages (0015) — stamped into outgoing Message-ID + X-Topoff-Message-Id for IMAP bounce matching
13. `messenger_imap_processed` idempotency table (0016) — keyed by `(inbox_key, fingerprint)`

## Test Structure

Tests use Pest 4.0 + Orchestra Testbench. Helpers in `tests/Helpers.php`: `createMessage()`, `createMessageType()`, `createReceiver()`, `createSender()`, `createMessagable()`.

Key test files:
- `tests/Tracking/MailTrackingSnsControllerTest.php` — SNS webhook processing (9 tests incl. BCC guard)
- `tests/Tracking/MailTrackerTest.php` — pixel/link injection & metadata persistence
- `tests/Jobs/SendMessageJobTest.php` — send orchestration, retry logic, exponential backoff
- `tests/Services/MessageServiceTest.php` — fluent builder
- `tests/Listeners/AddBccToEmailsListenerTest.php` — BCC logic

## Development

### Workbench

The package uses Orchestra Testbench workbench for local development:
- `workbench/` — app providers, models, mail handlers, routes, bootstrap
- `testbench.yaml` — workbench configuration
- `artisan` — root-level artisan file that bootstraps the workbench app (enables `php artisan` in package dir)
- `vendor/bin/testbench` — alternative entry point

### Composer Scripts

| Script | Command |
|---|---|
| `composer test` | Run Pest test suite |
| `composer build` | `testbench workbench:build` |
| `composer serve` | `testbench serve` |
| `composer prepare` | `testbench package:discover` |
