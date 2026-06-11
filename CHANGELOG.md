# Changelog

All notable changes to `laravel-messenger` will be documented in this file.

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
