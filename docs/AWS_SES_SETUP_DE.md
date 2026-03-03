# AWS SES Setup Anleitung

Diese Anleitung erklärt die wichtigsten Konzepte für die Einrichtung von AWS SES für Multi-Tenant-Anwendungen.

## Kern-Konzepte

### 🔹 Identity

**Was darf senden?**

- Verifizierte Domain oder E-Mail-Adresse
- Eine Domain-Identity erlaubt alle Absender dieser Domain
- Du brauchst pro Domain genau **eine Identity**
- Du kannst **mehrere Identities** konfigurieren, um E-Mail-Streams zu trennen (z. B. transaktional vs. Outreach) und die Domain-Reputation zu schützen

**👉 Regelt den Absender**

#### Mehrere Identities zur Reputation-Isolation

Verwende separate (Sub-)Domains für verschiedene E-Mail-Streams, um die Absender-Reputation zu isolieren:

| Stream | Identity | Zweck |
|---|---|---|
| Transaktional | `example.com` | Passwort-Resets, Bestätigungen, Rechnungen |
| Outreach | `outreach.example.com` | Marketing, Newsletter, Kampagnen |

Bounces und Spam-Beschwerden auf `outreach.example.com` beeinflussen die Zustellbarkeit von `example.com` nicht.

Jede Identity braucht eigene DKIM-Verifizierung und MAIL FROM-Subdomain:

```
# Transaktional
AWS_SES_IDENTITY_DOMAIN=example.com
AWS_SES_MAIL_FROM_DOMAIN=mail.example.com

# Outreach
AWS_SES_OUTREACH_IDENTITY_DOMAIN=outreach.example.com
AWS_SES_OUTREACH_MAIL_FROM_DOMAIN=mail.outreach.example.com
```

Der Befehl `mail-manager:ses-sns:setup-all` provisioniert alle konfigurierten Identities automatisch.

---

### 🔹 Configuration Set

**Was passiert mit der Mail?**

- Definiert Event-Weiterleitung (Bounce, Complaint, Delivery, etc.)
- Hängt an SNS / SQS
- Typischerweise 1–3 Stück:
  - `transactional`
  - `system`
  - `marketing`

**👉 Regelt die Event-Verarbeitung**

---

### 🔹 Tenants

**Wem gehört die Mail?**

- ❌ **Nicht** über Identity trennen
- ❌ **Nicht** über Configuration Sets trennen
- ✅ **Sondern** über SES-Tags (z. B. `tenant_id=42`)

**👉 Regelt die fachliche Zuordnung**

---

## 🎯 Standard-Setup für SaaS

1. **1+ Domain Identities** — Hauptdomain für transaktionale Mails, Subdomain(s) für Outreach/Marketing
2. **1–3 Configuration Sets** (transactional, system, marketing)
3. **Tenant per Tag** mitschicken
4. **Events** über SNS → SQS → Laravel verarbeiten

✅ Fertig.

---

## Implementierungshinweise

- Verwende SES-Tags, um zu tracken, welcher Tenant welche E-Mail gesendet hat
- Configuration Sets regeln das technische Event-Routing
- Identities verifizieren nur die Absenderberechtigung
- Die gesamte Tenant-spezifische Logik gehört in deine Anwendung, nicht in die AWS-Infrastruktur
- **Das Setup weist das Configuration Set automatisch der Identity zu** als Standard, sodass alle E-Mails von dieser Identity das angegebene Configuration Set für Event-Tracking verwenden
