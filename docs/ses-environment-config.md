# SES Environment Configuration

Environment-specific `.env` values for AWS SES identity and MAIL FROM domains.

## Domain Strategy

| Stream | Purpose | Domain pattern |
|---|---|---|
| **Transactional** | Password resets, confirmations, invoices | `top-offerten.ch` (main domain) |
| **Outreach** | Marketing, newsletters, campaigns | `outreach.top-offerten.ch` (subdomain) |

Separating outreach into a subdomain protects the main domain's sender reputation. Bounces and spam complaints on `outreach.top-offerten.ch` do not affect deliverability for `top-offerten.ch`.

## Production

```env
AWS_DEFAULT_REGION_TOPUMZUGDE=eu-central-2
AWS_SES_IDENTITY_DOMAIN=top-offerten.ch
AWS_SES_MAIL_FROM_DOMAIN=mail.top-offerten.ch
AWS_SES_OUTREACH_IDENTITY_DOMAIN=outreach.top-offerten.ch
AWS_SES_OUTREACH_MAIL_FROM_DOMAIN=mail.outreach.top-offerten.ch
```

## Staging

```env
AWS_DEFAULT_REGION_TOPUMZUGDE=eu-central-1
AWS_SES_IDENTITY_DOMAIN=staging.top-offerten.ch
AWS_SES_MAIL_FROM_DOMAIN=mail.staging.top-offerten.ch
AWS_SES_OUTREACH_IDENTITY_DOMAIN=outreach.staging.top-offerten.ch
AWS_SES_OUTREACH_MAIL_FROM_DOMAIN=mail.outreach.staging.top-offerten.ch
```

## Local Development

Leave empty to skip SES setup, or use the staging values if you need to test real sending locally:

```env
AWS_DEFAULT_REGION_TOPUMZUGDE=eu-west-1
AWS_SES_IDENTITY_DOMAIN=dev.top-offerten.ch
AWS_SES_MAIL_FROM_DOMAIN=mail.dev.top-offerten.ch
AWS_SES_OUTREACH_IDENTITY_DOMAIN=dev.outreach.top-offerten.ch
AWS_SES_OUTREACH_MAIL_FROM_DOMAIN=mail.dev.outreach.top-offerten.ch
MAIL_MANAGER_CALLBACK_ENDPOINT=https://caritative-reina-inelastic.ngrok-free.dev/email/sns
```

## DNS Records Required

Each identity domain needs DKIM records (created automatically by SES). Each MAIL FROM subdomain needs:

| Domain | Type | Value |
|---|---|---|
| `mail.top-offerten.ch` | MX | `10 feedback-smtp.eu-central-1.amazonses.com` |
| `mail.top-offerten.ch` | TXT | `"v=spf1 include:amazonses.com -all"` |

Same pattern applies to all MAIL FROM domains (`mail.outreach.top-offerten.ch`, `mail.staging.top-offerten.ch`, etc.).

The `messenger:ses-sns:setup-all` command provisions identities and can auto-create Route53 records if configured.
