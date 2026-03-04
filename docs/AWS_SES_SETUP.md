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
| Outreach | `outreach.example.com` | Marketing, newsletters, campaigns |

Bounces and spam complaints on `outreach.example.com` do not affect deliverability for `example.com`.

Each identity needs its own DKIM verification and MAIL FROM subdomain:

```
# Transactional
AWS_SES_IDENTITY_DOMAIN=example.com
AWS_SES_MAIL_FROM_DOMAIN=mail.example.com

# Outreach
AWS_SES_OUTREACH_IDENTITY_DOMAIN=outreach.example.com
AWS_SES_OUTREACH_MAIL_FROM_DOMAIN=mail.outreach.example.com
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
4. **Events processed** via SNS → SQS → Laravel

✅ Done.

---

## Implementation Notes

- Use SES tags to track which tenant sent which email
- Configuration sets handle technical event routing
- Identities only verify sender authorization
- All tenant-specific logic should be in your application, not in AWS infrastructure
- **The setup automatically assigns the Configuration Set to the Identity** as the default, so all emails sent from that identity will use the specified configuration set for event tracking
