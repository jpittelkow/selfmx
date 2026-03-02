"use client";

import { useState, useRef, useCallback, useEffect } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Upload, Loader2, CheckCircle, AlertCircle, FileUp } from "lucide-react";

interface Mailbox {
  id: number;
  address: string;
  display_name: string | null;
  email_domain: { name: string } | null;
}

interface ImportResult {
  imported: number;
  skipped: number;
  failed: number;
  errors: string[];
}

interface EmailImportDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  mailboxes: Mailbox[];
  defaultMailboxId?: number;
  onImportComplete?: () => void;
}

export function EmailImportDialog({
  open,
  onOpenChange,
  mailboxes,
  defaultMailboxId,
  onImportComplete,
}: EmailImportDialogProps) {
  const [mailboxId, setMailboxId] = useState<string>(
    defaultMailboxId?.toString() ?? mailboxes[0]?.id?.toString() ?? ""
  );
  const [format, setFormat] = useState<string>("mbox");
  const [file, setFile] = useState<File | null>(null);
  const [isImporting, setIsImporting] = useState(false);
  const [result, setResult] = useState<ImportResult | null>(null);
  const [asyncJobId, setAsyncJobId] = useState<string | null>(null);
  const [asyncStatus, setAsyncStatus] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  // Reset state when dialog opens/closes
  useEffect(() => {
    if (open) {
      setFile(null);
      setResult(null);
      setAsyncJobId(null);
      setAsyncStatus(null);
      setIsImporting(false);
      if (defaultMailboxId) {
        setMailboxId(defaultMailboxId.toString());
      }
    }
    return () => {
      if (pollRef.current) {
        clearInterval(pollRef.current);
        pollRef.current = null;
      }
    };
  }, [open, defaultMailboxId]);

  // Poll for async job status
  useEffect(() => {
    if (!asyncJobId) return;

    const poll = async () => {
      try {
        const res = await api.get<{ status: string; result?: ImportResult }>(
          `/email/import/${asyncJobId}/status`
        );
        setAsyncStatus(res.data.status);

        if (res.data.status === "completed" || res.data.status === "failed") {
          if (pollRef.current) {
            clearInterval(pollRef.current);
            pollRef.current = null;
          }
          setIsImporting(false);
          if (res.data.result) {
            setResult(res.data.result);
          }
          if (res.data.status === "completed") {
            onImportComplete?.();
          }
        }
      } catch {
        // Ignore poll errors
      }
    };

    pollRef.current = setInterval(poll, 3000);
    return () => {
      if (pollRef.current) {
        clearInterval(pollRef.current);
        pollRef.current = null;
      }
    };
  }, [asyncJobId, onImportComplete]);

  const handleFileChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    const selected = e.target.files?.[0];
    if (!selected) return;
    setFile(selected);
    setResult(null);

    // Auto-detect format from extension
    const ext = selected.name.split(".").pop()?.toLowerCase();
    if (ext === "eml") {
      setFormat("eml");
    } else if (ext === "mbox" || ext === "mbx") {
      setFormat("mbox");
    }
  }, []);

  const handleImport = async () => {
    if (!file || !mailboxId) return;

    setIsImporting(true);
    setResult(null);
    setAsyncJobId(null);
    setAsyncStatus(null);

    try {
      const formData = new FormData();
      formData.append("file", file);
      formData.append("mailbox_id", mailboxId);
      formData.append("format", format);

      const res = await api.post<{
        status: string;
        job_id?: string;
        result?: ImportResult;
      }>("/email/import", formData, {
        headers: { "Content-Type": "multipart/form-data" },
        timeout: 120000,
      });

      if (res.data.status === "completed" && res.data.result) {
        setResult(res.data.result);
        setIsImporting(false);
        toast.success(`Import complete: ${res.data.result.imported} emails imported`);
        onImportComplete?.();
      } else if (res.data.status === "queued" && res.data.job_id) {
        setAsyncJobId(res.data.job_id);
        setAsyncStatus("queued");
        toast.info("Large file queued for processing. Status will update automatically.");
      }
    } catch {
      toast.error("Failed to import emails");
      setIsImporting(false);
    }
  };

  const selectedMailbox = mailboxes.find((m) => m.id.toString() === mailboxId);
  const mailboxLabel = selectedMailbox
    ? `${selectedMailbox.address}@${selectedMailbox.email_domain?.name ?? ""}`
    : "";

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Import Emails</DialogTitle>
          <DialogDescription>
            Upload an mbox or eml file to import emails into a mailbox.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-2">
            <Label>Mailbox</Label>
            <Select value={mailboxId} onValueChange={setMailboxId}>
              <SelectTrigger>
                <SelectValue placeholder="Select a mailbox" />
              </SelectTrigger>
              <SelectContent>
                {mailboxes.map((mb) => (
                  <SelectItem key={mb.id} value={mb.id.toString()}>
                    {mb.display_name ?? mb.address}@{mb.email_domain?.name ?? ""}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label>Format</Label>
            <Select value={format} onValueChange={setFormat}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="mbox">mbox (multiple emails)</SelectItem>
                <SelectItem value="eml">eml (single email)</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label>File</Label>
            <input
              ref={fileInputRef}
              type="file"
              accept=".mbox,.mbx,.eml"
              onChange={handleFileChange}
              className="hidden"
            />
            <div
              className="flex cursor-pointer flex-col items-center justify-center rounded-md border-2 border-dashed border-muted-foreground/25 p-6 transition hover:border-muted-foreground/50"
              onClick={() => fileInputRef.current?.click()}
            >
              {file ? (
                <>
                  <FileUp className="mb-2 h-8 w-8 text-muted-foreground" />
                  <p className="text-sm font-medium">{file.name}</p>
                  <p className="text-xs text-muted-foreground">
                    {(file.size / 1024 / 1024).toFixed(1)} MB
                  </p>
                </>
              ) : (
                <>
                  <Upload className="mb-2 h-8 w-8 text-muted-foreground" />
                  <p className="text-sm text-muted-foreground">
                    Click to select a file
                  </p>
                  <p className="text-xs text-muted-foreground">
                    .mbox, .mbx, or .eml up to 100MB
                  </p>
                </>
              )}
            </div>
          </div>

          {(isImporting || asyncStatus) && !result && (
            <div className="flex items-center gap-2 rounded-md bg-muted/50 p-3">
              <Loader2 className="h-4 w-4 animate-spin" />
              <span className="text-sm">
                {asyncStatus === "queued" && "Queued for processing..."}
                {asyncStatus === "processing" && "Importing emails..."}
                {!asyncStatus && "Importing..."}
              </span>
            </div>
          )}

          {result && (
            <div className="space-y-2 rounded-md border p-3">
              <div className="flex items-center gap-2">
                {result.failed === 0 ? (
                  <CheckCircle className="h-4 w-4 text-green-600" />
                ) : (
                  <AlertCircle className="h-4 w-4 text-yellow-600" />
                )}
                <span className="text-sm font-medium">Import Complete</span>
              </div>
              <div className="grid grid-cols-3 gap-2 text-center text-sm">
                <div>
                  <p className="text-lg font-bold">{result.imported}</p>
                  <p className="text-xs text-muted-foreground">Imported</p>
                </div>
                <div>
                  <p className="text-lg font-bold">{result.skipped}</p>
                  <p className="text-xs text-muted-foreground">Skipped</p>
                </div>
                <div>
                  <p className="text-lg font-bold">{result.failed}</p>
                  <p className="text-xs text-muted-foreground">Failed</p>
                </div>
              </div>
              {result.errors.length > 0 && (
                <div className="mt-2 max-h-20 overflow-y-auto text-xs text-destructive">
                  {result.errors.map((err, i) => (
                    <p key={i}>{err}</p>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {result ? "Close" : "Cancel"}
          </Button>
          {!result && (
            <Button
              onClick={handleImport}
              disabled={isImporting || !file || !mailboxId}
            >
              {isImporting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Import
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
