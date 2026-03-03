# Email Phase 3: Compose Experience Audit — Implementation Plan

**Goal**: Improve the compose dialog and sending flow for usability, clarity, and polish. All changes are frontend-only unless noted.

**Primary file**: `frontend/components/mail/compose-dialog.tsx`
**Supporting files**: `frontend/components/mail/rich-text-editor.tsx`

---

## Current State Summary

- `compose-dialog.tsx`: `max-w-3xl`, `h-[95vh] sm:h-auto sm:max-h-[90vh]`. Send button is `disabled` when `to.length === 0 || !selectedMailboxId` — silently, no tooltip.
- `rich-text-editor.tsx`: Toolbar has Bold, Italic, Strike, bullet list, ordered list, blockquote, link/unlink, undo, redo. Code block is missing.
- Draft auto-save: 5s debounce. Shows "Saving draft..." or "Draft saved" as plain text in footer. Silent on failure.
- Scheduled send: Clock button opens Popover. After selecting a date, Send button label changes to "Schedule" — but no persistent badge indicates scheduling is active.
- Send failure: `catch` fires `toast.error("Failed to send email")`. No retry affordance.
- Missing subject: no warning. Send handler only checks recipient + mailbox.
- Attachments: no client-side size check before upload.
- Quoted content (reply/forward): embedded inline in TipTap. No collapse affordance.

---

## Step 1: Validation and Error States (highest impact, do first)

### 1a. Missing-subject confirmation

In `handleSend`, before `setIsSending(true)`, gate on empty subject:

```tsx
const [showNoSubjectDialog, setShowNoSubjectDialog] = useState(false);

// In handleSend:
if (subject.trim() === "") {
  setShowNoSubjectDialog(true);
  return;
}
```

Add an `AlertDialog` (shadcn) rendered beside the main `Dialog`:

```tsx
<AlertDialog open={showNoSubjectDialog} onOpenChange={setShowNoSubjectDialog}>
  <AlertDialogContent>
    <AlertDialogHeader>
      <AlertDialogTitle>Send without a subject?</AlertDialogTitle>
      <AlertDialogDescription>This email has no subject line.</AlertDialogDescription>
    </AlertDialogHeader>
    <AlertDialogFooter>
      <AlertDialogCancel onClick={() => setShowNoSubjectDialog(false)}>Add Subject</AlertDialogCancel>
      <AlertDialogAction onClick={() => { setShowNoSubjectDialog(false); handleSend(true); }}>Send Anyway</AlertDialogAction>
    </AlertDialogFooter>
  </AlertDialogContent>
</AlertDialog>
```

Pass a `skipSubjectCheck` param to `handleSend` to avoid re-triggering the dialog on the retry.

### 1b. Send button tooltip for disabled state

Wrap the Send button in a `Tooltip` so touch devices get feedback:

```tsx
<TooltipProvider>
  <Tooltip>
    <TooltipTrigger asChild>
      <span>
        <Button onClick={() => handleSend()} disabled={isSending || to.length === 0 || !selectedMailboxId}>
          Send
        </Button>
      </span>
    </TooltipTrigger>
    {(to.length === 0 || !selectedMailboxId) && (
      <TooltipContent>
        {to.length === 0 ? "Add at least one recipient" : "Select a sending mailbox"}
      </TooltipContent>
    )}
  </Tooltip>
</TooltipProvider>
```

### 1c. Retry on send failure

In the `catch` block of `handleSend`, use Sonner's action button:

```tsx
toast.error("Failed to send email", {
  action: { label: "Retry", onClick: () => handleSend() },
});
```

No state change needed — form data persists in component state.

### 1d. Client-side attachment size warning

In `handleAttachFiles` and `handleDrop`, after building the new file list:

```tsx
const MAX_ATTACHMENT_MB = 25;
const oversized = newFiles.filter(f => f.size > MAX_ATTACHMENT_MB * 1024 * 1024);
if (oversized.length > 0) {
  toast.warning(`${oversized.map(f => f.name).join(", ")} exceeds ${MAX_ATTACHMENT_MB}MB. May fail to send.`);
}
```

Still allow the user to add the files — the backend enforces the hard limit.

---

## Step 2: Draft Auto-Save Feedback

Replace plain text draft status in the footer with icon + text:

```tsx
import { Cloud, Loader2 } from "lucide-react";

{draftStatus === "saving" && (
  <span className="flex items-center gap-1 text-xs text-muted-foreground">
    <Loader2 className="h-3 w-3 animate-spin" />
    Saving...
  </span>
)}
{draftStatus === "saved" && (
  <span className="flex items-center gap-1 text-xs text-muted-foreground">
    <Cloud className="h-3 w-3" />
    Saved
  </span>
)}
```

No logic change. JSX-only update in the dialog footer.

---

## Step 3: TipTap Toolbar — Add Code Block

In `rich-text-editor.tsx`, add a Code Block button after the Blockquote button:

```tsx
import { Code } from "lucide-react";

<ToolbarButton
  onClick={() => editor.chain().focus().toggleCodeBlock().run()}
  isActive={editor.isActive("codeBlock")}
  title="Code block"
>
  <Code className="h-4 w-4" />
</ToolbarButton>
```

StarterKit includes `codeBlock` by default — no extension configuration needed.

Final toolbar order: Bold | Italic | Strike | [sep] | BulletList | OrderedList | Blockquote | Code | [sep] | Link | Unlink | [sep] | Undo | Redo

---

## Step 4: Cc/Bcc Toggle Label

Change the toggle button in the To row:

```tsx
<Button
  type="button"
  variant="ghost"
  size="sm"
  className="h-7 text-xs px-2 shrink-0 text-muted-foreground"
  onClick={() => setShowCcBcc(!showCcBcc)}
>
  {showCcBcc ? <X className="h-3 w-3" /> : "Cc Bcc"}
</Button>
```

From `{showCcBcc ? "Hide" : "CC/BCC"}` → `{showCcBcc ? <X /> : "Cc Bcc"}`. Matches Gmail conventions.

---

## Step 5: Scheduled Send Badge

When `scheduledDate` is set, show a dismissible strip between the attachment list and the footer so the user can see scheduling is active without opening the clock popover:

```tsx
{scheduledDate && (
  <div className="flex items-center gap-2 px-4 py-1.5 border-t bg-muted/30 text-xs text-muted-foreground shrink-0">
    <Clock className="h-3 w-3" />
    <span>
      Scheduled for {scheduledDate.toLocaleDateString()} at {scheduledTime}
    </span>
    <button
      type="button"
      onClick={() => setScheduledDate(undefined)}
      className="ml-auto hover:text-foreground"
      title="Cancel scheduled send"
    >
      <X className="h-3 w-3" />
    </button>
  </div>
)}
```

Also highlight the Clock trigger button when scheduling is active:

```tsx
<Button variant={scheduledDate ? "secondary" : "outline"} size="icon" title="...">
  <Clock className="h-4 w-4" />
</Button>
```

---

## Step 6: Signature Visual Separator

Add to `frontend/app/globals.css` (or the email-specific CSS file):

```css
.ProseMirror [data-signature="true"] {
  border-top: 1px solid hsl(var(--border));
  margin-top: 1rem;
  padding-top: 0.5rem;
  color: hsl(var(--muted-foreground));
  font-size: 0.875rem;
}
```

No TipTap extension needed. Targets the existing `data-signature="true"` attribute already on the signature div. Signature-switching when the From mailbox changes already works correctly — no logic change.

---

## Step 7: Offline Warning

Import the online hook and show a warning strip in the compose footer:

```tsx
import { useOnline } from "@/lib/use-online";

const { isOffline } = useOnline();

// In footer:
{isOffline && (
  <span className="text-xs text-yellow-600 dark:text-yellow-500 flex items-center gap-1">
    <WifiOff className="h-3 w-3" />
    Offline — cannot send
  </span>
)}
```

Also disable the Send button when `isOffline`:

```tsx
disabled={isSending || to.length === 0 || !selectedMailboxId || isOffline}
```

---

## Step 8: Collapsed Quoted Content in Replies (do last — most complex)

Currently quoted HTML is embedded directly in TipTap content in reply/forward mode. This means the user edits over/through the quoted content with no collapse option.

**Approach**: render quoted content outside TipTap as a collapsible read-only section.

### Changes to `compose-dialog.tsx`:

1. Add state: `const [showQuotedContent, setShowQuotedContent] = useState(false)`.
2. Store the quoted HTML separately: `const [quotedHtml, setQuotedHtml] = useState<string>("")`.
3. In the initialization `useEffect` for reply/forward mode, instead of appending quoted HTML to `bodyHtml`, set it to `quotedHtml` state only. Set `bodyHtml` to just the signature and any pre-filled reply header (e.g., "On [date], [sender] wrote:").
4. In `handleSend`, before building the payload, append `quotedHtml` to `bodyHtml` so the full thread context is preserved when sent.
5. Add collapsible JSX below the editor area:

```tsx
{quotedHtml && (
  <div className="border-t px-4 py-2 shrink-0">
    <button
      type="button"
      onClick={() => setShowQuotedContent(!showQuotedContent)}
      className="text-xs text-muted-foreground hover:text-foreground flex items-center gap-1"
    >
      <ChevronDown className={cn("h-3 w-3 transition-transform", showQuotedContent && "rotate-180")} />
      {showQuotedContent ? "Hide quoted" : "Show quoted"}
    </button>
    {showQuotedContent && (
      <div
        className="prose prose-sm dark:prose-invert mt-2 max-h-48 overflow-y-auto text-muted-foreground"
        dangerouslySetInnerHTML={{ __html: quotedHtml }}
      />
    )}
  </div>
)}
```

**Note**: The quoted HTML is already sanitized by `sanitizeEmailHtml` before being set in the original code — reuse the same sanitization when extracting `quotedHtml`.

---

## Backend Changes Required

None. All items above are frontend-only. The backend `EmailController` `send` endpoint already validates recipients server-side. Subject is optional in SMTP and the backend accepts empty subjects.

---

## Implementation Order

| Priority | Step | Risk |
|----------|------|------|
| 1 | Step 1 — validation & error states | Low |
| 2 | Step 2 — draft status icons | Low |
| 3 | Step 4 — Cc/Bcc label | Low |
| 4 | Step 5 — schedule badge | Low |
| 5 | Step 3 — Code Block toolbar | Low (isolated file) |
| 6 | Step 6 — signature CSS | Low (one CSS rule) |
| 7 | Step 7 — offline warning | Low |
| 8 | Step 8 — collapsed quotes | Medium (refactor send flow) |

---

## Checklist of File Changes

| File | Change |
|------|--------|
| `compose-dialog.tsx` | `showNoSubjectDialog` state + AlertDialog; Send tooltip; retry toast; attachment size warning; draft status icons; Cc/Bcc label; schedule badge + Clock highlight; offline warning + disable; `quotedHtml` split for replies |
| `rich-text-editor.tsx` | Add Code Block toolbar button |
| `frontend/app/globals.css` | `.ProseMirror [data-signature="true"]` CSS rule |
