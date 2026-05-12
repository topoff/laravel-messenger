# Message Lifecycle Scenarios

Complete overview of all scenarios a message can go through, and which database fields are set at each stage.

## Legend

| Field | Description |
|---|---|
| `reserved_at` | Message is currently being processed by a worker |
| `error_at` | Transient error occurred, message will be retried with exponential backoff |
| `failed_at` | Permanent failure, message will **not** be retried |
| `sent_at` | Message was successfully handed off to the mail transport (SMTP/SES) |
| `deleted_at` | Message was soft-deleted |
| `attempts` | Number of send attempts so far |
| `error_code` | SMTP error code from the last failure |
| `error_message` | Error description from the last failure |
| `tracking_meta` | JSON field enriched by SES/SNS callbacks after sending |

---

## Phase 1: Sending (MainMailHandler / MainBulkMailHandler)

These scenarios happen when `SendMessageJob` picks up a message and attempts to send it.

### 1. Successful Send

The message is sent successfully via `Mail::send()`.

| Field | Value |
|---|---|
| `reserved_at` | set on pickup, then remains (not cleared) |
| `sent_at` | `now()` |
| `attempts` | incremented by 1 |

Only `sent_at` is actively written. `error_at` and `failed_at` remain at their previous values (typically `null` for a first attempt; filtered out by the retry query filters for retried messages).

**Code path:** `MainMailHandler::send()` -> `setMessageSent()`

---

### 2. Successful Send (Non-Production Environment)

When `shouldBeSentInThisEnvironment()` returns `false`, the message is marked as sent without actually sending.

| Field | Value |
|---|---|
| `sent_at` | `now()` |
| `attempts` | incremented by 1 |

**Same DB result as scenario 1** — the message is considered "sent" even though no email was dispatched.

---

### 3. Transient SMTP Error (retryable)

An SMTP error occurs that is **not** in the permanent failure list. Examples: timeout, temporary DNS failure, rate limiting (codes like 421, 450, 452, etc.).

| Field | Value |
|---|---|
| `reserved_at` | `null` (cleared) |
| `error_at` | `now()` |
| `failed_at` | `null` |
| `attempts` | incremented by 1 |
| `error_code` | SMTP code (e.g. `421`) |
| `error_message` | Error message (truncated to 245 chars) |

**Retry behavior:** Message will be retried by `SendMessageJob(isRetryCall: true)` with exponential backoff:

| Attempt | Backoff |
|---|---|
| 1 | 15 minutes |
| 2 | 30 minutes |
| 3 | 1 hour |
| 4 | 2 hours |
| 5 | 4 hours |
| 6 | 8 hours |
| 7 | 16 hours |
| 8+ | 16 hours (capped) |

Retries stop when `attempts >= message_type.max_retry_attempts` (default: 10) or when `created_at` exceeds `error_stop_send_minutes` (default: 3 days).

**Code path:** `MainMailHandler::send()` catch block -> transient branch -> `onError()`

---

### 4. Permanent SMTP Error (not retryable)

An SMTP error occurs with a code indicating the mailbox or domain is permanently unreachable.

| Code | Meaning |
|---|---|
| 550 | Mailbox doesn't exist / unroutable address |
| 553 | Mailbox name not allowed |
| 521 | Host does not accept mail |
| 556 | Domain does not accept mail |

| Field | Value |
|---|---|
| `reserved_at` | `null` (cleared) |
| `error_at` | `null` (not set) |
| `failed_at` | `now()` |
| `attempts` | incremented by 1 |
| `error_code` | SMTP code (e.g. `550`) |
| `error_message` | Error message (truncated to 245 chars) |

**No retry.** Message is permanently marked as failed. `onError()` is **not** called.

**Code path:** `MainMailHandler::send()` catch block -> `isPermanentFailure()` returns `true`

---

### 5. SES MessageRejected Error (not retryable)

SES rejects the message before even attempting delivery. The exception message contains "MessageRejected". This can happen for suppressed addresses, sandbox restrictions, or policy violations.

| Field | Value |
|---|---|
| `reserved_at` | `null` (cleared) |
| `failed_at` | `now()` |
| `error_code` | error code from exception |
| `error_message` | contains "MessageRejected" |

**No retry.** Same handling as scenario 4.

**Code path:** `MainMailHandler::send()` catch block -> `isPermanentFailure()` matches `MessageRejected`

---

### 6. Receiver Missing (soft-deleted user)

The receiver model returns `null` (e.g. user was deleted between message creation and sending).

| Field | Value |
|---|---|
| `reserved_at` | `null` (cleared) |
| `error_at` | `now()` |
| `deleted_at` | `now()` (soft-deleted) |
| `attempts` | incremented by 1 |
| `error_code` | `1000` (`ReceiverMissingException::USER_DELETED`) |
| `error_message` | "Message could not be sent because the receiver is missing, presumably trashed." |

**No retry.** Message is soft-deleted and excluded from all queries.

**Code path:** `MainMailHandler::send()` catch block -> `ReceiverMissingException`

---

### 7. Messagable Missing (abort & delete)

The messagable model (e.g. the order, ticket, etc. the message is about) no longer exists.

| Field | Value |
|---|---|
| `reserved_at` | set (from pickup) |
| `deleted_at` | `now()` (soft-deleted) |
| `error_message` | "Message has been deleted, because the Messagable itself is missing." |

**No retry.** Message is soft-deleted.

**Code path:** `MainMailHandler::send()` -> `abortAndDeleteWhen()` returns `true`

---

### 8. Invalid Receiver Email (abort & delete)

The receiver exists but `getEmailIsValid()` returns `false` (e.g. `email_invalid_at` is set on the receiver).

| Field | Value |
|---|---|
| `reserved_at` | set (from pickup) |
| `deleted_at` | `now()` (soft-deleted) |
| `error_message` | "Message has been deleted, because the receiver is trashed or the receiver email is invalid..." |

**No retry.** Message is soft-deleted.

**Code path:** `MainMailHandler::send()` -> `abortAndDeleteWhen()` returns `true`

---

### 9. shouldBeSentNow() Returns False

A child MailHandler overrides `shouldBeSentNow()` and returns `false`. The message is saved with the incremented attempt count but otherwise unchanged.

| Field | Value |
|---|---|
| `reserved_at` | set (from pickup, remains) |
| `attempts` | incremented by 1 |

Message stays in current state. Will be picked up again on next job run if `reserved_at` backoff has elapsed.

---

### 10. Invalid/Missing single_handler

The `message_type.single_handler` class doesn't exist or is null.

| Field | Value |
|---|---|
| `error_at` | `now()` |

**Retry possible** (treated as transient error, subject to exponential backoff).

**Code path:** `SendMessageJob::callMailHandlerWithSingleMessage()`

---

### 11. Bulk Mail — Successful Send

Multiple messages for the same receiver are grouped and sent as one bulk email.

| Field | Value (all messages in group) |
|---|---|
| `reserved_at` | set on pickup (remains) |
| `sent_at` | `now()` |
| `error_at` | `null` (cleared) |
| `failed_at` | `null` (cleared) |

**Code path:** `MainBulkMailHandler::send()` -> `setMessagesToSent()`

---

### 12. Bulk Mail — Send Failure

The bulk email fails to send. All messages in the group are marked as error.

| Field | Value (all messages in group) |
|---|---|
| `reserved_at` | `null` (cleared) |
| `error_at` | `now()` |

Exception is re-thrown. **Note:** Bulk mail errors do NOT set `failed_at` — they are always treated as transient.

**Code path:** `MainBulkMailHandler::send()` catch block -> `setMessagesToError()`

---

### 13. Bulk Mail — Send Succeeds but DB Update Fails

The bulk email was sent successfully, but the subsequent `setMessagesToSent()` DB update throws an exception. The email **was delivered** but the messages are not marked as sent.

| Field | Value (all messages in group) |
|---|---|
| `reserved_at` | set (from pickup, remains) |
| `sent_at` | `null` (update failed) |

A `critical` log is written with the affected message IDs. The exception is re-thrown. This is a dangerous state: the emails were sent, but on the next retry run they could be sent again.

**Code path:** `MainBulkMailHandler::send()` -> `setMessagesToSent()` throws

---

### 14. Bulk Mail — Receiver Deleted (indirect messages)

When grouping indirect messages, if the receiver no longer exists, all messages in the group are soft-deleted.

| Field | Value (all messages in group) |
|---|---|
| `deleted_at` | `now()` |

**Code path:** `SendMessageJob::sendIndirectMessages()` -> receiver check

---

## Phase 2: SES/SNS Callbacks (after sending)

These events arrive via SNS webhook **after** the message was already accepted by SES (i.e. `sent_at` is already set). Most callbacks only update `tracking_meta` plus a dedicated status timestamp (`delivered_at` / `bounced_at`). The exception is **Reject**, which also sets `failed_at`.

> **Note on accept-then-bounce:** SES can emit a `Delivery` event (recipient MTA answered `250 OK`) followed seconds later by an asynchronous `Bounce` event (recipient MTA sent back a DSN after acceptance). In that case both `delivered_at` and `bounced_at` are set and `tracking_meta.success` stays `true` (a previous `success: true` is never overwritten by a later bounce). Read the two timestamp columns to detect this pattern.

### 15. SES Delivery Confirmation

SES confirms the recipient mail server accepted the email at the SMTP layer.

| Field | Value |
|---|---|
| `delivered_at` | delivery timestamp (column) |
| `tracking_meta.success` | `true` |
| `tracking_meta.delivered_at` | delivery timestamp |
| `tracking_meta.smtpResponse` | SMTP response from recipient server |
| `tracking_meta.sns_message_delivery` | full SNS payload |
| `tracking_meta.ses_tags` | extracted SES message tags (if present) |

**Status fields:** `sent_at` remains set from Phase 1. `delivered_at` is set.

**Event dispatched:** `MessageDeliveredEvent`

**Code path:** `RecordDeliveryJob::handle()`

---

### 16. SES Permanent Bounce

The recipient's mail server permanently rejected the email (e.g. mailbox doesn't exist).

| Field | Value |
|---|---|
| `bounced_at` | bounce timestamp (column) |
| `tracking_meta.success` | `false` (only if no prior delivery; never overwrites `true`) |
| `tracking_meta.failures` | array of bounced recipients with diagnostic codes |
| `tracking_meta.sns_message_bounce` | full SNS payload |
| `tracking_meta.ses_tags` | extracted SES message tags (if present) |

**Status fields:** `sent_at` remains set. `failed_at` is **not** set. `bounced_at` is set.

**Event dispatched:** `MessagePermanentBouncedEvent` (per recipient)

> **Gap:** The application should listen for `MessagePermanentBouncedEvent` to take action (e.g. mark receiver email as invalid). The package itself does not update `failed_at` or the receiver.

**Code path:** `RecordBounceJob::handle()` -> bounceType === 'Permanent'

---

### 17. SES Transient Bounce

A temporary delivery failure (e.g. mailbox full, server temporarily unavailable) — or an **asynchronous DSN** from the recipient mail server after a successful SMTP-level acceptance (accept-then-bounce). With recipient-side content filters, this is the common case where `delivered_at` and `bounced_at` are both set.

| Field | Value |
|---|---|
| `bounced_at` | bounce timestamp (column) |
| `tracking_meta.success` | `false` only when no prior delivery exists — leaves a prior `true` untouched |
| `tracking_meta.failures` | array of bounced recipients |
| `tracking_meta.sns_message_bounce` | full SNS payload |
| `tracking_meta.ses_tags` | extracted SES message tags (if present) |

**Status fields:** `bounced_at` is set. `delivered_at` may also be set (accept-then-bounce).

**Event dispatched:** `MessageTransientBouncedEvent` (per recipient)

**Code path:** `RecordBounceJob::handle()` -> bounceType !== 'Permanent'

---

### 18. SES Complaint (Spam Report)

The recipient marked the email as spam.

| Field | Value |
|---|---|
| `tracking_meta.success` | `false` |
| `tracking_meta.complaint` | `true` |
| `tracking_meta.complaint_time` | complaint timestamp |
| `tracking_meta.complaint_type` | feedback type (e.g. "abuse") |
| `tracking_meta.sns_message_complaint` | full SNS payload |
| `tracking_meta.ses_tags` | extracted SES message tags (if present) |

**Status fields unchanged.**

**Event dispatched:** `MessageComplaintEvent` (per recipient)

> **Gap:** The application should listen for `MessageComplaintEvent` to take action (e.g. unsubscribe the user). The package itself does not update any status fields.

**Code path:** `RecordComplaintJob::handle()`

---

### 19. SES Reject Event

SES rejected the message after accepting it (e.g. virus detected in attachment, content policy violation).

| Field | Value |
|---|---|
| `failed_at` | `now()` |
| `tracking_meta.success` | `false` |
| `tracking_meta.rejected` | `true` |
| `tracking_meta.reject_reason` | reason string from SES (e.g. "VIRUS") |
| `tracking_meta.sns_message_reject` | full SNS payload |
| `tracking_meta.ses_tags` | extracted SES message tags (if present) |

**Event dispatched:** `MessageRejectedEvent`

**Code path:** `RecordRejectJob::handle()`

---

## Phase 3: Tracking (after delivery)

### 20. Email Opened (Tracking Pixel)

The recipient's email client loads the tracking pixel.

| Field | Value |
|---|---|
| `tracking_opens` | incremented by 1 |
| `tracking_opened_at` | `now()` (first open only) |

---

### 21. Link Clicked (Tracking Link)

The recipient clicks a tracked link in the email.

| Field | Value |
|---|---|
| `tracking_clicks` | incremented by 1 |
| `tracking_clicked_at` | `now()` (first click only) |

---

## Phase 4: Manual Actions (Nova / Resend)

### 22. Resend as New Message

A message is resent via Nova action or `MessageResender`. A new message is created as a copy.

**Original message:** Soft-deleted if it had `error_at` or `failed_at` set (to prevent duplicate sends from retry).

**New message:**

| Field | Value |
|---|---|
| `error_at` | `null` |
| `failed_at` | `null` |
| `sent_at` | `null` |
| `reserved_at` | `null` |
| `scheduled_at` | `null` |
| `attempts` | `0` |
| `tracking_meta` | contains `resent_from_message_id` |

The new message is dispatched via `ResendMessageJob` which calls `MainMailHandler::send()` — it then follows the same scenarios as Phase 1.

**Code path:** `ResendAsNewMessageAction` -> `MessageResender::resend()`

---

## Summary: Status Field Decision Tree

```
Message picked up by SendMessageJob
    |
    +-- reserved_at = now()
    |
    +-- Receiver missing?
    |       YES -> error_at, deleted_at, soft-deleted
    |
    +-- Messagable missing or email invalid?
    |       YES -> deleted_at, soft-deleted
    |
    +-- Mail::send() succeeds?
    |       YES -> sent_at = now()
    |               |
    |               +-- [SNS] Delivery   -> tracking_meta.success = true
    |               +-- [SNS] Bounce     -> tracking_meta.success = false
    |               +-- [SNS] Complaint  -> tracking_meta.complaint = true
    |               +-- [SNS] Reject     -> failed_at = now()
    |
    +-- Mail::send() fails
            |
            +-- isPermanentFailure (550, 553, 521, 556, MessageRejected)?
            |       YES -> failed_at = now()  (no retry)
            |
            +-- Transient error (all others incl. 421, 450, 451, ...)?
                    YES -> error_at = now()  (retry with exponential backoff)
```
