"use client";

import { useState, useEffect, useRef, useCallback, useMemo } from "react";
import { toast } from "sonner";
import { useMailData } from "@/lib/mail-data-provider";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { EmptyState } from "@/components/ui/empty-state";
import { Upload, FileUp, CheckCircle, AlertCircle, Loader2, MailWarning } from "lucide-react";
import { getMailboxAddress } from "@/lib/mail-types";

interface ImportResult {
  imported: number;
  skipped: number;
  failed: number;
  errors: string[];
}

export default function ImportEmailsPage() {
  const { accessibleMailboxes } = useMailData();
  const importableMailboxes = useMemo(
    () => accessibleMailboxes.filter((m) => m.address !== "*"),
    [accessibleMailboxes]
  );

  const [importMailboxId, setImportMailboxId] = useState<string>("");
  const [importFormat, setImportFormat] = useState<string>("mbox");
  const [importFile, setImportFile] = useState<File | null>(null);
  const [isImporting, setIsImporting] = useState(false);
  const [importResult, setImportResult] = useState<ImportResult | null>(null);
  const [importAsyncJobId, setImportAsyncJobId] = useState<string | null>(null);
  const [importAsyncStatus, setImportAsyncStatus] = useState<string | null>(null);
  const importFileInputRef = useRef<HTMLInputElement>(null);
  const importPollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  // Set default mailbox when mailboxes load
  useEffect(() => {
    if (importableMailboxes.length > 0 && !importMailboxId) {
      setImportMailboxId(importableMailboxes[0].id.toString());
    }
  }, [importableMailboxes, importMailboxId]);

  // Poll async import job status
  useEffect(() => {
    if (!importAsyncJobId) return;
    const controller = new AbortController();
    const poll = async () => {
      try {
        const res = await api.get<{ status: string; result?: ImportResult }>(
          `/email/import/${importAsyncJobId}/status`,
          { signal: controller.signal }
        );
        if (controller.signal.aborted) return;
        setImportAsyncStatus(res.data.status);
        if (res.data.status === "completed" || res.data.status === "failed") {
          if (importPollRef.current) {
            clearInterval(importPollRef.current);
            importPollRef.current = null;
          }
          setIsImporting(false);
          if (res.data.result) setImportResult(res.data.result);
        }
      } catch {
        // ignore poll errors (including aborted requests)
      }
    };
    poll();
    importPollRef.current = setInterval(poll, 3000);
    return () => {
      controller.abort();
      if (importPollRef.current) {
        clearInterval(importPollRef.current);
        importPollRef.current = null;
      }
    };
  }, [importAsyncJobId]);

  const handleImportFileChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    const selected = e.target.files?.[0];
    if (!selected) return;
    const MAX_IMPORT_MB = 100;
    if (selected.size > MAX_IMPORT_MB * 1024 * 1024) {
      toast.error(`File is too large. Maximum size is ${MAX_IMPORT_MB}MB.`);
      e.target.value = '';
      return;
    }
    setImportFile(selected);
    setImportResult(null);
    const ext = selected.name.split(".").pop()?.toLowerCase();
    if (ext === "eml") setImportFormat("eml");
    else if (ext === "mbox" || ext === "mbx") setImportFormat("mbox");
  }, []);

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
      } else {
        setIsImporting(false);
        toast.error("Unexpected response from server. Please try again.");
      }
    } catch {
      toast.error("Failed to import emails");
      setIsImporting(false);
    }
  };

  if (importableMailboxes.length === 0) {
    return (
      <div className="space-y-6">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">Import Emails</h2>
          <p className="text-muted-foreground">
            Upload mbox or eml files to import emails into your mailboxes.
          </p>
        </div>
        <Card>
          <CardContent className="py-12">
            <EmptyState
              icon={MailWarning}
              title="No mailboxes available"
              description="You need at least one mailbox to import emails into. Contact your administrator to set up a mailbox."
            />
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-2xl font-bold tracking-tight">Import Emails</h2>
        <p className="text-muted-foreground">
          Upload an mbox or eml file to import emails into one of your mailboxes.
          Files over 10MB are processed in the background.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Upload File</CardTitle>
          <CardDescription>
            Select a destination mailbox and file format, then choose your file.
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
                  {importableMailboxes.map((mb) => (
                    <SelectItem key={mb.id} value={mb.id.toString()}>
                      {mb.display_name
                        ? `${mb.display_name} <${getMailboxAddress(mb)}>`
                        : getMailboxAddress(mb)}
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
              aria-label="Select email file to import"
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
                  if (importFileInputRef.current) importFileInputRef.current.value = '';
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
    </div>
  );
}
