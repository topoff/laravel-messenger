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
- **SES Easy-DKIM CNAME target is region-dependent and the two forms are mutually exclusive** (AWS publishes the key at exactly one address): older regions (e.g. `eu-central-1`) use the global `<token>.dkim.amazonses.com`, newer regions (e.g. `eu-central-2`) use the regional `<token>.dkim.<region>.amazonses.com`. AWS confirms this ("Not all AWS Regions use the default SES DKIM domain") and `GetEmailIdentity` returns only the DKIM *tokens*, never the literal CNAME — so the target must be built, and any hard-coded suffix is correct for only one region. This hard-code ping-ponged twice (`443f5f2` regional → `c2506b3` global), each breaking the other region. Fixed in **v8.8.1**: `SesSendingSetupService::dkimSigningDomain()` probes DNS for where AWS actually serves the key (regional first, global fallback, cached per region) with a `messenger.ses_sns.aws.dkim_domain` (`MESSENGER_SES_DKIM_DOMAIN`) override; probe is injectable via `setDkimEndpointResolver()` for tests. It feeds `buildDnsRecords()` (which **writes** records via the Infomaniak/Route53 DNS automation), `check()`, and the dashboard view consistently — so before v8.8.1, `messenger:ses-sns:setup-sending` with DNS automation on would overwrite correct regional records with broken global ones. `setup-sending` never regenerates DKIM tokens (`getEmailIdentity` first, `createEmailIdentity` only when missing). Diagnose verification state with `messenger:ses-sns:check-sending` (`Pending` ≠ `Failed`; SES keeps polling) and cross-check reality with `dig +short <token>.dkim.<region>.amazonses.com TXT`.

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
