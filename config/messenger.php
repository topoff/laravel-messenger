<?php

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
        // This can be important for the domain reputation management.
        'configuration_sets' => [
            'default' => [
                'configuration_set' => env('APP_NAME').'-'.env('APP_ENV').'-messenger-tracking',
                'event_destination' => env('APP_NAME').'-'.env('APP_ENV').'-messenger-sns',
                'identity' => 'default',
            ],
        ],

        // SNS resources managed by this package.
        'topic_name' => env('APP_NAME').'-'.env('APP_ENV').'-messenger-ses-events',
        'topic_arn' => null,

        // If null, route('messenger.tracking.sns') is used.
        'callback_endpoint' => null,

        // Event types bound to the SES event destination.
        'event_types' => ['SEND', 'REJECT', 'BOUNCE', 'COMPLAINT', 'DELIVERY'],

        // Optional SES v2 tenant association for identity/configuration set resources.
        'tenant' => [
            'name' => env('APP_NAME').'-'.env('APP_ENV').'-tenant',
        ],

        // Automation toggles.
        'create_topic_if_missing' => true,
        'create_https_subscription_if_missing' => true,
        'set_topic_policy' => true,
        'enable_event_destination' => true,

        'sending' => [
            'identities' => [
                'default' => [
                    'identity_domain' => env('AWS_SES_IDENTITY_DOMAIN'),
                    'mail_from_domain' => env('AWS_SES_MAIL_FROM_DOMAIN'),
                    'mail_from_address' => env('MAIL_FROM_ADDRESS'),
                    // 'reply_to_address' => env('...'),  // Optional: override Reply-To for this identity.
                ],
            ],

            'mail_from_behavior_on_mx_failure' => 'USE_DEFAULT_VALUE',

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
];
