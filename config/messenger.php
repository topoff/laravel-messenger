<?php

declare(strict_types=1);

use Topoff\Messenger\Mail\BulkMail;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Models\MessageLog;
use Topoff\Messenger\Models\MessageType;

return [
    'models' => [
        'message' => Message::class,
        'message_type' => MessageType::class,
        'message_log' => MessageLog::class,
    ],

    'database' => [
        'connection' => 'mysql',
    ],

    'logs' => [
        'connection' => 'mysql',
        'message_log_table' => 'message_log',
    ],

    'cache' => [
        'tag' => 'messageType',
        'ttl' => 60 * 60 * 24 * 30,
    ],

    'cleanup' => [
        // Null disables deletion. Positive integer = delete records older than X months.
        'messages_delete_after_months' => 24,
        'message_log_delete_after_months' => 24,

        // Null disables this cleanup. Positive integer = set messages.tracking_content
        // to null when records are older than X days.
        'message_tracking_content_null_after_days' => 60,

        'schedule' => [
            // Registers package cleanup job in Laravel scheduler.
            'enabled' => true,

            // Run every day at 03:17 server time by default.
            'cron' => '17 3 * * *',

            // Null = default queue.
            'queue' => null,

            'without_overlapping' => true,
            'on_one_server' => false,
        ],
    ],

    'mail' => [
        'default_bulk_mail_class' => BulkMail::class,

        // View used by BulkMail. Override to use your own Blade template.
        'bulk_mail_view' => 'messenger::bulkMail',

        // Callable or null. Resolves the subject line for bulk mails.
        // Signature: fn(MessageReceiverInterface $receiver, Collection $messages): string
        'bulk_mail_subject' => null,

        // Callable or null. Resolves the URL shown in bulk mails.
        // Signature: fn(MessageReceiverInterface $receiver): ?string
        'bulk_mail_url' => null,

        // View used by the package custom message mail action.
        'custom_message_view' => 'messenger::customMessage',
    ],

    'sending' => [
        // Callable or null. When null, only sends in 'production'.
        // Signature: fn(): bool
        'check_should_send' => null,

        // Callable or null. When null, message creation is never prevented.
        // Signature: fn(string $receiverClass, int $receiverId): bool
        'prevent_create_message' => null,
    ],

    'bcc' => [
        // Callable or null. When null, BCC is always added when provided.
        // Signature: fn(): bool
        'check_should_add_bcc' => null,
    ],

    'notifications' => [
        'default_message_footer' => '',
    ],

    'tracking' => [
        // To disable the pixel injection, set this to false.
        'inject_pixel' => false,

        // To disable injecting tracking links, set this to false.
        'track_links' => false,

        // Where should the pingback URL route be?
        'route' => [
            'prefix' => 'email',
            'middleware' => ['api'],
        ],

        // Nova integration for browsing/troubleshooting messages.
        'nova' => [
            // Set to false to disable package Nova integration.
            'enabled' => true,

            // Automatically register the configured Nova resource when Nova is installed.
            'register_resource' => false,

            // Override with your own resource class if needed.
            'resource' => Topoff\Messenger\Nova\Resources\Message::class,

            // Signed preview route used by the Nova action.
            'preview_route' => [
                'prefix' => 'emessenger/nova',
                'middleware' => ['web', 'signed'],
            ],
        ],

        // Preview route used by the package "Send Custom Message" Nova action.
        'custom_preview_route' => [
            'prefix' => 'emessenger/nova',
            'middleware' => ['web', 'signed'],
        ],

        // If we get a link click without a URL, where should we send it to?
        'redirect_missing_links_to' => '/',

        // Determines whether the body of the email is logged in the messages table.
        'log_content' => true,

        // Can be either 'database' or 'filesystem'.
        'log_content_strategy' => 'database',

        // Filesystem disk used when log_content_strategy is filesystem.
        'tracker_filesystem' => null,
        'tracker_filesystem_folder' => 'messenger-tracker',

        // Queue used for tracking jobs. Null uses default queue.
        'tracker_queue' => null,

        // Max size for content when stored in database.
        'content_max_size' => 65535,

        // Optional: restrict SNS notifications to this topic ARN.
        'sns_topic' => null,

        'sns' => [
            // Verify the SNS message signature on the HTTP webhook path via
            // Aws\Sns\MessageValidator. SNS signs every HTTP delivery; enabling
            // this rejects forged tracking events from anyone who learns the
            // public endpoint and topic ARN. Off by default for backwards
            // compatibility. Not used by the SQS transport (IAM-authenticated).
            'verify_signature' => env('MESSENGER_SNS_VERIFY_SIGNATURE', false),
        ],

        // How SES events reach the application:
        //   'sns_http' — SNS pushes notifications to the HTTPS callback route (default).
        //   'sqs'      — SNS fans out to an SQS queue, drained by
        //                `messenger:tracking:sqs-poll` (scheduled automatically).
        // SES cannot target SQS directly, so the SQS transport is SES -> SNS -> SQS
        // and reuses the same topic + provisioning. See messenger.ses_sns.sqs.
        'event_transport' => env('MESSENGER_EVENT_TRANSPORT', 'sns_http'),

        // Vonage SMS delivery receipt (DLR) webhook.
        'vonage_dlr' => [
            'enabled' => false,
        ],
    ],

    'ses_sns' => [
        'aws' => [
            'region' => env('AWS_DEFAULT_REGION', 'eu-central-1'),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'session_token' => env('AWS_SESSION_TOKEN'),
            'profile' => env('AWS_PROFILE'),

            // Optional override. When null, fetched via STS GetCallerIdentity.
            'account_id' => null,
        ],

        // SES v2 configuration sets managed by this package.
        // Each key is a logical name; values specify the AWS resource names.
        // The 'identity' value must reference a key from 'sending.identities' below.
        // Use separate configuration sets to isolate domain reputation per mail purpose
        // (e.g. transactional vs. outreach/marketing).
        //
        // The MessageType model's `ses_configuration_set` field maps to a key here.
        // This determines which identity (and therefore from-address) is used for sending.
        'configuration_sets' => [
            'default' => [
                'configuration_set' => strtolower((string) env('APP_NAME')).'-'.strtolower((string) env('APP_ENV')).'-messenger-tracking',
                'event_destination' => strtolower((string) env('APP_NAME')).'-'.strtolower((string) env('APP_ENV')).'-messenger-sns',
                'identity' => 'default',

                // Optional IMAP inbox key (must reference messenger.imap.inboxes).
                // When set, the imap fetcher will scan that inbox for bounces, complaints,
                // and replies that target messages sent via this configuration set.
                // Set to null to leave this configuration set unmonitored.
                'imap_inbox' => null,
            ],
        ],

        // SNS resources managed by this package.
        'topic_name' => strtolower((string) env('APP_NAME')).'-'.strtolower((string) env('APP_ENV')).'-messenger-ses-events',
        'topic_arn' => null,

        // If null, route('messenger.tracking.sns') is used.
        'callback_endpoint' => null,

        // SQS transport (used when messenger.tracking.event_transport = 'sqs').
        // The package provisions an SQS queue, subscribes it to the SNS topic and
        // drains it via `messenger:tracking:sqs-poll`. SES events therefore flow
        // SES -> SNS -> SQS, which needs no public HTTPS endpoint, survives
        // deploys/downtime, and gets a native dead-letter queue.
        'sqs' => [
            // Logical -> AWS resource names. queue_url, when set, takes precedence
            // over name resolution for the poller.
            'queue_name' => strtolower((string) env('APP_NAME')).'-'.strtolower((string) env('APP_ENV')).'-messenger-ses-events',
            'queue_url' => env('MESSENGER_SQS_QUEUE_URL'),

            // Dead-letter queue. Messages that fail max_receive_count deliveries are
            // moved here instead of being retried forever. Set dlq_name to null to
            // skip DLQ provisioning.
            'dlq_name' => strtolower((string) env('APP_NAME')).'-'.strtolower((string) env('APP_ENV')).'-messenger-ses-events-dlq',
            'max_receive_count' => 5,

            // When true the SNS->SQS subscription strips the SNS envelope and the
            // SES message lands at the top level of the SQS body. The poller handles
            // both shapes; off keeps the body identical to the HTTP webhook.
            'raw_message_delivery' => false,

            // Long-poll + visibility tuning for receiveMessage.
            'wait_time_seconds' => 20,
            'visibility_timeout' => 60,
            'poll_max_messages' => 10,

            // Scheduler: drain the queue on a cron. Each tick runs until the queue is
            // empty or max_run_seconds elapses, with withoutOverlapping + onOneServer.
            'schedule' => [
                'enabled' => true,
                'cron' => '* * * * *',
                'max_run_seconds' => 55,
                'without_overlapping' => true,
                'on_one_server' => true,
            ],
        ],

        // Automation toggle: create + subscribe the SQS queue during setup when the
        // SQS transport is active.
        'create_sqs_subscription_if_missing' => true,

        // Event types bound to the SES event destination.
        'event_types' => ['SEND', 'REJECT', 'BOUNCE', 'COMPLAINT', 'DELIVERY'],

        // Optional SES v2 tenant association for identity/configuration set resources.
        'tenant' => [
            'name' => strtolower((string) env('APP_NAME')).'-'.strtolower((string) env('APP_ENV')).'-tenant',
        ],

        // Automation toggles.
        'create_topic_if_missing' => true,
        'create_https_subscription_if_missing' => true,
        'set_topic_policy' => true,
        'enable_event_destination' => true,

        // SES sending identities. Each identity represents a verified domain in SES
        // that the application is allowed to send from.
        //
        // You can define multiple identities to separate sending purposes (e.g.
        // transactional, outreach, app notifications). Each identity requires:
        //
        //   identity_domain   — The domain verified in SES (e.g. mailer.example.com).
        //                       Every From address must belong to a verified identity domain.
        //                       If you want to send as user@example.com, then example.com
        //                       must be a verified identity — a subdomain like mailer.example.com
        //                       does NOT cover the parent domain.
        //
        //                       Common scenario: you use mailer.example.com for transactional
        //                       mail but also want to send as info@example.com (e.g. for
        //                       Laravel's default mailer or password resets). In that case you
        //                       need a second identity with identity_domain = example.com.
        //
        //   mail_from_domain  — (Optional) Custom MAIL FROM / Return-Path domain for bounces
        //                       and SPF alignment (e.g. bounce.mailer.example.com). Requires
        //                       MX + TXT DNS records. Must be a subdomain of identity_domain.
        //                       If omitted, SES uses its default bounce domain.
        //
        //   mail_from_address — (Optional) The From address used when sending via this identity
        //                       (e.g. noreply@mailer.example.com). The domain part must match
        //                       identity_domain. If omitted, falls back to config('mail.from.address').
        //
        //   reply_to_address  — (Optional) Override Reply-To header. Can be on any domain,
        //                       does not need SES verification.
        //
        //   dmarc             — (Optional) Full DMARC TXT record value for the identity domain.
        //                       Written to `_dmarc.<identity_domain>` by the DNS automation when
        //                       a provider is configured. Defaults to 'v=DMARC1; p=none;' when
        //                       omitted. Set to false to skip DMARC entirely for this identity.
        //
        // Run `php artisan messenger:ses-sns:setup-sending` to provision all identities
        // in SES and get the required DNS records (DKIM, SPF, MX, DMARC).
        //
        // Example with multiple identities:
        //
        //   'default' => [
        //       'identity_domain'   => 'mailer.example.com',
        //       'mail_from_domain'  => 'bounce.mailer.example.com',
        //       'mail_from_address' => 'noreply@mailer.example.com',
        //       'dmarc'             => 'v=DMARC1; p=quarantine; rua=mailto:dmarc@example.com',
        //   ],
        //   'outreach' => [
        //       'identity_domain'   => 'business.example.com',
        //       'mail_from_domain'  => 'bounce.business.example.com',
        //       'mail_from_address' => 'welcome@business.example.com',
        //       'reply_to_address'  => 'info@example.com',
        //   ],
        //   'app' => [
        //       'identity_domain'   => 'example.com',        // parent domain as separate identity
        //       // mail_from_domain and mail_from_address are optional — omit if this
        //       // identity only needs to exist so Laravel's default mailer can send
        //       // from info@example.com (using config('mail.from.address')).
        //   ],
        'sending' => [
            'identities' => [
                'default' => [
                    'identity_domain' => env('AWS_SES_IDENTITY_DOMAIN'),
                    'mail_from_domain' => env('AWS_SES_MAIL_FROM_DOMAIN'),
                    'mail_from_address' => env('MAIL_FROM_ADDRESS'),
                    // 'reply_to_address' => env('...'),
                    // 'dmarc' => env('MESSENGER_DMARC_RECORD', 'v=DMARC1; p=none;'),
                ],
            ],

            'mail_from_behavior_on_mx_failure' => 'USE_DEFAULT_VALUE',

            // DNS automation for SES verification records (DKIM CNAMEs, MAIL FROM MX/TXT, DMARC TXT).
            //
            // Only one provider runs per `setup-sending` invocation. Order of precedence:
            //   1. Infomaniak (when `infomaniak.enabled` is true)
            //   2. Route53 (when `route53.enabled` is true)
            //   3. None — records are printed for manual entry at the DNS provider.

            // Infomaniak DNS automation.
            // Token: create at https://manager.infomaniak.com → API tokens with scope `domain`.
            // The zone identifier passed to the Infomaniak API is the apex domain
            // (e.g. for `mailer.example.com` the zone is `example.com`) — resolved automatically
            // by walking up the labels and probing the API.
            'infomaniak' => [
                'enabled' => env('MESSENGER_INFOMANIAK_DNS_ENABLED', false),
                'token' => env('INFOMANIAK_API_TOKEN'),
                'base_url' => env('INFOMANIAK_API_BASE_URL', 'https://api.infomaniak.com'),
                'auto_create_records' => true,
                'ttl' => 300,
            ],

            // Route53 DNS automation for SES records.
            // Route53 is AWS DNS. Enable only if your domain DNS is hosted in AWS Route53.
            // If DNS is managed elsewhere (e.g. Cloudflare, provider panel), keep disabled
            // and create SES verification/DKIM/MX records manually at your DNS provider.
            'route53' => [
                'enabled' => false,
                'hosted_zone_id' => null, // Optional: if null, lookup by identity domain.
                'auto_create_records' => false,
                'ttl' => 300,
            ],
        ],
    ],

    // IMAP-based bounce / complaint / reply processing.
    //
    // Requires webklex/laravel-imap. Without it the IMAP scheduler is dormant
    // and `messenger:imap:fetch` raises a clear error. Bounce events parsed via
    // IMAP fire the same MessagePermanentBouncedEvent / MessageTransientBouncedEvent
    // as the SNS path. Genuine replies fire MessageReplyReceivedEvent.
    //
    // Note: the IMAP path intentionally does NOT push hard-bounce recipients
    // to the SES suppression list (heuristic parsing must not globally block
    // real recipients). Host applications can subscribe to the bounce events
    // to apply their own domain-specific suppression.
    'imap' => [
        'enabled' => env('MESSENGER_IMAP_ENABLED', false),

        // What to do with an inbound message once we've processed it. Possible values:
        //  - 'move' (move to the matching folder under imap.folders)
        //  - 'seen' (just flag as \Seen)
        //  - 'delete'
        //  - 'noop' (leave untouched)
        'after_process' => [
            'bounce' => 'move',
            'complaint' => 'move',
            'reply' => 'seen',
            'auto_reply' => 'seen',
            'unknown' => 'seen',
        ],

        'folders' => [
            'bounce' => 'INBOX.Bounces',
            'complaint' => 'INBOX.Complaints',
            'reply' => 'INBOX.Processed',
        ],

        // One entry per IMAP account. Reference one of these keys from a
        // ses_sns.configuration_sets[].imap_inbox value.
        'inboxes' => [
            // 'topoffer_info' => [
            //     'host'          => env('MESSENGER_IMAP_INFO_HOST'),
            //     'port'          => (int) env('MESSENGER_IMAP_INFO_PORT', 993),
            //     'encryption'    => env('MESSENGER_IMAP_INFO_ENCRYPTION', 'ssl'),
            //     'validate_cert' => true,
            //     'username'      => env('MESSENGER_IMAP_INFO_USERNAME'),
            //     'password'      => env('MESSENGER_IMAP_INFO_PASSWORD'),
            //     'folder'        => env('MESSENGER_IMAP_INFO_FOLDER', 'INBOX'),
            //
            //     // Per-run safety caps.
            //     'max_messages_per_run' => 200,
            //     'fetch_since_days'     => 14,
            // ],
        ],

        // Scheduler entry, registered by MessengerServiceProvider when imap.enabled.
        'schedule' => [
            'enabled' => true,
            'cron' => '*/10 * * * *',
            'queue' => null,
            'without_overlapping' => true,
            'on_one_server' => true,
        ],
    ],
];
