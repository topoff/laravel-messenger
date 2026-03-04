# laravel-messenger

[![Latest Version on Packagist](https://img.shields.io/packagist/v/topoff/laravel-messenger.svg?style=flat-square)](https://packagist.org/packages/topoff/laravel-messenger)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/topoff/laravel-messenger/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/topoff/laravel-messenger/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/topoff/laravel-messenger/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/topoff/laravel-messenger/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/topoff/laravel-messenger.svg?style=flat-square)](https://packagist.org/packages/topoff/laravel-messenger)

This package provides a comprehensive solution for managing mail templates and mail sending in Laravel applications.

## Installation

You can install the package via composer:

```bash
composer require topoff/laravel-messenger
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="messenger-config"
```

## Usage

Documentation coming soon.

### SES/SNS Auto Setup (SES v2 Configuration Sets)

The package can provision SES/SNS tracking resources via AWS API:

- SES Configuration Set
- SES Event Destination (SNS)
- SNS Topic policy for SES publish
- SNS HTTPS subscription to `messenger.tracking.sns`

Enable it in config:

```php
'ses_sns' => [
    'enabled' => true,
],
```

Then run:

```bash
php artisan messenger:ses-sns:setup-tracking
php artisan messenger:ses-sns:check-tracking
php artisan messenger:ses-sns:setup-sending
php artisan messenger:ses-sns:check-sending
php artisan messenger:ses-sns:teardown --force
```

In Nova (`Message Types` resource), use action `Setup SES/SNS Tracking` to run setup and open the status/check page.

### BCC Recipient Filtering

When BCC is added to an email (e.g. via `AddBccToEmailsListener`), both the TO and BCC recipients share the same SES message ID. SNS event notifications (delivery, bounce, complaint) are matched to `Message` records by `tracking_message_id`, so without filtering, events for the BCC recipient would corrupt the original recipient's tracking data (e.g. a BCC bounce setting `success: false` on the original message).

The SNS event jobs (`RecordDeliveryJob`, `RecordBounceJob`, `RecordComplaintJob`) guard against this by comparing the event's recipient email(s) against the message's `tracking_recipient_email`. If they don't match, the event is skipped for that message. This comparison is case-insensitive and null-safe — messages without a `tracking_recipient_email` process all events as before.

`RecordRejectJob` is unaffected since reject notifications carry no per-recipient data.

### Nova Integration

If Laravel Nova is installed, the package can auto-register a tracked messages resource with preview and resend actions.

Configuration keys:

- `messenger.tracking.nova.enabled`
- `messenger.tracking.nova.register_resource`
- `messenger.tracking.nova.resource`
- `messenger.tracking.nova.preview_route`

The preview action uses a temporary signed URL and the package route `messenger.tracking.nova.preview`.

## Development

### Code Quality Tools

This package uses several tools to maintain code quality:

#### Laravel Pint (Code Formatting)

Format code according to Laravel standards:

```bash
composer format
```

#### Rector (Automated Refactoring)

Preview potential code improvements:

```bash
composer rector-dry
```

Apply automated refactorings:

```bash
composer rector
```

#### PHPStan (Static Analysis)

Run static analysis:

```bash
composer analyse
```

#### Run All Quality Checks

```bash
composer lint
```

This runs both Pint and PHPStan.

### Testing

```bash
composer test
```

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
