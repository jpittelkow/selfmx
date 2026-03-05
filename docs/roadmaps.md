# Roadmaps & Plans

Development roadmaps and implementation history.

## Active Development

Currently in progress. Complete these before starting new work.

- **[Code Review Remediation (Phases 4-5)](plans/code-review-remediation-roadmap.md)** - Phases 1-3 complete (security fixes, hardening, medium-priority improvements). Remaining: Phase 4 low-priority cleanup (~8 hours) and Phase 5 test coverage expansion (~12 hours).

## Next Up

Ready to start. These are unblocked and can begin immediately.

- **[Cloudflare DNS Integration (Phase 8)](plans/cloudflare-phase8-roadmap.md)** - Automatically manage DNS records required by email providers using Cloudflare API. Sync status dashboard, one-click DNS fixes, intelligent SPF merging, automated drift detection, and extensible DNS provider pattern.
- **Mail Forwarding (Phase 11)** - Forward specific mailboxes or catchall mail to external addresses. Per-forward choice of keeping a local copy or pass-through. See [email-app-roadmap.md](plans/email-app-roadmap.md#phase-11-mail-forwarding).
## Completed

- **[Email Design Audit — Phases 1-3](plans/email-design-audit-roadmap.md)** - Webhook audit, reading experience, and compose experience all complete. Remaining phases (4-7: notifications, spam, navigation, polish) continue as future work.
- **[Mailgun Deep Integration (Phase 7)](plans/mailgun-phase7-roadmap.md)** - Domain detail page with 8 tabs, domain search/filtering, provider health badge, webhook testing, DKIM auto-rotation UI with history, bulk suppression import/export (CSV), event-to-email linking popover, compose-time suppression warnings, and full test coverage. Nice-to-haves deferred to Phase 7.5.
- **[User Mail Settings — Sidebar Section & Per-User Spam](plans/user-mail-settings-roadmap.md)** - Mail Settings section at `/mail/settings/` with rules, per-user spam filter, and email import pages. Sidebar nav group added.
- **[Move Import Email to User Settings](plans/email-import-move-plan.md)** - Import relocated from sidebar to `/mail/settings/import/` as an inline card.
- **Page Title Audit** - Centralized `PageTitleManager` component covers 96+ routes with dynamic titles and unread count in browser tab.

See **[roadmap-archive.md](roadmap-archive.md)** for all completed roadmaps and journal entries.

## Integration Costs

Reference for paid third-party integrations used by selfmx. All integrations are optional — the app runs fully self-hosted with no paid services required. Costs only apply when an admin configures and enables a paid provider.

### LLM Providers (per-token/per-request)

| Provider | Pricing Model | Notes |
|----------|--------------|-------|
| OpenAI (GPT-4, GPT-4o) | Per input/output token | Varies by model; GPT-4o is cheaper than GPT-4 |
| Anthropic (Claude) | Per input/output token | Varies by model tier (Haiku, Sonnet, Opus) |
| Google Gemini | Per input/output token | Free tier available; paid for higher usage |
| AWS Bedrock | Per input/output token | Pay-per-use via AWS account; model pricing varies |
| Azure OpenAI | Per input/output token | Azure subscription required; same models as OpenAI |
| Ollama (local) | Free (self-hosted) | Runs on local hardware; no API costs |

**Cost amplifiers:** Aggregation mode queries all configured providers (multiplied cost); Council mode queries all providers plus a synthesis step. Single mode is the most cost-efficient.

### Email Providers (per message)

| Provider | Pricing Model | Notes |
|----------|--------------|-------|
| SMTP (self-hosted) | Free | Requires own mail server |
| Mailgun | Per email (free tier available) | 100 emails/day free, then per-email |
| SendGrid | Per email (free tier available) | 100 emails/day free, then tiered plans |
| AWS SES | Per email | ~$0.10/1,000 emails; very cost-effective at scale |
| Postmark | Per email | Transactional-focused; tiered plans |

### SMS Providers (per message)

| Provider | Pricing Model | Notes |
|----------|--------------|-------|
| Twilio | Per SMS segment | Pricing varies by country; ~$0.0079/msg (US) |
| Vonage | Per SMS segment | Pricing varies by country |
| AWS SNS | Per SMS | ~$0.00645/msg (US); international rates vary |

### Storage Providers (per GB/month)

| Provider | Pricing Model | Notes |
|----------|--------------|-------|
| Local disk | Free | Default; limited by server disk |
| Amazon S3 | Per GB stored + requests | ~$0.023/GB/month (Standard) |
| Google Cloud Storage | Per GB stored + requests | ~$0.020/GB/month (Standard) |
| Azure Blob Storage | Per GB stored + requests | ~$0.018/GB/month (Hot tier) |
| DigitalOcean Spaces | Flat + per GB | $5/month includes 250 GB |
| MinIO (self-hosted) | Free | S3-compatible; runs on own infrastructure |
| Backblaze B2 | Per GB stored + requests | ~$0.006/GB/month; 10 GB free |

### Real-Time / Broadcasting

| Provider | Pricing Model | Notes |
|----------|--------------|-------|
| Reverb | Self-hosted (free) | Included in Docker container. Used for live streaming features (audit logs, app logs, notifications) |

### Notification Services (optional)

| Provider | Pricing Model | Notes |
|----------|--------------|-------|
| Novu (optional) | Free tier + usage-based | Optional notification infrastructure (Cloud or self-hosted). Free for 30k events/month. Local system remains default fallback. See [ADR-025](adr/025-novu-notification-integration.md). |

### Payment Processing (per transaction)

| Provider | Pricing Model | Notes |
|----------|--------------|-------|
| Stripe | 2.9% + 30c per transaction | Plus 1% platform fee via Connect. Optional — only when Stripe is configured. See [Stripe Connect Roadmap](plans/stripe-connect-roadmap.md). |

### Free Integrations (no cost)

These integrations are self-hosted or free and incur no third-party costs:

- **Meilisearch** — Embedded in Docker container (self-hosted)
- **Ollama** — Local LLM inference (self-hosted)
- **SSO/OAuth providers** — Google, GitHub, Microsoft, Apple, Discord, GitLab authentication is free
- **Telegram, Discord, Slack, Matrix, ntfy** — Notification channels use free APIs/webhooks
- **Web Push (VAPID)** — Browser push notifications are free
- **SMTP** — Self-hosted email is free

### Cost Management Considerations

- **LLM is typically the largest cost** — Monitor token usage; prefer Single mode over Aggregation/Council for routine queries
- **Email costs are usually negligible** — Most apps send fewer than 1,000 emails/month (well within free tiers)
- **SMS is per-message** — Can add up with international recipients; consider limiting to critical notifications only
- **Storage scales with data** — Local disk is free; cloud storage costs grow with backup frequency and file uploads
- **Broadcasting is optional** — Only needed for real-time log streaming; most deployments don't require it

## Roadmap Maintenance

When adding or updating roadmaps:

1. **New roadmaps**: Add to appropriate section (Active, Next Up, or Planned) with priority
2. **Completing work**: Move to [roadmap-archive.md](roadmap-archive.md) with date, note any remaining optional work
3. **Verify links**: Ensure all roadmap file links resolve correctly
4. **Journal entries**: Add implementation notes to the Journal Entries table in [roadmap-archive.md](roadmap-archive.md)

## Build Verification

To verify the build works end-to-end:

1. Clean rebuild: `docker-compose down -v && docker-compose up -d --build`
2. Wait for startup, then access http://localhost:8080
3. Test: login flow, dashboard loads, configuration pages work
4. Check browser console for errors

**Production build verified 2026-02-15**: Docker build, registration, login, dashboard, configuration pages all working. Production standalone mode is clean.

**Known dev-mode issues** (do not affect production):
- The dev compose (`docker-compose.yml`) runs Next.js in Turbopack dev mode. After a `down -v` (which deletes the `node_modules` volume), the `start-nextjs.sh` script auto-installs dependencies but Turbopack may produce stale module chunks for `lib/utils.ts` (e.g. `formatCurrency is not a function`). Workaround: restart the container once after the initial `npm install` completes, or test with a production container (`docker run` without source mounts).
- Hydration mismatch warning (debug level) from Sonner Toaster's deferred client-side mount — cosmetic only, does not crash production builds.

**Known issues to diagnose:**
- `Failed to load resource: the server responded with a status of 500 (Internal Server Error)` — Intermittent or consistent 500 errors from the backend API. Needs investigation to identify which endpoint(s) are failing, root cause (unhandled exception, missing env var, migration issue, etc.), and whether it affects production or dev only.
