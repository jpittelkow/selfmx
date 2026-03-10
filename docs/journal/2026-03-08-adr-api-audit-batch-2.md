# ADR & API Documentation Audit — Batch 2: Communication & Notifications

**Date:** 2026-03-08
**Related:** [ADR API Audit Roadmap](../plans/adr-api-audit-roadmap.md), [ADR-005](../adr/005-notification-system-architecture.md), [ADR-016](../adr/016-email-template-system.md), [ADR-017](../adr/017-notification-template-system.md), [ADR-025](../adr/025-novu-notification-integration.md), [ADR-027](../adr/027-real-time-streaming.md)

## Summary

Audited 5 ADRs (005, 016, 017, 025, 027) against actual code and API documentation. Found no implementation gaps — all ADR-described functionality is implemented. Found significant documentation gaps (27 API doc fixes) and 4 ADR updates needed.

## Results

| ADR | Docs | ADR | Code | Verdict |
|-----|------|-----|------|---------|
| 016 | ✅ | ✅ | ✅ | Clean |
| 017 | ⚠️ | ⚠️ | ✅ | README missing 5 endpoints; seeder grew from 6→14 types |
| 025 | ⚠️ | ✅ | ✅ | 7 endpoints missing from both README and OpenAPI |
| 027 | ⚠️ | ✅ | ✅ | Broadcasting auth missing from docs |
| 005 | ⚠️ | ⚠️ | ✅ | Largest gap — 17+ endpoints undocumented; OpenAPI schema errors; ADR model schema stale |

## Key Fixes

- **README:** Added 7 new sections (Notification Templates, Notification Settings, Admin Notification Channels, Notification Deliveries, User Notification Settings, Novu Integration, Real-Time Streaming) covering ~30 endpoints
- **OpenAPI:** Fixed `Notification.id` type (integer→string UUID), `mark-read` body field name and type; added ~25 missing endpoint definitions
- **ADR-005:** Updated Notification model schema (`body`→`message`, removed `channels_sent`), added ntfy to channel table, updated orchestrator code sample
- **ADR-017:** Updated seeder description (6 types × 3 groups → 14 types × 4 groups)

## Observations

- The notification system has grown significantly beyond its original ADR scope (10→13 channels, 6→14 notification types)
- API documentation had the largest gap in the notification domain — many endpoints were never added to README or OpenAPI as they were implemented
- All implementations are correct and well-structured; the gaps are purely documentation
- Progress: 13/30 ADRs audited (43%)
