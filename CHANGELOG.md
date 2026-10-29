# Changelog

All notable changes to `laravel-messenger` will be documented in this file.

## 8.11.0 - 2026-10-29

- New notification channel `aws-sms` (AWS End User Messaging SMS, SMS-Voice v2):
  `toAwsSms()` + `routeNotificationFor('aws-sms')`, sender/configuration set from
  `messenger.sms.aws`, `Context.messenger_message_id` for correlation, SDK retries
  off (no idempotency token), never the SDK default credential chain.
- `RecordNotificationSentListener` tracks every channel in
  `messenger.tracking.sms_channels` (default `['vonage', 'aws-sms']`, also when a
  host overrides the whole `tracking` block); a second send keeps the earlier
  provider id in `tracking_meta.previous_message_ids`.
- New endpoint `POST {prefix}/sms-sns` (`messenger.tracking.aws_sms_events`):
  only with an explicit topic ARN, SNS signature always verified (503 without
  the validator package), subscription confirmed only for that topic and a
  failed confirmation answered with 502.
- `RecordAwsSmsEventJob`: final events set `delivered_at`/`failed_at`; first final
  state wins, later ones go to `tracking_meta.late_events`; business events are
  fired at least once (`sms_final_event_pending` marker + safety net
  `messenger:dispatch-pending-sms-events`, scheduled while the endpoint is enabled).
- New `SmsDeliveryFailedEvent` (AWS and Vonage) so e-mail bounce listeners do not
  react to SMS failures; Vonage still also fires `MessageRejectedEvent` first.
- Vonage DLR endpoint: optional shared secret `messenger.tracking.vonage_dlr.token`
  (`?token=`, stripped before the job), DLR lookup also via previous provider ids and
  retried while the send listener has not stored the id yet; first final state wins.

## 9.0.0 - Unreleased

Start of the v9 line (host: top-offerten 4.0, decision E79/E123).

- PHP 8.5 baseline (E92): `"php": "^8.5"`, suite green on 8.5.7; Rector
  PHP-8.5 set applied (#[\Override] on overridden properties,
  strict_types declares, dead null named args removed).
- MessageTypeRepository caches scalar attribute arrays instead of
  serialized Eloquent objects (shared database cache returned
  __PHP_Incomplete_Class in other processes, e.g. parallel E2E ticks);
  models are rehydrated via newFromBuilder, cache key format bumped to
  `f2` so stale object entries are never read.

- Send-time payload resolver (`messenger.rendering.resolve_params`,
  callable(Message): array): enrich params right before rendering — e.g.
  short-lived signed links whose TTL must start at send time. Resolved
  params are persisted with the message.
- Per-channel send rate guard (`messenger.rate_limit.channels.<channel>`):
  `min_interval_seconds`, `per_receiver_per_day`, `per_channel_per_day` —
  deferred messages are rescheduled, never errored (SMS-pumping defence).
  Wired into both handler base classes via `shouldBeSentNow()`; call
  `parent::shouldBeSentNow()` when overriding.
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
