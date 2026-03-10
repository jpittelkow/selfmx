# ADR & API Documentation Audit — Batches 3 & 4

**Date:** 2026-03-08
**Related:** [ADR API Audit Roadmap](../plans/adr-api-audit-roadmap.md), [Bug Tracker](../plans/bug-tracker.md)

## Summary

Audited 10 ADRs (007, 010, 014, 021, 022, 006, 026, 028, 029, 030) covering Data & Storage and Features & Integrations. Found 1 implementation bug, 5 implementation fixes, significant documentation gaps across Stripe/Usage/File Manager/Search, and multiple ADR drift issues.

## Results

### Batch 3: Data & Storage

| ADR | Docs | ADR | Code | Verdict |
|-----|------|-----|------|---------|
| 007 | ⚠️ | ⚠️ | ✅ | DELETE endpoint missing from OpenAPI; ADR permissions stale |
| 010 | ✅ | ✅ | ✅ | Architecture ADR — no API surface |
| 014 | ⚠️ | ✅ | ✅ | System Settings section missing from README + OpenAPI |
| 021 | ❌ | ✅ | ✅ | Search section entirely missing from README; major OpenAPI schema errors |
| 022 | ⚠️ | ⚠️ | ⚠️ | ADR URLs wrong; 5 endpoints missing from ADR; GET returns incomplete data |

### Batch 4: Features & Integrations

| ADR | Docs | ADR | Code | Verdict |
|-----|------|-----|------|---------|
| 006 | ⚠️ | ⚠️ | ✅ | 6 LLM endpoints missing; council config schema stale; OpenAPI schema errors |
| 026 | ❌ | ✅ | ✅ | All 14 Stripe endpoints entirely absent from README and OpenAPI |
| 028 | ⚠️ | ⚠️ | ✅ | All 7 webhook endpoints missing from README; show endpoint missing from ADR/OpenAPI |
| 029 | ❌ | ⚠️ | ⚠️ | All 3 usage endpoints missing; `payments` filter missing from controller |
| 030 | ⚠️ | ❌ | ✅ | All 7 file manager endpoints missing from README; ADR had wrong paths and methods |

## Key Fixes

**ADRs updated:**
- ADR-007: `manage-backups` → `can:backups.*` / `manage-settings` → `can:settings.view|edit`
- ADR-006: council config `consensus_threshold`/`include_dissent` → `strategy` enum
- ADR-022: all endpoint URLs corrected (`/storage/settings` → `/storage-settings`); 5 new endpoints documented
- ADR-028: added `GET /webhooks/{webhook}` to API table
- ADR-029: removed `api` from integration types (not in `IntegrationUsage::INTEGRATIONS`)
- ADR-030: all 7 endpoint paths corrected to `/api/storage/files/*`; rename/move changed POST → PUT

**README additions (docs/api/README.md):**
- System Settings section (3 endpoints)
- Search section (6 endpoints + 4 admin endpoints)
- LLM section expanded (6 missing endpoints added)
- Stripe Connect + Settings + Payments + Webhook sections (14 endpoints)
- Outbound Webhooks section (7 endpoints)
- Integration Usage & Costs section (3 endpoints)
- File Manager section (7 endpoints)

**Implementation fixes:**
- `UsageController`: added `payments` to integration filter enum in `stats()`, `breakdown()`, `export()`

## Bug Found

**`GET /storage-settings` returns incomplete data** — `StorageSettingController::show()` uses `settingService->getGroup('storage')` which only returns schema-defined alert settings (4 keys), not the driver or provider credentials. The storage system itself works because `StorageService` reads directly from `SystemSetting::getGroup()` (bypassing schema). Logged as Low severity in [bug-tracker.md](../plans/bug-tracker.md).

## Observations

1. **Stripe and Usage were implemented cleanly** but completely undocumented — likely because they were added as separate features after initial documentation was written.
2. **ADR drift pattern**: Newer ADRs (028, 029, 030) were written as design specs before implementation and diverged during development. The file manager had the most severe drift — all endpoint paths changed.
3. **SettingService schema binding** creates subtle inconsistencies: settings can be written for any key but only schema-defined keys are returned via `getGroup()`. Storage provider settings fall into this gap.
4. **Progress**: 23/30 ADRs audited (77%). Batch 5 (Infrastructure & UI) remains.

## What's Next

Batch 5: Infrastructure & UI (ADRs 001, 008, 009, 011, 013, 019, 023) — Docker, Testing, PWA, Navigation, Audit Logging.
