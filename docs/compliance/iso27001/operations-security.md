# ISO 27001 Operations Security (A.12)

**Organization:** [COMPANY_NAME]  
**Version:** 1.0  
**Last Updated:** [DATE]

> **Customize**: Document operational procedures, change management, malware protection, and related controls. Replace placeholders with your procedures and evidence locations.

---

## 1. Overview

ISO 27001 Annex A.12 addresses operational security: procedures, malware protection, backup, logging, change management, and vulnerability management. This template supports compliance with the technological controls theme.

---

## 2. A.12.1 – Operational Procedures and Responsibilities

Operational procedures and responsibilities shall be defined and documented.

### Implementation

| Control | Implementation | Evidence |
|---------|----------------|----------|
| Documented procedures | Runbooks, operational procedures | [Procedure repository] |
| Responsibilities | Roles and responsibilities for operations | [RACI, job descriptions] |
| Change to procedures | Procedures updated and approved when operations change | [Version control, approval] |

**Sourdough mapping:** Deployment and startup are managed by Docker and supervisord (see [docker/](https://github.com/Sourdough-start/sourdough/tree/master/docker)). Document your own runbooks for backup, restore, scaling, and incident response. See [Business Continuity Plan](../business-continuity-plan.md) and [Incident Response Plan](../incident-response-plan.md).

---

## 3. A.12.2 – Protection from Malware

Information and other associated assets shall be protected against malware.

### Implementation

| Control | Implementation | Evidence |
|---------|----------------|----------|
| Malware protection | Anti-malware on endpoints and servers where applicable | [Agent config, scan schedule] |
| Definitions | Malware definitions kept up to date | [Update policy, logs] |
| Awareness | User awareness (no unauthorized software, reporting) | [Security awareness training] |

> **Customize**: For containerized deployments, use trusted base images, image scanning, and restrict execution. Document host-level anti-malware if applicable.

---

## 4. A.12.3 – Backup

Backup copies of information, software, and systems shall be maintained and tested in accordance with the agreed topic-specific policy.

### Implementation

| Control | Implementation | Evidence |
|---------|----------------|----------|
| Backup policy | Schedule, retention, scope | [Backup policy, BCP] |
| Backup execution | Backups performed per schedule | [Backup logs, success rate] |
| Restore testing | Restore tested periodically | [Test records] |

**Sourdough mapping:** Backup feature (Configuration > Backup). See [Business Continuity Plan](../business-continuity-plan.md) for RTO/RPO and backup procedures.

---

## 5. A.12.4 – Logging and Monitoring

Event logs shall be produced, stored, and reviewed to support incident detection and investigation.

### Implementation

| Control | Implementation | Evidence |
|---------|----------------|----------|
| Logging | Audit logs, access logs, application logs | [Log config, retention] |
| Log protection | Logs protected from tampering and unauthorized access | [Access control, integrity] |
| Log review | Periodic review and alerting | [Review schedule, alert rules] |

**Sourdough mapping:** Audit logs and optional HIPAA-style access logs (Configuration > Logs). Application logging via Laravel Log. Retain and review per [Data Handling Policy](../data-handling-policy.md).

---

## 6. A.12.5 – Control of Operational Software

Installation of software on operational systems shall be controlled.

### Implementation

| Control | Implementation | Evidence |
|---------|----------------|----------|
| Approval | Software installation requires approval | [Change process] |
| Inventory | Approved software inventory | [Inventory list] |
| Updates | Updates applied in controlled manner | [Patch policy, change records] |

**Sourdough mapping:** Application and dependencies defined in Dockerfile, composer.json, package.json. Deployment via controlled CI/CD or image updates.

---

## 7. A.12.6 – Technical Vulnerability Management

Information about technical vulnerabilities shall be obtained, and the organization's exposure to such vulnerabilities shall be evaluated and appropriate measures taken.

### Implementation

| Control | Implementation | Evidence |
|---------|----------------|----------|
| Vulnerability awareness | Subscribe to vendor/advisory feeds | [Subscription, process] |
| Assessment | Regular scanning (SAST/DAST, dependency check) | [Scan results, schedule] |
| Remediation | Patch or mitigate within defined SLA | [Remediation records] |

**Sourdough mapping:** SAST in CI (PHPStan, ESLint security, Semgrep). Run `./scripts/security-scan.sh` locally. Dependency updates via Composer and npm; review security advisories. See [SDLC Policy](../sdlc-policy.md) and [Security Compliance Roadmap](../../plans/security-compliance-roadmap.md).

---

## 8. A.12.7 – Information Systems Audit Considerations

Audit tests and other assurance activities involving operational systems shall be planned and agreed to minimize the risk of disruption.

### Implementation

| Control | Implementation | Evidence |
|---------|----------------|----------|
| Audit planning | Audit scope and methods agreed with operations | [Audit plan] |
| Minimize impact | Read-only or non-production where possible | [Audit procedures] |
| Approval | Audit access approved and logged | [Access approval, audit trail] |

---

## 9. Revision History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | [DATE] | [AUTHOR] | Initial version |
