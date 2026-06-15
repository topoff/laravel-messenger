# AWS SES Setup Guide

This guide explains the key concepts for setting up AWS SES for multi-tenant applications.

## Core Concepts

### 🔹 Identity

**What is allowed to send?**

- Verified domain or email address
- A domain identity allows all senders from that domain
- You need exactly **one identity per domain**
- You can configure **multiple identities** to separate email streams (e.g. transactional vs. outreach) and protect domain reputation

**👉 Controls the sender**

#### Multiple Identities for Reputation Isolation

Use separate (sub)domains for different email streams to isolate sender reputation:

| Stream | Identity | Purpose |
|---|---|---|
| Transactional | `example.com` | Password resets, confirmations, invoices |
| Outreach | `business.example.com` | Cold outreach, partner acquisition |

Bounces and spam complaints on `business.example.com` do not affect deliverability for `example.com`.

Each identity needs its own DKIM verification and MAIL FROM subdomain:

```
# Transactional
AWS_SES_IDENTITY_DOMAIN=example.com
AWS_SES_MAIL_FROM_DOMAIN=mail.example.com

# Outreach (separate identity + custom From + Reply-To)
AWS_SES_OUTREACH_IDENTITY_DOMAIN=business.example.com
AWS_SES_OUTREACH_MAIL_FROM_DOMAIN=bounce.business.example.com
AWS_SES_OUTREACH_FROM_ADDRESS=welcome@business.example.com
AWS_SES_OUTREACH_REPLY_TO_ADDRESS=info@example.com
```

The `messenger:ses-sns:setup-all` command provisions all configured identities automatically.

---

### 🔹 Configuration Set

**What happens with the email?**

- Defines event forwarding (Bounce, Complaint, Delivery, etc.)
- Connected to SNS / SQS
- Typically 1–3 configuration sets:
  - `transactional`
  - `system`
  - `marketing`

**👉 Controls event processing**

---

### 🔹 Tenants

**Who does the email belong to?**

- ❌ **Don't** separate via Identity
- ❌ **Don't** separate via Configuration Sets
- ✅ **Do** separate via SES Tags (e.g., `tenant_id=42`)

**👉 Controls business assignment**

---

## 🎯 Standard Setup for SaaS

1. **1+ Domain Identities** — main domain for transactional, subdomain(s) for outreach/marketing
2. **1–3 Configuration Sets** (transactional, system, marketing)
3. **Tenant per Tag** sent with each email
4. **Events processed** via SNS → HTTPS webhook, or SNS → SQS → Laravel

✅ Done.

---

## 🔀 Event Transport: SNS HTTP vs. SQS

SES delivers events to an SNS topic. `messenger.tracking.event_transport` selects how they reach the app:

| | `sns_http` (default) | `sqs` |
|---|---|---|
| Path | SES → SNS → HTTPS `POST` | SES → SNS → SQS → poller |
| Public endpoint | **Required** | Not needed |
| Durability across deploys/downtime | SNS retry policy then dropped | Buffered up to 14 days |
| Dead-letter queue | — | Native (redrive) |
| Forgery protection | Optional SNS signature verification | IAM / queue policy |
| Extra moving part | None | A scheduled poller |

SES has no SQS event destination, so the SQS transport is **SES → SNS → SQS** — it reuses the same SNS topic and only adds an SQS queue subscribed to it.

### Enabling the SQS transport

```dotenv
MESSENGER_EVENT_TRANSPORT=sqs
```

Then provision and run:

```bash
php artisan messenger:ses-sns:setup-tracking   # creates the queue + DLQ, subscribes it to the topic
php artisan messenger:ses-sns:check-tracking    # validates queue / subscription / DLQ
```

The queue is drained automatically by the scheduled `messenger:tracking:sqs-poll` command (one per-minute background run, `withoutOverlapping` + `onOneServer`). Tune the queue/DLQ names and polling under `messenger.ses_sns.sqs.*`.

### Hardening the HTTP transport

When staying on `sns_http`, enable signature verification so forged events are rejected:

```dotenv
MESSENGER_SNS_VERIFY_SIGNATURE=true
```

```bash
composer require aws/aws-php-sns-message-validator
```

---

## Implementation Notes

- Use SES tags to track which tenant sent which email
- Configuration sets handle technical event routing
- Identities only verify sender authorization
- All tenant-specific logic should be in your application, not in AWS infrastructure
- **The setup automatically assigns the Configuration Set to the Identity** as the default, so all emails sent from that identity will use the specified configuration set for event tracking
