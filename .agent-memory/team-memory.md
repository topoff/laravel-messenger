# Team Memory: laravel-messenger

This file contains deliberate, team-reviewed memory for this project.
It is versioned in this repository and is intended for Claude Code, Codex, and OpenCode.

## Stable facts

- Mail-template + sending package. Two delivery-observation paths exist side by side:
  1. **SNS path** (authoritative for SES events) — SES → SNS, then either the HTTP webhook (`MailTrackingSnsController`) or the SQS poller (`SqsTrackingPoller`) → Record*Job → events. Both delegate to one transport-agnostic `SnsNotificationProcessor`.
  2. **IMAP path** (optional, additive) — `ProcessImapInboxJob` → `ImapBounceProcessor` → events. Requires `webklex/laravel-imap` (suggested dep).
- **SES event transport is selectable** via `messenger.tracking.event_transport` (`sns_http` default | `sqs`). SES can't target SQS directly, so the SQS transport is SES → SNS → SQS reusing the same topic; `SesSnsSetupService` provisions the queue + DLQ + queue policy + subscription and `messenger:tracking:sqs-poll` (auto-scheduled) drains it. SQS needs no public endpoint, survives deploys, and has a native DLQ.
- **HTTP webhook signature verification** is opt-in (`messenger.tracking.sns.verify_signature`, off by default) via `Aws\Sns\MessageValidator` — needs the suggested `aws/aws-php-sns-message-validator` dep; degrades to allow when absent. Not needed for the SQS transport.
- Both paths fire the same `MessagePermanentBouncedEvent` / `MessageTransientBouncedEvent` / `MessageComplaintEvent`. The IMAP path additionally fires `MessageReplyReceivedEvent` for genuine human replies.
- `MailTracker::injectCorrelationId()` stamps every outgoing email with `X-Topoff-Message-Id` and an RFC 5322 `Message-ID` that embeds the UUID — this is what the IMAP matcher uses for high-confidence lookup.

## Architecture pointers

- General architecture, sending flow, SNS events → `docs/architecture.md`
- IMAP bounce/complaint/reply processing → `docs/imap-bounce-handling.md`
- Message lifecycle scenarios → `docs/message-lifecycle-scenarios.md`

## Commands and workflows

- Run tests: `composer test` (Pest 4.0)
- Static analysis + style: `composer clean` (Rector + Pint + PHPStan)
- IMAP sweep: `php artisan messenger:imap:fetch [inboxKey] [--dry-run] [--limit=N]`
- SQS drain: `php artisan messenger:tracking:sqs-poll [--once] [--max-messages=N] [--max-time=S]` (auto-scheduled when `event_transport=sqs`).
- Scheduler entries are auto-registered when `messenger.imap.enabled` is true (one per inbox) and when `event_transport=sqs` (one sqs-poll drain).

## Conventions specific to this project

- **Every commit gets a SemVer git tag** (`vX.Y.Z`). Composer pulls this package by tag from GitHub, so an untagged commit is invisible to host apps. Per commit: `patch` for bug fixes, `minor` for new features, `major` for breaking changes. Workflow per commit: (1) commit, (2) `git tag vX.Y.Z <sha>` (next SemVer step from the latest existing tag — check with `git tag --sort=-creatordate | head -1`), (3) `git push origin master && git push origin vX.Y.Z`. Don't batch multiple commits under one tag — each commit ships its own version so the changelog stays one-to-one with releases.
- Inbox ↔ configuration-set link is **explicit**: `ses_sns.configuration_sets[<key>].imap_inbox` references `imap.inboxes[<key>]`. Multiple configuration sets may share one inbox.
- **The IMAP path must never write to the SES suppression list.** IMAP DSNs are heuristically parsed; misclassification must not globally block real recipients. Host applications add their own suppression by subscribing to `MessagePermanentBouncedEvent`.
- New PHP files in `src/` should use `declare(strict_types=1);` (most files already do).
- Always use the Blade block form `@php ... @endphp` — inline `@php()` breaks compiled views in this package.

## Do not store here

- Personal preferences.
- Temporary debugging notes.
- Secrets, credentials, API keys, tokens, or private customer data.
- Facts that belong only to another project.
- Long-form agent documentation that belongs in `docs/`.
