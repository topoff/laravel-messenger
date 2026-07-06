# Changelog

All notable changes to `laravel-messenger` will be documented in this file.

## 9.0.0 - Unreleased

Start of the v9 line (host: top-offerten 4.0, decision E79/E123).

- Status-driven fallback engine (migration 0020): a message type can name a
  `fallback_message_type_id` (usually another channel) plus
  `fallback_after_minutes` — sent messages without delivery confirmation
  after the timeout, or with a bounce, get a follow-up message of the
  fallback type (same receiver/context/params/locale, linked via
  `messages.fallback_of_message_id`). Guards: one follow-up per message,
  chain never revisits a type and is capped, marketing fallbacks respect
  the consent guard. `FallbackEngine`, `RunFallbackEngineJob`, schedule via
  `messenger.fallback.schedule.*` (disabled by default),
  `MessageFallbackCreatedEvent`.
- RFC 8058 List-Unsubscribe (marketing only): `List-Unsubscribe` with a
  signed one-click URL + `List-Unsubscribe-Post` headers, injected by a
  MessageSending listener; signature-protected GET|POST endpoint
  `messenger.unsubscribe` writes a `marketing` opt-out via ConsentService.
  Transactional mails are never touched.
- Preference / consent guard (migration 0019, `message_opt_outs`): central
  guard in `MessageService` blocks creation of marketing-class messages for
  opted-out receivers and deletes already-created ones at send time (both
  handler base classes) — an opt-out between creation and send still wins.
  Scopes: `marketing` (all), `channel:<channel>`, `type:<notification_class>`.
  `ConsentService` API: `optOut()`, `optIn()`, `isOptedOut()`. Transactional
  types never consult the opt-outs (password resets always go through).
- `message_types.message_class` (`transactional`|`marketing`, default transactional,
  migration 0018): drives the upcoming consent guard (marketing requires
  consent) and RFC 8058 List-Unsubscribe headers (marketing only). Constants
  `MessageType::CLASS_TRANSACTIONAL`/`CLASS_MARKETING`; Filament/Nova selects.
  `channel` columns widened to 20 chars for upcoming channels (whatsapp,
  expo_push); SQLite skips the widening (dynamic typing, see migration 0017).
- PHP requirement raised to `^8.5` (E106 runtime baseline). The v8 line
  remains available for hosts on PHP 8.4.
- Planned for v9 (work packages): `message_class` (transactional|marketing),
  status-driven fallback engine (follow-up message on another channel when
  no delivery confirmation after timeout), preference/consent tables with a
  central guard in MessageService, RFC 8058 List-Unsubscribe, send-time
  payload resolver hook (short-lived links), SMS rate guard.

## 8.4.0 - Support string/UUID morph IDs, backwards compatible

Added support for host applications whose receiver / sender / messagable /
company models use UUID (string, 36-char) primary keys, alongside the existing
bigint installations. Fully backwards compatible — int IDs remain valid
everywhere and require no data or code change.

- New migration `0017_support_string_morph_ids` widens `messages.receiver_id`,
  `sender_id`, `messagable_id` and `company_id` from `unsignedBigInteger` to
  `string(36)`. Existing bigint values convert losslessly to strings; all
  indexes are preserved. Runs on MySQL (native `change()`) and PostgreSQL (raw
  `ALTER ... USING col::varchar`); SQLite is a no-op (dynamic typing already
  stores both).
- `MessageService` setters (`setSender`, `setReceiver`, `setMessagable`,
  `setCompanyId`) now accept `int|string|null`.
- `Message` model: morph-id docblocks widened to `int|string|null`; the
  `integer` casts on `company_id` / `messagable_id` removed. MorphTo relations
  are unchanged and work with string keys.
- Nova / Filament message actions and resources no longer assume numeric morph
  ids (no `(int)` coercion, fields loosened from numeric).
