# Email Design Audit Roadmap

Design audit focused on the email experience — especially composing and reading emails. Identify UX friction, visual inconsistencies, and opportunities to improve clarity, usability, and polish across the mail UI.

## Phase 1: Mailgun Webhook Audit & Duplicate Email Bug ✅

**Goal**: Audit the inbound webhook pipeline for correctness and fix the duplicate email issue.

All items complete. Deduplication implemented at three layers: provider event ID idempotency check, mailbox-scoped message_id check, and DB-level unique constraint (`mailbox_id`, `message_id`). Event webhook 500 errors fixed — handler now returns 200 for all non-transient errors. Log retention handled by `PruneWebhookLogsCommand` scheduled daily. Full test coverage in `EmailWebhookTest.php`.

## Phase 2: Reading Experience Audit

**Goal**: Review the email reading and thread browsing experience for clarity, scanability, and comfort.

### Mail Layout — Always Show List + Reading Pane

- [x] **Ensure the mail UI always renders as a two-panel layout**: thread list on the left, reading pane on the right — on every mail view (Inbox, Sent, Drafts, Starred, Spam, Trash, labels)
- [x] When no email is selected, show a placeholder in the reading pane (e.g., "Select an email to read" with an icon) — never collapse to a single-panel list-only view
- [x] Preserve the two-panel layout when navigating between views (switching from Inbox to Sent should not flash to list-only)
- [x] On mobile, the reading pane can stack below or navigate forward — but desktop/tablet should always show both panels

### Unread Count in Page Title

- [x] **Show unread email count in the browser tab title** — e.g., `(3) Inbox - selfmx` or `(3) Mail - selfmx`
- [x] Update the count in real-time as emails are read or new emails arrive (tie into the existing 60-second polling / Reverb push)
- [x] Clear the count indicator when all emails are read (title reverts to just `Mail - selfmx`)

### Thread List

- [x] Audit thread list density — is there too much or too little information per row?
- [x] Review visual hierarchy in thread rows (sender, subject, preview, date, labels, attachment indicator)
- [x] Evaluate unread vs read visual distinction — is it strong enough at a glance?
- [x] Audit starred, snoozed, and scheduled indicators in the thread list
- [x] Review bulk selection UX — checkbox placement, select-all behavior, bulk action bar
- [x] Audit empty state for each view (Inbox, Sent, Drafts, Spam, Trash, labels)
- [x] Review thread list loading skeleton — does it match the actual layout?

### Mark as Read Behavior (Bug + Enhancement)

- [x] **Bug**: Investigate mark-as-read not working — verify the API call fires, check for race conditions, confirm optimistic UI update
- [x] Add auto-mark-as-read on double-click / opening an email in the detail view — reading an email should mark it read without requiring a manual action
- [x] Review single-click vs double-click semantics — single-click selects/previews, double-click opens and marks read
- [x] Ensure mark-as-read state syncs immediately in the thread list (bold/unread indicator clears, unread count badge updates)
- [x] Audit the manual mark-as-read / mark-as-unread toggle — is it discoverable in the action bar and right-click context menu?

### Email Detail / Thread View

- [x] Audit email header layout — sender, recipients, date, labels, action buttons
- [x] Review email body rendering — HTML email display, image loading, dark mode handling
- [x] Evaluate thread conversation view — is the message grouping clear? Are collapsed messages obvious?
- [x] Audit attachment display in detail view — inline vs download, preview thumbnails, file type icons
- [x] Review action bar (reply, forward, trash, archive, star, label, more) — icon clarity, spacing, mobile overflow
- [x] Audit the transition between thread list and detail view (navigation feel, back button, breadcrumb)
- [x] Review AI features presentation — thread summary, smart reply suggestions, priority badge placement

### Email Content Rendering

- [x] Audit HTML email rendering in the iframe — zoom, scroll, responsiveness
- [x] Review dark mode email rendering — does the theme-aware iframe work well across common email templates?
- [x] Evaluate plain text email rendering — formatting, link detection, whitespace handling
- [x] Audit external image blocking / loading UX (privacy indicator, load-images prompt)

## Phase 3: Compose Experience Audit ✅

**Goal**: Review the compose dialog and sending flow for usability, clarity, and delight.

### Layout & Visual Design

- [x] Audit compose dialog sizing, padding, and spacing — ensure it feels spacious on desktop and usable on mobile
- [x] Review field labels, placeholders, and input styles (To, Cc, Bcc, Subject) for consistency with the rest of the app
- [x] Evaluate the TipTap toolbar — added Code Block button; toolbar grouping is logical
- [x] Audit attachment UX — drag-and-drop zone visibility, file list layout, progress indicators, remove affordance
- [x] Review compose dialog positioning (modal vs docked) — Gmail-style compact layout retained

### Interaction & Flow

- [x] Audit compose-to-send flow — send button has tooltip for disabled state; missing-subject AlertDialog added
- [x] Review draft auto-save feedback — icon + text (Loader2 / Cloud) replaces plain text
- [x] Evaluate reply/reply-all/forward UX — quoted content split out of editor into collapsible section below
- [x] Audit scheduled send UX — active schedule now shows as a dismissible badge strip; Clock button highlights when active
- [x] Review contact autocomplete — speed, visual design, handling of unknown recipients, recent contacts weighting
- [x] Audit Cc/Bcc toggle — relabeled "Cc Bcc" / X icon (Gmail style)
- [x] Review signature insertion — visual separator (border-top) via `.ProseMirror [data-signature]` CSS rule

### Error States & Edge Cases

- [x] Audit empty recipient / missing subject warnings — recipient tooltip + missing-subject AlertDialog
- [x] Review send failure handling — retry action button on error toast; form data preserved in state
- [x] Audit large attachment handling — client-side 25MB warning toast on attach/drop
- [x] Review behavior when composing while offline — offline warning strip in footer; Send disabled when offline

## Phase 4: New Email Notifications

**Goal**: Alert users when new emails arrive, with support for generic and tag/label-based notification rules.

### Core Notification

- [x] Trigger an in-app "New email" notification when an inbound email is received (via the existing EmailReceived Reverb event)
- [x] Show a toast/alert with sender, subject, and a preview snippet — clicking it navigates to the email
- [ ] Integrate with the existing notification system (NotificationService) so new-email alerts appear in the notification bell/panel
- [ ] Support browser push notifications for new emails (via existing web push infrastructure)

### Tag/Label-Based Notification Rules

- [ ] Allow users to configure notification preferences per label — e.g., "notify me immediately for emails labeled **Urgent**"
- [ ] Support notification levels per label: all (every email), digest (batched summary), or none (silent)
- [ ] Default behavior: generic notification for all new emails (user can disable or customize per label)
- [ ] Apply auto-label rules first, then evaluate notification rules — so an email auto-labeled "Billing" can trigger a specific alert
- [ ] UI for managing notification rules: settings page or inline in label management

### Notification Channels

- [ ] Route new-email notifications through all configured channels (in-app, email digest, push, Slack, Telegram, etc.) based on user notification preferences
- [ ] Respect Do Not Disturb / quiet hours settings from the existing notification system
- [ ] Avoid notification loops — do not send an email notification about a new email arriving

## Phase 5: Spam Filtering Enhancements

**Goal**: Improve spam detection accuracy, add user feedback loops, and surface spam management tools in the UI. Builds on the existing foundation: `SpamFilterService` (block/allow lists + provider score threshold), `EmailRuleService` (condition-based rules with `mark_spam` action), and the spam filter configuration page.

### Spam Threshold & Settings UI

- [x] **Add spam threshold slider to the email provider configuration page** — surfaced as a labeled slider (0–10, step 0.5) on `/configuration/email-provider` with real-time value display and descriptive help text
- [x] Show the spam score on the email detail view header — displayed as a badge with score value when email is flagged as spam
- [x] Add a "Why was this flagged?" tooltip on spam emails — tooltip on spam score badge explains the score exceeded the configured threshold

### Spam Feedback Loop

- [x] **When a user marks an email as spam, offer to block the sender** — toast with "Block sender" action button that adds sender to block list via existing spam filter API
- [x] **When a user marks a spam email as "not spam", offer to allow the sender** — toast with "Allow sender" action button that adds sender to allow list
- [ ] Track spam/not-spam corrections per sender — store a `spam_reports` count on a lightweight sender reputation table to inform future scoring
- [ ] Use correction history to adjust effective threshold per sender — if a sender has been repeatedly marked "not spam", reduce their effective score; if repeatedly marked spam, increase it

### Content-Based Filtering

- [ ] **Add keyword block list** — allow users to define keywords/phrases that trigger spam classification when found in subject or body (e.g., "lottery winner", "act now")
- [ ] **Add URL scanning** — flag emails containing URLs on known phishing/spam domain lists (integrate a free blocklist like `abuse.ch URLhaus` or allow user-managed URL blocklists)
- [ ] Add attachment type filtering — allow blocking by file extension (e.g., `.exe`, `.scr`, `.bat`) with a configurable list in spam filter settings
- [ ] Add header analysis — check for missing or suspicious email headers (`Reply-To` mismatch, missing `Date`, suspicious `Received` chain) and add to spam score

### AI-Powered Spam Classification

- [ ] **Integrate LLM-based spam detection** — use the existing LLM orchestration (`backend/app/Services/LLM/`) to classify borderline emails (score near threshold). Send subject + body snippet to the configured LLM with a spam/ham classification prompt
- [ ] Make AI spam classification opt-in via `email_ai` settings — add `spam_classification_enabled` toggle (default off) to avoid unexpected LLM costs
- [ ] Cache LLM classification results per `message_id` to avoid re-processing
- [ ] Respect the existing `daily_token_limit` for AI spam checks — skip LLM classification when budget is exhausted and fall back to score-only

### Spam Statistics & Reporting

- [ ] **Add a spam dashboard widget** — show spam vs legitimate email counts over the last 7/30 days on the mail overview or a dedicated stats section
- [ ] Show top blocked senders/domains — aggregate block list hits and display a ranked list
- [ ] Add spam rate trend line — chart showing daily spam percentage over time to help users gauge whether filtering is improving
- [ ] Log spam filter decisions to audit trail — record which filter (block list, threshold, rule, AI) caught each spam email for debugging and transparency

### Provider-Native Spam Integration

- [ ] **Pull and store provider-specific spam metadata** — Mailgun provides `X-Mailgun-Sflag` / `X-Mailgun-Sscore`; SES provides `spamVerdict`; SendGrid provides spam report headers. Normalize these into `spam_score` and a `spam_provider_details` JSON column
- [ ] Use provider SPF/DKIM/DMARC results in scoring — if the provider reports authentication failures, increase the spam score or auto-flag
- [ ] Support Mailgun's built-in spam filtering settings — expose "aggressive" vs "moderate" filtering options that map to Mailgun route configuration

### Quarantine System

- [ ] **Add a quarantine queue for borderline emails** — emails with scores near the threshold (e.g., within 1.0 of the cutoff) go to a quarantine folder instead of inbox or spam
- [ ] Show quarantine as a separate mail view (between Inbox and Spam in the sidebar) with a count badge
- [ ] Allow users to release (move to inbox + allow list) or confirm spam (move to spam + block list) from quarantine
- [ ] Auto-expire quarantined emails after a configurable period (default 30 days) — move to trash with a notification

## Phase 6: Navigation & Information Architecture

**Goal**: Ensure the mail navigation feels natural within the broader app.

- [ ] Audit sidebar mail section — folder ordering, label management, compose button placement
- [ ] Review the relationship between sidebar navigation and mail view state (URL sync, active indicators)
- [ ] Evaluate label creation and management UX — inline creation, color picker, rename/delete
- [x] **Integrate emails into global search (Cmd+K)** — added `emails` and `contacts` types to `SearchController` validation. Email and contact models were already indexed in Meilisearch; the validation rule was the only blocker. Search result icons added for both types.
- [ ] Audit search integration — is the search bar prominent? Do filters feel natural?
- [ ] Review keyboard shortcut discoverability — is there a help overlay? Are bindings intuitive?
- [ ] Audit command palette mail commands — completeness, naming, shortcut display
- [ ] Review mobile navigation — does the sidebar collapse cleanly? Is swipe-to-action available?

## Phase 7: Visual Consistency & Polish

**Goal**: Ensure the mail UI is consistent with the rest of the app and feels polished.

- [ ] Audit typography — font sizes, weights, and line heights across all mail views
- [ ] Review color usage — are semantic colors (info, warning, error, success) used consistently?
- [ ] Evaluate spacing and alignment — do mail components follow the same grid/spacing as the rest of the app?
- [ ] Audit dark mode across all mail views — contrast, readability, no missed backgrounds
- [ ] Review animations and transitions — loading states, view transitions, toast notifications
- [ ] Audit responsive breakpoints — does the mail UI degrade gracefully from desktop → tablet → mobile?
- [ ] Review accessibility — focus management, keyboard navigation, screen reader labels, contrast ratios
- [ ] Audit loading and error states across all mail views for visual consistency
