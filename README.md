# laravel-messenger

[![Latest Version on Packagist](https://img.shields.io/packagist/v/topoff/laravel-messenger.svg?style=flat-square)](https://packagist.org/packages/topoff/laravel-messenger)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/topoff/laravel-messenger/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/topoff/laravel-messenger/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/topoff/laravel-messenger/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/topoff/laravel-messenger/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/topoff/laravel-messenger.svg?style=flat-square)](https://packagist.org/packages/topoff/laravel-messenger)

Template-driven message sending for Laravel with SES/SNS tracking (opens, clicks, delivery, bounce, complaint), automatic retries with exponential backoff, and Nova integration.

## Installation

```bash
composer require topoff/laravel-messenger
```

Publish the config and migrations:

```bash
php artisan vendor:publish --tag="messenger-config"
php artisan vendor:publish --tag="messenger-migrations"
php artisan migrate
```

## Core Concepts

### Models

**Message** — represents a single outgoing message (email or notification):
- Polymorphic relations: `receiver`, `sender`, `messagable` (MorphTo), `messageType` (BelongsTo)
- Status timestamps: `scheduled_at`, `reserved_at`, `error_at`, `sent_at`, `failed_at`
- Tracking fields: `tracking_hash`, `tracking_message_id`, `tracking_opens`, `tracking_clicks`, `tracking_opened_at`, `tracking_clicked_at`, `tracking_content`
- Error fields: `error_code`, `error_message`, `attempts`
- SoftDeletes enabled

**MessageType** — defines how a message is sent:
- `channel` (mail/vonage), `notification_class`, `single_handler`, `bulk_handler`, `direct` flag
- Per-type config: `dev_bcc`, `error_stop_send_minutes`, `max_retry_attempts` (default: 10), `configuration_set`
- Cached via `MessageTypeRepository` (30-day TTL, `messageType` cache tag)

### Contracts

Your receiver models must implement `MessageReceiverInterface`:

```php
use Topoff\Messenger\Contracts\MessageReceiverInterface;

class User extends Model implements MessageReceiverInterface
{
    public function getEmail(): string { /* ... */ }
    public function getResourceUri(): string { /* ... */ }
    public function setEmailToInvalid(bool $isManualCall = true): void { /* ... */ }
    public function getEmailIsValid(): bool { /* ... */ }
    public function preferredLocale(): string { /* ... */ }
}
```

Mail handlers that support grouping into bulk mails implement `GroupableMailTypeInterface`.

## Usage

### Creating Messages

Use the fluent `MessageService` builder:

```php
use Topoff\Messenger\Services\MessageService;

$service = app(MessageService::class);

$service
    ->setSender(User::class, $user->id)
    ->setReceiver(Company::class, $company->id)
    ->setMessagable(Lead::class, $lead->id)
    ->setMessageTypeClass(NewLeadToCustomerMailHandler::class)
    ->setCompanyId($company->id)
    ->setScheduled(now()->addMinutes(5))
    ->setParams(['key' => 'value'])
    ->setLocale('de')
    ->create();
```

### Scheduling SendMessageJob

The package does **not** schedule `SendMessageJob` automatically. You must add it to your application's `routes/console.php`:

```php
use Topoff\Messenger\Jobs\SendMessageJob;

// Send new messages every minute
Schedule::job(new SendMessageJob, 'messages')
    ->name(SendMessageJob::class)
    ->withoutOverlapping()
    ->everyMinute();

// Retry failed messages every 10 minutes
Schedule::job(new SendMessageJob(isRetryCallForMessagesWithError: true), 'messages')
    ->everyTenMinutes();
```

### Sending Flow

1. **MessageService** — fluent builder, persists a `Message` record
2. **SendMessageJob** — picks up pending messages in chunks of 250, routes to single or bulk handler
3. **MainMailHandler** — single message: reserve -> send -> mark sent
4. **MainBulkMailHandler** — groups messages by receiver -> sends `BulkMail`

### Retry Mechanism

Failed messages are retried with exponential backoff (`min(2^(attempts-1) * 15, 960)` minutes):

| Attempt | Backoff |
|---|---|
| 1 | 15 min |
| 2 | 30 min |
| 3 | 1 hour |
| 4 | 2 hours |
| 5 | 4 hours |
| 6 | 8 hours |
| 7+ | 16 hours (capped) |

Retries stop when `attempts >= max_retry_attempts`, `created_at` exceeds `error_stop_send_minutes`, or the message is marked as permanently failed.

### Permanent Failure Detection

These SMTP codes cause immediate permanent failure (`failed_at` is set, no further retries):

| Code | Meaning |
|---|---|
| 550 | Mailbox doesn't exist / unroutable |
| 553 | Mailbox name not allowed |
| 521 | Host does not accept mail |
| 556 | Domain does not accept mail |
| — | Exception contains "MessageRejected" (SES rejection) |

## Tracking

### Open & Click Tracking

When enabled, the `MailTracker` listener hooks into `MessageSending`:
- Injects a 1x1 tracking pixel (`<img>`)
- Rewrites links to signed tracking URLs
- Injects `X-SES-CONFIGURATION-SET` and `X-SES-MESSAGE-TAGS` headers
- On `MessageSent`: captures the SES message ID from response headers
- Respects `X-No-Track` header to skip tracking

Config keys:

```php
'tracking' => [
    'inject_pixel' => true,
    'track_links' => true,
    'log_content' => true,             // store rendered HTML
    'log_content_strategy' => 'database', // or 'filesystem'
],
```

### Tracking Routes

| Method | URI | Purpose |
|---|---|---|
| GET | `/email/t/{hash}` | Open pixel — returns 1x1 GIF, increments opens |
| GET | `/email/n?l=...&h=...` | Link click — validates signature, increments clicks, redirects |
| POST | `/email/sns` | SNS webhook — processes delivery/bounce/complaint/reject events |

Route prefix and middleware are configurable via `tracking.route`.

### SNS Event Processing

SNS notifications are dispatched to dedicated jobs:

| Event | Job | Effect |
|---|---|---|
| Delivery | `RecordDeliveryJob` | Sets `success: true`, `delivered_at` |
| Bounce | `RecordBounceJob` | Sets `success: false`, dispatches Permanent/Transient event |
| Complaint | `RecordComplaintJob` | Sets `complaint: true`, `success: false` |
| Reject | `RecordRejectJob` | Sets `success: false`, `failed_at` (permanent) |
| Open | `RecordOpenJob` | Increments opens, sets `tracking_opened_at` |
| Click | `RecordLinkClickJob` | Increments clicks, sets `tracking_clicked_at` |

### BCC Recipient Filtering

When BCC is added (via `AddBccToEmailsListener`), both TO and BCC recipients share the same SES message ID. The SNS event jobs guard against this by comparing event recipient(s) against `tracking_recipient_contact`. Events for non-matching recipients are skipped. This is case-insensitive and null-safe.

### Events

- `MessageOpenedEvent`, `MessageLinkClickedEvent` — user interaction
- `MessageDeliveredEvent`, `MessagePermanentBouncedEvent`, `MessageTransientBouncedEvent` — delivery status
- `MessageComplaintEvent`, `MessageRejectedEvent` — negative outcomes
- `SesSnsWebhookReceivedEvent` — raw SNS webhook payload

### Listeners

| Listener | Trigger | Purpose |
|---|---|---|
| `LogEmailsListener` | `MessageSent` | Logs to `email_log` table |
| `LogNotificationListener` | `NotificationSent` | Logs to `notification_log` table |
| `AddBccToEmailsListener` | `MessageSending` | Adds BCC (respects `dev_bcc` per MessageType) |

## SES/SNS Auto Setup

The package can provision all required AWS SES/SNS resources:
- SES Configuration Set + Event Destination (SNS)
- SNS Topic + HTTPS subscription
- SES identities with DKIM + MAIL FROM domains

Enable in config:

```php
'ses_sns' => [
    'enabled' => true,
],
```

### Artisan Commands

| Command | Purpose |
|---|---|
| `messenger:ses-sns:setup-all` | Provision all SES identities + SNS tracking in one go |
| `messenger:ses-sns:setup-tracking` | Set up SNS topic, subscription, config set, event destination |
| `messenger:ses-sns:check-tracking` | Validate tracking infrastructure health |
| `messenger:ses-sns:setup-sending` | Set up SES identities with DKIM + MAIL FROM |
| `messenger:ses-sns:check-sending` | Validate identity verification and DNS records |
| `messenger:ses-sns:test-events` | Simulate SES events (bounce, complaint, delivery) |
| `messenger:ses-sns:teardown` | Remove all provisioned resources (requires `--force`) |

## Automatic Cleanup

The package schedules `CleanupMessengerTablesJob` automatically (configurable via `cleanup.schedule`):

```php
'cleanup' => [
    'messages_delete_after_months' => 24,
    'email_log_delete_after_months' => 24,
    'notification_log_delete_after_months' => 24,
    'message_tracking_content_null_after_days' => 60,
    'schedule' => [
        'enabled' => true,
        'cron' => '17 3 * * *',
    ],
],
```

## Nova Integration

When Laravel Nova is installed, the package provides:

**Resources:** Message (full CRUD with tracking fields), MessageType, EmailLog, NotificationLog

**Actions:**
- Resend failed/errored message as new copy
- Preview rendered HTML of sent messages (signed URL)
- Preview message type templates
- Compose ad-hoc custom emails with markdown editor
- Send SMS/email notifications via AnonymousNotifiable
- Open SES/SNS dashboard

**Filters:** Date range, status, message type, receiver type, messagable type

**Lenses:** Tracking stats by message type, by recipient domain, per-message details

**SES/SNS Dashboard** — web UI at `/emessenger/nova/ses-sns-dashboard` with health checks, DNS records, identity details, AWS Console links, and command buttons.

Config:

```php
'tracking' => [
    'nova' => [
        'enabled' => true,
        'register_resource' => false, // auto-register in Nova
        'resource' => \Topoff\Messenger\Nova\Resources\Message::class,
    ],
],
```

## Configuration Reference

| Section | Key Settings |
|---|---|
| `models.*` | Configurable model classes (message, message_type, email_log, notification_log) |
| `database.*` | Connection name |
| `logs.*` | Connection, table names for email_log / notification_log |
| `cache.*` | Tag (`messageType`), TTL (30 days) |
| `cleanup.*` | Retention periods, tracking_content nullification, schedule cron |
| `mail.*` | Bulk mail class/view/subject/url, custom message view |
| `sending.*` | `check_should_send` callable, `prevent_create_message` callable |
| `bcc.*` | `check_should_add_bcc` callable |
| `tracking.*` | Pixel/link injection, route prefix/middleware, Nova config, content storage, SNS topic |
| `ses_sns.*` | AWS credentials, configuration sets, SNS topic, event types, tenant, Route53 automation |

## Development

```bash
composer test          # Run Pest test suite
composer format        # Laravel Pint
composer analyse       # PHPStan
composer lint          # Pint + PHPStan
composer rector-dry    # Preview Rector refactorings
composer rector        # Apply Rector refactorings
```

The package uses Orchestra Testbench. `php artisan` works in the package root directory.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Andreas Berger](https://github.com/andreasberger83)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
