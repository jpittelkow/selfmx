# Email Provider Comparison Guide

Reference document for the in-app provider selection guide. This will be presented in the configuration section to help operators choose the right email provider for their selfmx instance.

> **Requirement:** All providers listed here must support **inbound email processing** (receiving email via webhook/API), since selfmx is a full email client — not just outbound sending.

## Supported Providers (with Inbound)

selfmx has a provider abstraction layer (`EmailProviderInterface`) with adapters for Mailgun, AWS SES, SendGrid, and Postmark. The providers below are candidates for current or future integration.

### Quick Comparison

| Provider | Free Volume | Free Domains | Inbound | Paid From | Cost per 1K | DX Quality |
|---|---|---|---|---|---|---|
| **Amazon SES** | 3,000/mo (12 months) | Unlimited | Yes | Usage only | **$0.10** | Complex |
| **Resend** | 3,000/mo (100/day) | 1 | Yes | $20/mo | $0.40 | Outstanding |
| **Mailgun** | ~3,000/mo (100/day) | 1 | Yes | $35/mo | $1.50 | Good |
| **MailerSend** | 500/mo | 1 | Starter+ ($25/mo) | $5.60/mo | $1.12 | Excellent |
| **SMTP2GO** | 1,000/mo (200/day) | 5 | Yes | $12.50/mo | $1.25 | Good |
| **Postmark** | 100/mo | 5 | Pro+ ($16.50/mo) | $15/mo | $1.20–1.80 | Outstanding |

### Eliminated Providers (No Inbound)

| Provider | Why Eliminated |
|---|---|
| Brevo (Sendinblue) | No inbound email processing |
| Mailjet | No inbound email processing |
| Elastic Email | No inbound on free; sandbox-only free tier |
| SendGrid | Free tier discontinued July 2025; declining platform quality |
| SparkPost / Bird | Platform being sunset; forced migrations; not recommended |

---

## Provider Deep Dives

### Amazon SES

**Best for:** High-volume senders already on AWS who want the lowest per-email cost.

| | Details |
|---|---|
| **Free tier** | 3,000 emails/mo for first 12 months, then pay-as-you-go |
| **Domains** | Unlimited (up to 10,000 identities per region) |
| **Inbound** | Yes — via SES Receipt Rules + SNS topics |
| **Outbound cost** | $0.10 per 1,000 emails |
| **Inbound cost** | $0.10 per 1,000 emails |
| **Dedicated IP** | $24.95/mo per IP |
| **Webhooks** | Via SNS — requires separate topic configuration |
| **Setup complexity** | High — IAM policies, SES rules, SNS topics, no turnkey dashboard |

**Pros:**
- Cheapest at scale by a wide margin ($10 for 100K emails)
- Unlimited domains on every plan including free
- Massive sending capacity
- Tight integration with other AWS services

**Cons:**
- Steep learning curve (IAM, SNS, receipt rules)
- Free tier expires after 12 months
- No built-in dashboard for non-technical users
- Webhook routing is SNS-based (not simple HTTP POST)
- Deliverability slightly lower than Postmark (~93–95% vs ~99%)

**selfmx status:** Adapter exists (`SesProvider`).

---

### Resend

**Best for:** Developer-first teams who want modern API design and fast integration.

| | Details |
|---|---|
| **Free tier** | 3,000 emails/mo, 100/day, 1 domain, 1 webhook endpoint |
| **Pro ($20/mo)** | 50,000/mo, 10 domains, overage $0.90/1K |
| **Scale ($90/mo)** | 100,000/mo, 1,000 domains, dedicated IPs available ($30/mo) |
| **Inbound** | Yes — included on all plans |
| **Unique features** | React Email templates, multi-region, batch sending, email scheduling |

**Pros:**
- Best modern developer experience — clean API, minimal boilerplate
- React-first template system
- Inbound email included on free tier
- Strong fit for SaaS/startups

**Cons:**
- Only 1 domain on free tier (need $20/mo for multi-domain)
- Some reports of occasional sending delays (1+ minute)
- Newer platform, smaller track record than Mailgun/SES

**selfmx status:** No adapter yet. Strong candidate for addition.

---

### Mailgun (Sinch)

**Best for:** Established transactional email with mature API and good inbound routing.

| | Details |
|---|---|
| **Free tier** | ~3,000/mo (100/day), 1 domain, 1-day log retention |
| **Foundation ($35/mo)** | 50,000/mo, 1,000 domains, dedicated IP add-on ($59/mo) |
| **Scale ($90/mo)** | 100,000/mo, 1,000 domains, dedicated IP included, email validation |
| **Inbound** | Yes — regex-based route matching with webhook forwarding |
| **Unique features** | Route-based inbound with regex, send-time optimization, email validation |

**Pros:**
- Mature, well-documented API with strong SDK support
- Powerful inbound routing (regex match expressions)
- Deep management API (domains, DNS, webhooks, suppressions, stats)
- Good deliverability

**Cons:**
- Only 1 domain on free tier
- Recent price increases (Flex plan doubled)
- 1-day log retention on free tier
- Foundation tier requires $59/mo add-on for dedicated IP

**selfmx status:** Primary adapter (`MailgunProvider`) + deep management integration (Phase 7).

---

### Postmark (ActiveCampaign)

**Best for:** Maximum deliverability and fast delivery speeds. Transactional-only focus.

| | Details |
|---|---|
| **Free tier** | 100 emails/mo, 5 domains, 4 users |
| **Basic ($15/mo)** | 10,000/mo, 5 domains, 15 message streams |
| **Pro ($16.50/mo)** | 10,000/mo, 10 domains, inbound processing |
| **Platform ($18/mo)** | 10,000/mo, unlimited domains, unlimited streams |
| **Inbound** | Pro+ — $16.50/mo minimum |
| **Unique features** | Message streams, ~99% inbox placement, fastest delivery speeds |

**Pros:**
- Industry-best deliverability (~99% inbox placement)
- Fastest delivery speeds in testing
- 5 domains on free tier (best free multi-domain support)
- Transactional-only focus keeps shared IP reputation high
- Outstanding developer experience

**Cons:**
- Inbound requires Pro tier ($16.50/mo)
- Very low free volume (100/mo — essentially a sandbox)
- No marketing email support (transactional only)
- Dedicated IPs require 300K+/mo volume ($50/mo)
- DMARC monitoring is a paid add-on ($14/mo/domain)

**selfmx status:** Adapter exists (`PostmarkProvider`).

---

### MailerSend (by MailerLite)

**Best for:** Budget-conscious teams who want a clean, modern API without high costs.

| | Details |
|---|---|
| **Free tier** | 500 emails/mo, 1 domain, 1 template, 100 daily API requests |
| **Starter ($25/mo)** | 50,000/mo, 10 domains, inbound routing, SMS API |
| **Professional ($25/mo+)** | 50,000/mo, unlimited domains, unlimited webhooks |
| **Inbound** | Starter+ — $25/mo minimum |
| **Unique features** | Drag-and-drop email builder, SMS API, DMARC monitoring add-on |

**Pros:**
- Clean, modern API with SDKs for PHP, Node.js, Python, Ruby, Go
- Good deliverability
- SMS API included on Starter+
- Competitive pricing at scale

**Cons:**
- Very low free volume (500/mo)
- Inbound requires Starter tier ($25/mo)
- Dedicated IPs only available on Enterprise
- Some reports of account suspension concerns

**selfmx status:** No adapter yet. Could be added.

---

### SMTP2GO

**Best for:** Simple, reliable SMTP relay with good free-tier multi-domain support.

| | Details |
|---|---|
| **Free tier** | 1,000/mo (200/day), 5 sender domains |
| **Starter ($12.50/mo)** | Higher volume |
| **Professional ($62.50/mo)** | Full feature set |
| **Inbound** | Yes — included on all plans |
| **Unique features** | Never-expiring free plan, second-best deliverability in independent tests |

**Pros:**
- 5 domains on free tier with inbound
- Strong deliverability (second-best in independent tests)
- Simple, reliable service
- Good SendGrid replacement

**Cons:**
- Smaller ecosystem and community
- Less feature-rich management API than Mailgun
- Fewer SDKs and integrations

**selfmx status:** No adapter yet. Good budget candidate.

---

## Recommendation Matrix

### By Priority

| If you care most about... | Choose | Why |
|---|---|---|
| **Lowest cost at scale** | Amazon SES | $0.10/1K — 10–15x cheaper than alternatives |
| **Best deliverability** | Postmark | ~99% inbox placement, fastest delivery |
| **Best developer experience** | Resend | Modern API, React templates, minimal setup |
| **Multi-domain on free tier** | SMTP2GO or Postmark | 5 domains free (Postmark: 100/mo; SMTP2GO: 1K/mo) |
| **Most features free** | Mailgun | Inbound routing, webhooks, stats on free tier |
| **All-in-one (email + SMS)** | MailerSend | SMS API included on Starter+ |

### By Scale

| Scale | Best Pick | Monthly Cost |
|---|---|---|
| **Hobby (< 1K/mo, 1–2 domains)** | Mailgun or Resend (free) | $0 |
| **Small (< 10K/mo, 3–5 domains)** | SMTP2GO (free) or Resend ($20) | $0–20 |
| **Medium (10–50K/mo)** | Resend ($20) or Mailgun ($35) | $20–35 |
| **Large (50–100K/mo)** | Resend ($90) or SES (usage) | $10–90 |
| **High volume (100K+/mo)** | Amazon SES | $10+ |

### Multi-Provider Strategy

selfmx supports assigning different providers to different domains. Consider:

- **Primary**: Resend or Mailgun for most domains (good DX, reasonable cost)
- **High-volume**: Route bulk sending domains through SES ($0.10/1K)
- **Critical transactional**: Route important domains through Postmark (best deliverability)

This lets you optimize cost and deliverability per domain without being locked into one provider.

---

## Provider Status in selfmx

| Provider | Adapter | Management API | Config UI | Status |
|---|---|---|---|---|
| **Mailgun** | Yes | Deep (Phase 7) | Yes | Primary — full integration |
| **AWS SES** | Yes | Basic | Yes | Supported |
| **SendGrid** | Yes | Basic | Yes | Supported (but provider declining) |
| **Postmark** | Yes | Basic | Yes | Supported |
| **Resend** | Not yet | — | — | Planned |
| **MailerSend** | Not yet | — | — | Candidate |
| **SMTP2GO** | Not yet | — | — | Candidate |

---

## API Documentation Links

Quick reference to each provider's API docs, pricing pages, and inbound email setup guides.

| Provider | API Docs | Pricing | Inbound Setup |
|---|---|---|---|
| **Amazon SES** | [SES v2 API](https://docs.aws.amazon.com/ses/latest/APIReference-V2/Welcome.html) | [SES Pricing](https://aws.amazon.com/ses/pricing/) | [SES Email Receiving](https://docs.aws.amazon.com/ses/latest/dg/receiving-email.html) |
| **Resend** | [Resend API](https://resend.com/docs/api-reference/introduction) | [Resend Pricing](https://resend.com/pricing) | [Resend Inbound](https://resend.com/docs/dashboard/webhooks/introduction) |
| **Mailgun** | [Mailgun API](https://documentation.mailgun.com/docs/mailgun/api-reference/openapi-final/tag/Domains/) | [Mailgun Pricing](https://www.mailgun.com/pricing/) | [Mailgun Inbound Routes](https://documentation.mailgun.com/docs/mailgun/api-reference/openapi-final/tag/Routes/) |
| **Postmark** | [Postmark API](https://postmarkapp.com/developer) | [Postmark Pricing](https://postmarkapp.com/pricing) | [Postmark Inbound](https://postmarkapp.com/developer/webhooks/inbound-webhook) |
| **MailerSend** | [MailerSend API](https://developers.mailersend.com/) | [MailerSend Pricing](https://www.mailersend.com/pricing) | [MailerSend Inbound](https://developers.mailersend.com/api/v1/inbound.html) |
| **SMTP2GO** | [SMTP2GO API](https://apidoc.smtp2go.com/) | [SMTP2GO Pricing](https://www.smtp2go.com/pricing/) | [SMTP2GO Inbound](https://support.smtp2go.com/hc/en-gb/articles/900000390766-Inbound-Email-Parsing) |

### SDKs by Language (for adapter development)

| Provider | PHP | Node.js | Python |
|---|---|---|---|
| **Amazon SES** | `aws/aws-sdk-php` | `@aws-sdk/client-ses` | `boto3` |
| **Resend** | `resend/resend-php` | `resend` | `resend` |
| **Mailgun** | `mailgun/mailgun-php` | `mailgun.js` | `mailgun` |
| **Postmark** | `wildbit/postmark-php` | `postmark` | `postmarker` |
| **MailerSend** | `mailersend/mailersend` | `mailersend` | `mailersend` |
| **SMTP2GO** | REST API (no official SDK) | REST API (no official SDK) | REST API (no official SDK) |

---

## Data Sources

Pricing and feature data collected March 2026 from official provider pricing pages. Verify current pricing before making purchasing decisions — providers change plans frequently.
