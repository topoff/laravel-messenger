# laravel-mail-manager — Architecture Notes

## Package Overview

Mail management package: template-driven sending via SES, SNS event tracking (opens, clicks, delivery, bounce, complaint, reject), Nova integration, audit logging.

## Models

### Message (`src/Models/Message.php`)
- Polymorphic relations: `receiver`, `sender`, `messagable` (MorphTo), `messageType` (BelongsTo)
- Status timestamps: `scheduled_at`, `reserved_at`, `error_at`, `sent_at`, `failed_at`
- Tracking fields: `tracking_hash`, `tracking_message_id`, `tracking_recipient_contact`, `tracking_sender_contact`, `tracking_subject`, `tracking_opens`, `tracking_clicks`, `tracking_meta`, `tracking_content`, `tracking_content_path`
- Tracking timestamps: `tracking_opened_at`, `tracking_clicked_at`
- Error fields: `error_code`, `error_message`, `attempts`
- Scopes: `hasErrorAndIsNotSent()` (excludes `failed_at IS NOT NULL`), `isScheduledButNotSent()`
- SoftDeletes enabled

### MessageType (`src/Models/MessageType.php`)
- Defines: `channel`, `notification_class`, `single_handler`, `bulk_handler`, `direct` flag
- Config: `dev_bcc`, `error_stop_send_minutes`, `required_*` fields (sender, messagable, company_id, scheduled, text, params), `configuration_set`, `max_retry_attempts` (default: 10)
- Cached via `MessageTypeRepository` (30-day TTL, `messageType` cache tag)

### EmailLog / NotificationLog
- Simple audit trail models with configurable tables & connections

## Mail Sending Flow

1. **MessageService** — fluent builder: `setSender()`, `setReceiver()`, etc. → `create()` persists Message
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

### Message Resending (`src/Tracking/MessageResender.php`)

Creates a new message copying all data from original, resets all status fields (`scheduled_at`, `reserved_at`, `error_at`, `sent_at`, `failed_at`), resets tracking fields, stores `resent_from_message_id` in `tracking_meta`. Dispatches `ResendMessageJob`. If original had `error_at` or `failed_at`, it is soft-deleted.

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

### SNS Event Processing
Jobs in `src/Jobs/`:
- `RecordDeliveryJob` — sets `success: true`, `delivered_at`, `smtpResponse`
- `RecordBounceJob` — sets `success: false`, appends to `failures[]`, dispatches Permanent/Transient events
- `RecordComplaintJob` — sets `complaint: true`, `success: false`, `complaint_type`
- `RecordRejectJob` — sets `success: false`, sets `failed_at = now()`
- `RecordOpenJob` / `RecordLinkClickJob` — increments opens/clicks counters, sets `tracking_opened_at`/`tracking_clicked_at` on first event

All use `ExtractsSesMessageTags` trait for SES tag extraction.

### SNS Payload Structure

| Job | Recipient path | Type |
|-----|---------------|------|
| `RecordDeliveryJob` | `delivery.recipients` | `string[]` |
| `RecordBounceJob` | `bounce.bouncedRecipients` | `object[]` with `emailAddress` |
| `RecordComplaintJob` | `complaint.complainedRecipients` | `object[]` with `emailAddress` |
| `RecordRejectJob` | N/A | No per-recipient data |

### BCC Recipient Guard

**Problem:** BCC recipients share the same SES message ID. SNS events for BCC would corrupt the TO recipient's tracking data.

**Solution:** Delivery/Bounce/Complaint jobs compare event recipient(s) against `Message.tracking_recipient_contact`. Skip if no match. Null-safe (null = process all). Case-insensitive via `mb_strtolower()`.

## Events

- `MessageOpenedEvent`, `MessageLinkClickedEvent` — user interaction
- `MessageDeliveredEvent`, `MessagePermanentBouncedEvent`, `MessageTransientBouncedEvent` — delivery status
- `MessageComplaintEvent`, `MessageRejectedEvent` — negative outcomes
- `MessageTrackingValidActionEvent` — generic valid action
- `SesSnsWebhookReceivedEvent` — raw SNS webhook

## Listeners

- `LogEmailsListener` — logs to `email_log` table on `MessageSent`
- `LogNotificationListener` — logs to `notification_log` table on `NotificationSent`
- `AddBccToEmailsListener` — adds BCC on `MessageSending` (respects `dev_bcc` flag per MessageType)

## SES/SNS Provisioning

Services in `src/Services/SesSns/`:
- `AwsSesSnsProvisioningApi` — AWS SDK wrapper (config sets, event destinations, SNS topics, subscriptions)
- `SesSnsSetupService` — orchestrates full setup/teardown, provides `check()` with health validation
- `SesSendingSetupService` — SES identities, DKIM verification, MAIL FROM domains, DNS record retrieval
- `SesEventSimulatorService` — simulates SES events for testing

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
| `mail-manager:ses-sns:setup-all` | Provision all SES identities + SNS tracking in one go |
| `mail-manager:ses-sns:setup-tracking` | Set up SNS topic, subscription, config set, event destination |
| `mail-manager:ses-sns:check-tracking` | Validate tracking infrastructure health |
| `mail-manager:ses-sns:setup-sending` | Set up SES identities with DKIM + MAIL FROM |
| `mail-manager:ses-sns:check-sending` | Validate identity verification and DNS records |
| `mail-manager:ses-sns:test-events` | Simulate SES events (bounce, complaint, delivery) for testing |
| `mail-manager:ses-sns:teardown` | Remove all provisioned SES/SNS resources (requires `--force`) |

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
| `SendCustomMailAction` | Compose ad-hoc emails with markdown editor, supports preview-only and scheduling |
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

Web-based dashboard at `/email-manager/nova/ses-sns-dashboard` (signed URL via `OpenSesSnsSiteAction`):
- **Status overview cards** — sending, tracking, mail transport health
- **Health checks tables** — detailed SES and SNS check results with ok/fail/warn badges
- **DNS records table** — required DKIM, MAIL FROM, DMARC records with copy-to-clipboard buttons
- **Identity detail cards** — per-identity DKIM status, MAIL FROM config, DMARC record
- **Actions** — grouped command buttons for setup, checks, tests, teardown
- **AWS Console links** — direct links to SES (dashboard, identities, config sets, reputation, tenants) and SNS (topics, subscriptions)
- **Reference sections** — collapsible env vars, config snapshot, artisan commands

## Configuration Reference (`config/mail-manager.php`)

| Section | Key Settings |
|---|---|
| `models.*` | Configurable model classes: message, message_type, email_log, notification_log |
| `database.*` | Connection name, table names for all models |
| `cache.*` | Tag: `messageType`, TTL: 30 days |
| `cleanup.*` | Delete messages/logs after 24 months; null tracking_content after 60 days; schedule cron `17 3 * * *` |
| `mail.*` | default_bulk_mail_class, bulk_mail_view/subject/url, custom_message_view |
| `sending.*` | `check_should_send` callable (null = production only) |
| `bcc.*` | `check_should_add_bcc` callable (null = always add when provided) |
| `tracking.*` | inject_pixel, track_links, nova resource class, preview route config, content storage |
| `ses_sns.*` | AWS region/credentials, configuration_sets (keyed by name with identity + event_destination), topic name, event types, callback endpoint, tenant config, Route53 automation |

## Migrations (9 total)

1. Create messages + message_types tables
2. Add tracking_* columns to messages
3. Custom message mail type
4. Locale column
5. Email log table
6. Notification log table
7. SES configuration_set on message_types
8. Retry improvements (failed_at on messages, max_retry_attempts on message_types)
9. Rename columns for channel support (mail_class→notification_class, email_error→error_message, etc.) + add `channel` field

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
