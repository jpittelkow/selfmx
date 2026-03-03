# Email Import: Move from Sidebar to User Profile — Implementation Plan

**Goal**: Remove the Import button from the sidebar and add an inline "Import Emails" card to the user profile settings page. Backend API is unchanged. Frontend-only.

**Files changed**:
- `frontend/components/sidebar.tsx` — remove button + dialog
- `frontend/app/(dashboard)/user/profile/page.tsx` — add import card

**File kept but not changed**:
- `frontend/components/mail/email-import-dialog.tsx` — preserved as-is for future reuse

---

## Current State

### What lives in `sidebar.tsx`

In `MailNavSectionInner` (expanded state):

```tsx
{/* Compose + Import */}
<div className="px-2 mb-3 flex gap-1">
  <Button className="flex-1 ..." onClick={() => { openCompose(); onNavClick?.(); }}>
    <PenLine className="h-4 w-4" />
    Compose
  </Button>
  {accessibleMailboxes.length > 0 && (
    <Button variant="outline" size="icon" title="Import emails" onClick={() => setShowImport(true)}>
      <Upload className="h-4 w-4" />
    </Button>
  )}
</div>
```

And near the bottom of the expanded nav:

```tsx
{accessibleMailboxes.length > 0 && (
  <EmailImportDialog
    open={showImport}
    onOpenChange={setShowImport}
    mailboxes={accessibleMailboxes.map(m => ({ ... }))}
    defaultMailboxId={activeMailboxId ?? accessibleMailboxes[0]?.id}
  />
)}
```

### Target: user profile page

`frontend/app/(dashboard)/user/profile/page.tsx` currently has:
1. Profile Information card (name, email)
2. Group Memberships card
3. Danger Zone card (delete account)

The Import Emails card goes between Group Memberships and Danger Zone (keeps destructive actions last).

The profile page is accessible to all authenticated users via the user dropdown menu — appropriate since import is a user-level action, not an admin-only one.

### Decision: inline the form, don't reuse `EmailImportDialog`

The dialog component wraps the form in modal chrome (`Dialog`, `DialogContent`, `open/onOpenChange`). On a settings page the correct pattern is an inline `Card`. The logic is extracted and inlined — the original dialog file remains intact.

---

## Step 1: Remove from `sidebar.tsx`

### a. Remove `showImport` state

```tsx
// DELETE this line:
const [showImport, setShowImport] = useState(false);
```

### b. Replace Compose + Import button group with Compose-only

```tsx
// BEFORE:
<div className="px-2 mb-3 flex gap-1">
  <Button className="flex-1 justify-center gap-2" onClick={() => { openCompose(); onNavClick?.(); }}>
    <PenLine className="h-4 w-4" />
    Compose
  </Button>
  {accessibleMailboxes.length > 0 && (
    <Button variant="outline" size="icon" className="shrink-0" title="Import emails" onClick={() => setShowImport(true)}>
      <Upload className="h-4 w-4" />
    </Button>
  )}
</div>

// AFTER:
<div className="px-2 mb-3">
  <Button className="w-full justify-center gap-2" onClick={() => { openCompose(); onNavClick?.(); }}>
    <PenLine className="h-4 w-4" />
    Compose
  </Button>
</div>
```

### c. Remove the `EmailImportDialog` render block

Delete the entire block:
```tsx
// DELETE:
{accessibleMailboxes.length > 0 && (
  <EmailImportDialog
    open={showImport}
    onOpenChange={setShowImport}
    mailboxes={accessibleMailboxes.map(m => ({
      id: m.id,
      address: m.address,
      display_name: m.display_name ?? null,
      email_domain: { name: m.email_domain.name },
    }))}
    defaultMailboxId={activeMailboxId ?? accessibleMailboxes[0]?.id}
  />
)}
```

### d. Remove imports

From the lucide import at the top:
```tsx
// Remove "Upload" from the import list
```

From the component imports:
```tsx
// DELETE:
import { EmailImportDialog } from "@/components/mail/email-import-dialog";
```

---

## Step 2: Add Import Emails card to `user/profile/page.tsx`

### a. New imports

```tsx
import { useRef, useCallback } from "react"; // useEffect, useState already present
import { useMailData } from "@/lib/mail-data-provider";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Label } from "@/components/ui/label";
import { Upload, FileUp, CheckCircle, AlertCircle } from "lucide-react";
// Loader2 likely already imported; confirm
```

### b. `ImportResult` type (add near top of file)

```tsx
interface ImportResult {
  imported: number;
  skipped: number;
  failed: number;
  errors: string[];
}
```

### c. Hook and state (inside `ProfilePage` component, alongside existing state)

```tsx
const { accessibleMailboxes } = useMailData();

// Import state
const [importMailboxId, setImportMailboxId] = useState<string>("");
const [importFormat, setImportFormat] = useState<string>("mbox");
const [importFile, setImportFile] = useState<File | null>(null);
const [isImporting, setIsImporting] = useState(false);
const [importResult, setImportResult] = useState<ImportResult | null>(null);
const [importAsyncJobId, setImportAsyncJobId] = useState<string | null>(null);
const [importAsyncStatus, setImportAsyncStatus] = useState<string | null>(null);
const importFileInputRef = useRef<HTMLInputElement>(null);
const importPollRef = useRef<ReturnType<typeof setInterval> | null>(null);
```

### d. Set default mailbox when mailboxes load

```tsx
useEffect(() => {
  if (accessibleMailboxes.length > 0 && !importMailboxId) {
    setImportMailboxId(accessibleMailboxes[0].id.toString());
  }
}, [accessibleMailboxes, importMailboxId]);
```

### e. File change handler (auto-detect format from extension)

```tsx
const handleImportFileChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
  const selected = e.target.files?.[0];
  if (!selected) return;
  setImportFile(selected);
  setImportResult(null);
  const ext = selected.name.split(".").pop()?.toLowerCase();
  if (ext === "eml") setImportFormat("eml");
  else if (ext === "mbox" || ext === "mbx") setImportFormat("mbox");
}, []);
```

### f. Import submit handler

```tsx
const handleImport = async () => {
  if (!importFile || !importMailboxId) return;
  setIsImporting(true);
  setImportResult(null);
  setImportAsyncJobId(null);
  setImportAsyncStatus(null);

  try {
    const formData = new FormData();
    formData.append("file", importFile);
    formData.append("mailbox_id", importMailboxId);
    formData.append("format", importFormat);

    const res = await api.post<{ status: string; job_id?: string; result?: ImportResult }>(
      "/email/import",
      formData,
      { headers: { "Content-Type": "multipart/form-data" }, timeout: 120000 }
    );

    if (res.data.status === "completed" && res.data.result) {
      setImportResult(res.data.result);
      setIsImporting(false);
      toast.success(`Import complete: ${res.data.result.imported} emails imported`);
    } else if (res.data.status === "queued" && res.data.job_id) {
      setImportAsyncJobId(res.data.job_id);
      setImportAsyncStatus("queued");
      toast.info("Large file queued for processing. Status will update automatically.");
    }
  } catch {
    toast.error("Failed to import emails");
    setIsImporting(false);
  }
};
```

### g. Async job polling effect

```tsx
useEffect(() => {
  if (!importAsyncJobId) return;
  const poll = async () => {
    try {
      const res = await api.get<{ status: string; result?: ImportResult }>(
        `/email/import/${importAsyncJobId}/status`
      );
      setImportAsyncStatus(res.data.status);
      if (res.data.status === "completed" || res.data.status === "failed") {
        if (importPollRef.current) {
          clearInterval(importPollRef.current);
          importPollRef.current = null;
        }
        setIsImporting(false);
        if (res.data.result) setImportResult(res.data.result);
      }
    } catch { /* ignore poll errors */ }
  };
  importPollRef.current = setInterval(poll, 3000);
  return () => {
    if (importPollRef.current) {
      clearInterval(importPollRef.current);
      importPollRef.current = null;
    }
  };
}, [importAsyncJobId]);
```

### h. Import Emails card JSX

Insert this card in the return JSX **between** the Group Memberships card and the Danger Zone card:

```tsx
{accessibleMailboxes.length > 0 && (
  <Card>
    <CardHeader>
      <CardTitle>Import Emails</CardTitle>
      <CardDescription>
        Upload an mbox or eml file to import emails into one of your mailboxes.
        Files over 10MB are processed in the background.
      </CardDescription>
    </CardHeader>
    <CardContent className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-2">
          <Label>Destination Mailbox</Label>
          <Select value={importMailboxId} onValueChange={setImportMailboxId}>
            <SelectTrigger>
              <SelectValue placeholder="Select a mailbox" />
            </SelectTrigger>
            <SelectContent>
              {accessibleMailboxes.map((mb) => (
                <SelectItem key={mb.id} value={mb.id.toString()}>
                  {mb.display_name
                    ? `${mb.display_name} <${mb.address}@${mb.email_domain.name}>`
                    : `${mb.address}@${mb.email_domain.name}`}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-2">
          <Label>Format</Label>
          <Select value={importFormat} onValueChange={setImportFormat}>
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="mbox">mbox (multiple emails)</SelectItem>
              <SelectItem value="eml">eml (single email)</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="space-y-2">
        <Label>File</Label>
        <input
          ref={importFileInputRef}
          type="file"
          accept=".mbox,.mbx,.eml"
          onChange={handleImportFileChange}
          className="hidden"
        />
        <div
          className="flex cursor-pointer flex-col items-center justify-center rounded-md border-2 border-dashed border-muted-foreground/25 p-6 transition hover:border-muted-foreground/50"
          onClick={() => importFileInputRef.current?.click()}
        >
          {importFile ? (
            <>
              <FileUp className="mb-2 h-8 w-8 text-muted-foreground" />
              <p className="text-sm font-medium">{importFile.name}</p>
              <p className="text-xs text-muted-foreground">
                {(importFile.size / 1024 / 1024).toFixed(1)} MB
              </p>
            </>
          ) : (
            <>
              <Upload className="mb-2 h-8 w-8 text-muted-foreground" />
              <p className="text-sm text-muted-foreground">Click to select a file</p>
              <p className="text-xs text-muted-foreground">.mbox, .mbx, or .eml up to 100MB</p>
            </>
          )}
        </div>
      </div>

      {(isImporting || importAsyncStatus) && !importResult && (
        <div className="flex items-center gap-2 rounded-md bg-muted/50 p-3">
          <Loader2 className="h-4 w-4 animate-spin" />
          <span className="text-sm">
            {importAsyncStatus === "queued" && "Queued for processing..."}
            {importAsyncStatus === "processing" && "Importing emails..."}
            {!importAsyncStatus && "Importing..."}
          </span>
        </div>
      )}

      {importResult && (
        <div className="space-y-2 rounded-md border p-3">
          <div className="flex items-center gap-2">
            {importResult.failed === 0 ? (
              <CheckCircle className="h-4 w-4 text-green-600" />
            ) : (
              <AlertCircle className="h-4 w-4 text-yellow-600" />
            )}
            <span className="text-sm font-medium">Import complete</span>
          </div>
          <div className="grid grid-cols-3 gap-2 text-center text-sm">
            <div>
              <p className="text-lg font-bold">{importResult.imported}</p>
              <p className="text-xs text-muted-foreground">Imported</p>
            </div>
            <div>
              <p className="text-lg font-bold">{importResult.skipped}</p>
              <p className="text-xs text-muted-foreground">Skipped</p>
            </div>
            <div>
              <p className="text-lg font-bold">{importResult.failed}</p>
              <p className="text-xs text-muted-foreground">Failed</p>
            </div>
          </div>
          {importResult.errors.length > 0 && (
            <div className="mt-2 max-h-20 overflow-y-auto text-xs text-destructive">
              {importResult.errors.map((err, i) => (
                <p key={i}>{err}</p>
              ))}
            </div>
          )}
          <Button
            variant="outline"
            size="sm"
            className="mt-2"
            onClick={() => {
              setImportFile(null);
              setImportResult(null);
              setImportAsyncJobId(null);
              setImportAsyncStatus(null);
            }}
          >
            Import another file
          </Button>
        </div>
      )}
    </CardContent>
    {!importResult && (
      <CardFooter className="flex justify-end">
        <Button
          onClick={handleImport}
          disabled={isImporting || !importFile || !importMailboxId}
        >
          {isImporting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
          Import
        </Button>
      </CardFooter>
    )}
  </Card>
)}
```

---

## Step 3: No Config Nav Changes Needed

The profile page lives under `/user/` and is reached via the user dropdown in the app header — not through the `/configuration/` sidebar. No entries in `configuration/layout.tsx` `navigationGroups` are needed.

---

## Implementation Order

1. Edit `sidebar.tsx`: remove Upload button, `showImport` state, `EmailImportDialog` block, and their imports.
2. Edit `user/profile/page.tsx`: add `useMailData` import, `ImportResult` interface, state + refs, handlers, polling effect, and Import Emails card JSX.
3. Lint check: confirm `Upload` icon is not referenced elsewhere in `sidebar.tsx` after removal.
4. Manual test: visit `/user/profile`, confirm card appears for users with mailbox access, confirm file selection auto-detects format, confirm small file imports synchronously, confirm large file shows queued state and polls.

---

## Files Not Touched

| File | Reason |
|------|--------|
| `frontend/components/mail/email-import-dialog.tsx` | Kept intact as a reusable modal for potential future use |
| `backend/app/Http/Controllers/Api/EmailImportController.php` | No backend changes |
| `backend/routes/api.php` | No route changes |
