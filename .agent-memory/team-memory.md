# Team Memory: laravel-messenger

This file contains deliberate, team-reviewed memory for this project.
It is versioned in this repository and is intended for Claude Code, Codex, and OpenCode.

## Stable facts

- Mail-template + sending package. Two delivery-observation paths exist side by side:
  1. **SNS path** (authoritative for SES events) — `MailTrackingSnsController` → Record*Job → events.
  2. **IMAP path** (optional, additive) — `ProcessImapInboxJob` → `ImapBounceProcessor` → events. Requires `webklex/laravel-imap` (suggested dep).
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
- Scheduler entries are auto-registered when `messenger.imap.enabled` is true — one per inbox.

## Conventions specific to this project

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
