"use client";

import { useState, useEffect, useRef, useCallback } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { RecipientInput, type SuppressionWarning } from "./recipient-input";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { Calendar } from "@/components/ui/calendar";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Loader2, Send, Paperclip, X, Clock, Cloud, WifiOff, ChevronDown, PenLine, Check } from "lucide-react";
import { RichTextEditor } from "./rich-text-editor";
import { sanitizeEmailHtml } from "@/lib/sanitize";
import { useOnline } from "@/lib/use-online";
import { cn } from "@/lib/utils";
import { getMailboxAddress } from "@/lib/mail-types";

const MAX_ATTACHMENT_MB = 25;

function escapeHtml(text: string): string {
  return text
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

interface EmailSignature {
  id: number;
  name: string;
  body: string;
  is_default: boolean;
}

interface Mailbox {
  id: number;
  address: string;
  display_name: string | null;
  signature: string | null;
  default_signature_id: number | null;
  domain_name?: string | null;
  email_domain: { name: string } | null;
  email_domain_id: number | null;
  user_role?: string;
}

export interface ReplyData {
  to: string[];
  cc: string[];
  subject: string;
  in_reply_to: string;
  references: string;
  thread_id: number;
  quoted_html: string;
  quoted_text: string;
  mailbox_id: number;
  original_attachments?: Array<{ id: number; filename: string; size: number; mime_type: string }>;
  prefill_body?: string;
}

interface ComposeDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSent: () => void;
  mode?: "compose" | "reply" | "reply_all" | "forward";
  replyData?: ReplyData | null;
}

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function ComposeDialog({
  open,
  onOpenChange,
  onSent,
  mode = "compose",
  replyData = null,
}: ComposeDialogProps) {
  const { isOffline } = useOnline();

  const [mailboxes, setMailboxes] = useState<Mailbox[]>([]);
  const [signatures, setSignatures] = useState<EmailSignature[]>([]);
  const [selectedMailboxId, setSelectedMailboxId] = useState<string>("");
  const [selectedSignatureId, setSelectedSignatureId] = useState<string>("");
  const [to, setTo] = useState<string[]>([]);
  const [cc, setCc] = useState<string[]>([]);
  const [bcc, setBcc] = useState<string[]>([]);
  const [showCcBcc, setShowCcBcc] = useState(false);
  const [subject, setSubject] = useState("");
  const [suppressionWarnings, setSuppressionWarnings] = useState<Record<string, SuppressionWarning | null>>({});
  const [bodyHtml, setBodyHtml] = useState("");
  const [bodyText, setBodyText] = useState("");
  const [quotedHtml, setQuotedHtml] = useState("");
  const [showQuotedContent, setShowQuotedContent] = useState(false);
  const [isSending, setIsSending] = useState(false);
  const [showNoSubjectDialog, setShowNoSubjectDialog] = useState(false);
  const [scheduledDate, setScheduledDate] = useState<Date | undefined>(undefined);
  const [scheduledTime, setScheduledTime] = useState("09:00");
  const [showSchedulePicker, setShowSchedulePicker] = useState(false);
  const [attachments, setAttachments] = useState<File[]>([]);
  const [draftId, setDraftId] = useState<number | null>(null);
  const [draftStatus, setDraftStatus] = useState<"" | "saving" | "saved">("");
  const [inReplyTo, setInReplyTo] = useState<string | null>(null);
  const [references, setReferences] = useState<string | null>(null);
  const [threadId, setThreadId] = useState<number | null>(null);

  const fileInputRef = useRef<HTMLInputElement>(null);
  const draftTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const hasEditedRef = useRef(false);
  const initializedRef = useRef(false);
  // Stable ref so the Sonner retry action always calls the latest executeSend closure
  const executeSendRef = useRef<() => Promise<void>>(() => Promise.resolve());
  // Ref guard to prevent concurrent sends (state updates are async so useState alone is insufficient)
  const isSendingRef = useRef(false);
  const dragCounterRef = useRef(0);

  // Fetch mailboxes and signatures when dialog opens
  useEffect(() => {
    if (!open) return;
    let cancelled = false;
    initializedRef.current = false;

    // Fetch signatures in parallel
    api.get<{ signatures: EmailSignature[] }>("/email/signatures")
      .then((res) => {
        if (!cancelled) setSignatures(res.data.signatures);
      })
      .catch(() => {});

    api
      .get<{ mailboxes: Mailbox[] }>("/email/mailboxes")
      .then((res) => {
        if (cancelled) return;
        const filtered = res.data.mailboxes.filter(
          (m) => m.address !== "*" && m.user_role !== "viewer"
        );
        setMailboxes(filtered);

        // Set mailbox from reply data, active mailbox, or default to first.
        // Use a local variable to avoid reading stale state after setSelectedMailboxId.
        let chosen = "";
        if (replyData?.mailbox_id) {
          const replyMailbox = filtered.find((m) => m.id === replyData.mailbox_id);
          if (replyMailbox) chosen = replyMailbox.id.toString();
        }

        if (!chosen && (!selectedMailboxId || !filtered.find((m) => m.id.toString() === selectedMailboxId))) {
          // Try activeMailboxId from localStorage as a fallback default
          const storedActiveId = localStorage.getItem("selfmx_active_mailbox_id");
          const activeMatch = storedActiveId
            ? filtered.find((m) => m.id.toString() === storedActiveId)
            : null;

          if (activeMatch) {
            chosen = activeMatch.id.toString();
          } else if (filtered.length === 1) {
            chosen = filtered[0].id.toString();
          }
        }

        if (chosen) setSelectedMailboxId(chosen);
      })
      .catch(() => {
        if (!cancelled) toast.error("Failed to load mailboxes");
      });
    return () => { cancelled = true; };
  }, [open, replyData?.mailbox_id]);

  // Initialize form with reply/forward data
  useEffect(() => {
    if (!open || initializedRef.current) return;
    initializedRef.current = true;

    if (replyData && mode !== "compose") {
      setTo(replyData.to);
      setCc(replyData.cc);
      setShowCcBcc(replyData.cc.length > 0);
      setSubject(replyData.subject);
      setInReplyTo(replyData.in_reply_to);
      setReferences(replyData.references);
      setThreadId(replyData.thread_id);

      // Prefill body (e.g. smart reply suggestion) goes into the editor
      const escapeHtml = (text: string) =>
        text
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;")
          .replace(/\n/g, "<br>");
      const prefill = replyData.prefill_body
        ? `<p>${escapeHtml(replyData.prefill_body)}</p><br>`
        : "";
      // Store quoted content separately — keep editor clean
      setBodyHtml(prefill);
      setQuotedHtml(sanitizeEmailHtml(replyData.quoted_html));
      setShowQuotedContent(false);
    } else {
      // Reset for new compose
      setTo([]);
      setCc([]);
      setBcc([]);
      setShowCcBcc(false);
      setSubject("");
      setBodyHtml("");
      setBodyText("");
      setQuotedHtml("");
      setShowQuotedContent(false);
      setInReplyTo(null);
      setReferences(null);
      setThreadId(null);
    }
    setAttachments([]);
    setDraftId(null);
    setDraftStatus("");
    hasEditedRef.current = false;
  }, [open, mode, replyData]);

  // Resolve signature when mailbox changes: mailbox default → user default → none
  useEffect(() => {
    if (!selectedMailboxId || !open || signatures.length === 0) return;
    const mailbox = mailboxes.find((m) => m.id.toString() === selectedMailboxId);

    // Priority: mailbox default_signature_id → user default (is_default) → none
    let resolved: EmailSignature | undefined;
    if (mailbox?.default_signature_id) {
      resolved = signatures.find((s) => s.id === mailbox.default_signature_id);
    }
    if (!resolved) {
      resolved = signatures.find((s) => s.is_default);
    }

    setSelectedSignatureId(resolved ? resolved.id.toString() : "");
  }, [selectedMailboxId, mailboxes, signatures, open]);

  // Insert/replace signature in body when selection changes
  useEffect(() => {
    if (!open) return;
    const sig = signatures.find((s) => s.id.toString() === selectedSignatureId);

    setBodyHtml((prev) => {
      // Remove existing signature
      const withoutSig = prev.replace(/(<br>)*<div data-signature="true">--<br>[\s\S]*<\/div>$/, "");
      if (!sig) return withoutSig;
      const sigHtml = `<br><br><div data-signature="true">--<br>${escapeHtml(sig.body).replace(/\n/g, "<br>")}</div>`;
      return withoutSig + sigHtml;
    });
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedSignatureId, signatures, open]);

  // Check suppressions when recipients change (debounced)
  useEffect(() => {
    if (!selectedMailboxId || !open) return;
    const allRecipients = [...to, ...cc, ...bcc];
    const unchecked = allRecipients.filter((addr) => !(addr in suppressionWarnings));
    if (unchecked.length === 0) return;

    const mailbox = mailboxes.find((m) => m.id.toString() === selectedMailboxId);
    if (!mailbox?.email_domain_id) return;

    const timer = setTimeout(async () => {
      try {
        const res = await api.post<{
          results: Record<string, { suppressed: boolean; reason: string | null; detail: string | null }>;
        }>(`/email/domains/${mailbox.email_domain_id}/management/suppressions/check-batch`, {
          addresses: unchecked,
        });
        const newEntries: Record<string, SuppressionWarning | null> = {};
        for (const [addr, data] of Object.entries(res.data.results)) {
          if (data.suppressed && data.reason) {
            newEntries[addr] = { reason: data.reason, detail: data.detail };
          } else {
            newEntries[addr] = null; // Mark as checked but not suppressed
          }
        }
        if (Object.keys(newEntries).length > 0) {
          setSuppressionWarnings((prev) => ({ ...prev, ...newEntries }));
        }
      } catch {
        // Silent — suppression check is best-effort
      }
    }, 500);

    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [to, cc, bcc, selectedMailboxId, mailboxes, open]);

  // Clear suppression warnings when mailbox changes
  useEffect(() => {
    setSuppressionWarnings({});
  }, [selectedMailboxId]);

  // Draft auto-save (5s debounce)
  const scheduleDraftSave = useCallback(() => {
    if (!hasEditedRef.current) return;

    if (draftTimerRef.current) {
      clearTimeout(draftTimerRef.current);
    }

    draftTimerRef.current = setTimeout(async () => {
      if (!selectedMailboxId && to.length === 0 && !subject && !bodyHtml) return;

      setDraftStatus("saving");
      try {
        const payload = {
          mailbox_id: selectedMailboxId ? parseInt(selectedMailboxId) : null,
          to,
          cc: cc.length > 0 ? cc : [],
          bcc: bcc.length > 0 ? bcc : [],
          subject,
          body_html: bodyHtml,
          body_text: bodyText,
          in_reply_to: inReplyTo,
          references,
          thread_id: threadId,
        };

        if (draftId) {
          await api.put(`/email/messages/${draftId}/draft`, payload);
        } else {
          const res = await api.post<{ email: { id: number } }>("/email/messages/draft", payload);
          setDraftId(res.data.email.id);
        }
        setDraftStatus("saved");
      } catch {
        setDraftStatus("");
      }
    }, 5000);
  }, [selectedMailboxId, to, cc, bcc, subject, bodyHtml, bodyText, draftId, inReplyTo, references, threadId]);

  // Track edits for draft auto-save
  useEffect(() => {
    if (!open) return;
    if (!hasEditedRef.current) {
      hasEditedRef.current = true;
      return;
    }
    scheduleDraftSave();
  }, [to, cc, bcc, subject, bodyHtml, scheduleDraftSave, open]);

  // Cleanup timer
  useEffect(() => {
    return () => {
      if (draftTimerRef.current) clearTimeout(draftTimerRef.current);
    };
  }, []);

  const executeSend = useCallback(async () => {
    if (isSendingRef.current) return;
    isSendingRef.current = true;
    setIsSending(true);
    if (draftTimerRef.current) clearTimeout(draftTimerRef.current);

    // Append quoted HTML to body for sending so full thread context is preserved
    const finalBodyHtml = quotedHtml
      ? bodyHtml + `<br><div class="quoted-content">${quotedHtml}</div>`
      : bodyHtml;

    try {
      if (attachments.length > 0) {
        // Use FormData for file uploads
        const formData = new FormData();
        formData.append("mailbox_id", selectedMailboxId);
        to.forEach((addr) => formData.append("to[]", addr));
        cc.forEach((addr) => formData.append("cc[]", addr));
        bcc.forEach((addr) => formData.append("bcc[]", addr));
        formData.append("subject", subject);
        formData.append("body_html", finalBodyHtml);
        formData.append("body_text", bodyText);
        if (inReplyTo) formData.append("in_reply_to", inReplyTo);
        if (references) formData.append("references", references);
        if (threadId) formData.append("thread_id", threadId.toString());
        attachments.forEach((file) => formData.append("attachments[]", file));
        if (scheduledDate) {
          const d = new Date(scheduledDate);
          const [h, m] = scheduledTime.split(":").map(Number);
          d.setHours(h, m, 0, 0);
          formData.append("send_at", d.toISOString());
        }

        if (draftId) {
          await api.post(`/email/messages/${draftId}/send`, formData, {
            headers: { "Content-Type": "multipart/form-data" },
          });
        } else {
          await api.post("/email/messages/send", formData, {
            headers: { "Content-Type": "multipart/form-data" },
          });
        }
      } else {
        const payload: Record<string, unknown> = {
          mailbox_id: parseInt(selectedMailboxId),
          to,
          cc: cc.length > 0 ? cc : undefined,
          bcc: bcc.length > 0 ? bcc : undefined,
          subject,
          body_html: finalBodyHtml,
          body_text: bodyText,
          in_reply_to: inReplyTo || undefined,
          references: references || undefined,
          thread_id: threadId || undefined,
        };

        if (scheduledDate) {
          const d = new Date(scheduledDate);
          const [h, m] = scheduledTime.split(":").map(Number);
          d.setHours(h, m, 0, 0);
          payload.send_at = d.toISOString();
        }

        if (draftId) {
          await api.post(`/email/messages/${draftId}/send`, payload);
        } else {
          await api.post("/email/messages/send", payload);
        }
      }

      const isScheduled = !!scheduledDate;
      toast.success(isScheduled ? "Email scheduled!" : "Email sent!");
      setScheduledDate(undefined);
      onOpenChange(false);
      onSent();
    } catch {
      toast.error("Failed to send email", {
        action: {
          label: "Retry",
          // Use ref so the toast closure always calls the latest version
          onClick: () => executeSendRef.current(),
        },
      });
    } finally {
      isSendingRef.current = false;
      setIsSending(false);
    }
  }, [selectedMailboxId, to, cc, bcc, subject, bodyHtml, bodyText, quotedHtml, attachments,
      scheduledDate, scheduledTime, draftId, inReplyTo, references, threadId, onOpenChange, onSent]);

  // Keep the ref in sync with the latest executeSend
  useEffect(() => {
    executeSendRef.current = executeSend;
  }, [executeSend]);

  const handleSend = (skipSubjectCheck = false) => {
    if (isOffline) {
      toast.error("Cannot send while offline");
      return;
    }
    if (to.length === 0 || !selectedMailboxId) {
      toast.error("Please fill in the required fields");
      return;
    }
    if (!skipSubjectCheck && subject.trim() === "") {
      setShowNoSubjectDialog(true);
      return;
    }
    executeSend();
  };

  const handleClose = (isOpen: boolean) => {
    if (!isSending) {
      if (draftTimerRef.current) clearTimeout(draftTimerRef.current);
      dragCounterRef.current = 0;
      onOpenChange(isOpen);
    }
  };

  const handleAttachFiles = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = e.target.files;
    if (!files) return;
    const newFiles = Array.from(files);
    const oversized = newFiles.filter(f => f.size > MAX_ATTACHMENT_MB * 1024 * 1024);
    if (oversized.length > 0) {
      toast.warning(`${oversized.map(f => f.name).join(", ")} exceeds ${MAX_ATTACHMENT_MB}MB and may fail to send.`);
    }
    setAttachments((prev) => [...prev, ...newFiles]);
    e.target.value = "";
  };

  const removeAttachment = (index: number) => {
    setAttachments((prev) => prev.filter((_, i) => i !== index));
  };

  const dialogTitle = {
    compose: "New Message",
    reply: "Reply",
    reply_all: "Reply All",
    forward: "Forward",
  }[mode];

  const [isDragging, setIsDragging] = useState(false);

  const handleDragOver = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
  };

  const handleDragEnter = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    dragCounterRef.current++;
    setIsDragging(true);
  };

  const handleDragLeave = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    dragCounterRef.current--;
    if (dragCounterRef.current === 0) {
      setIsDragging(false);
    }
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    dragCounterRef.current = 0;
    setIsDragging(false);
    const files = Array.from(e.dataTransfer.files);
    if (files.length > 0) {
      const oversized = files.filter(f => f.size > MAX_ATTACHMENT_MB * 1024 * 1024);
      if (oversized.length > 0) {
        toast.warning(`${oversized.map(f => f.name).join(", ")} exceeds ${MAX_ATTACHMENT_MB}MB and may fail to send.`);
      }
      setAttachments((prev) => [...prev, ...files]);
    }
  };

  const isSendDisabled = isSending || to.length === 0 || !selectedMailboxId || isOffline;
  const sendDisabledReason = isOffline
    ? "You are offline"
    : to.length === 0
      ? "Add at least one recipient"
      : !selectedMailboxId
        ? "Select a sending mailbox"
        : null;

  // Filter out null entries (checked but not suppressed) for the warnings prop
  const activeWarnings = Object.fromEntries(
    Object.entries(suppressionWarnings).filter(([, v]) => v !== null)
  ) as Record<string, SuppressionWarning>;

  return (
    <>
      <Dialog open={open} onOpenChange={handleClose}>
        <DialogContent className="max-w-3xl h-[95vh] sm:h-auto sm:max-h-[90vh] flex flex-col p-0 gap-0">
          <DialogHeader className="px-4 py-3 border-b shrink-0">
            <DialogTitle className="text-base">{dialogTitle}</DialogTitle>
          </DialogHeader>

          {/* Header fields — compact Gmail-style rows */}
          <div className="shrink-0">
            {/* From row */}
            <div className="flex items-center border-b px-4 py-1.5">
              <span className="text-xs text-muted-foreground w-12 shrink-0">From</span>
              <Select value={selectedMailboxId} onValueChange={setSelectedMailboxId}>
                <SelectTrigger className="h-8 border-0 shadow-none px-1 text-sm">
                  <SelectValue placeholder="Select sending address" />
                </SelectTrigger>
                <SelectContent>
                  {mailboxes.map((m) => (
                    <SelectItem key={m.id} value={m.id.toString()}>
                      {m.display_name
                        ? `${m.display_name} <${getMailboxAddress(m)}>`
                        : getMailboxAddress(m)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* To row */}
            <div className="flex items-start border-b px-4 py-1.5 gap-2">
              <span className="text-xs text-muted-foreground w-12 shrink-0 pt-1.5">To</span>
              <div className="flex-1 min-w-0">
                <RecipientInput
                  label=""
                  value={to}
                  onChange={setTo}
                  placeholder="recipient@example.com"
                  warnings={activeWarnings}
                />
              </div>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-7 text-xs px-2 shrink-0 text-muted-foreground"
                onClick={() => setShowCcBcc(!showCcBcc)}
              >
                {showCcBcc ? <X className="h-3 w-3" /> : "Cc Bcc"}
              </Button>
            </div>

            {/* CC/BCC rows */}
            {showCcBcc && (
              <>
                <div className="flex items-start border-b px-4 py-1.5 gap-2">
                  <span className="text-xs text-muted-foreground w-12 shrink-0 pt-1.5">CC</span>
                  <div className="flex-1 min-w-0">
                    <RecipientInput
                      label=""
                      value={cc}
                      onChange={setCc}
                      placeholder="cc@example.com"
                      warnings={activeWarnings}
                    />
                  </div>
                </div>
                <div className="flex items-start border-b px-4 py-1.5 gap-2">
                  <span className="text-xs text-muted-foreground w-12 shrink-0 pt-1.5">BCC</span>
                  <div className="flex-1 min-w-0">
                    <RecipientInput
                      label=""
                      value={bcc}
                      onChange={setBcc}
                      placeholder="bcc@example.com"
                      warnings={activeWarnings}
                    />
                  </div>
                </div>
              </>
            )}

            {/* Subject row */}
            <div className="flex items-center border-b px-4 py-1.5">
              <span className="text-xs text-muted-foreground w-12 shrink-0">Subject</span>
              <Input
                placeholder="Subject"
                value={subject}
                onChange={(e) => setSubject(e.target.value)}
                className="h-8 border-0 shadow-none px-1 text-sm"
              />
            </div>
          </div>

          {/* Editor area with drag-and-drop */}
          <div
            className="flex-1 overflow-y-auto relative min-h-0"
            onDragOver={handleDragOver}
            onDragEnter={handleDragEnter}
            onDragLeave={handleDragLeave}
            onDrop={handleDrop}
          >
            {isDragging && (
              <div className="absolute inset-0 z-10 flex items-center justify-center bg-background/80 border-2 border-dashed border-primary rounded-md m-2">
                <p className="text-sm font-medium text-primary">Drop files here</p>
              </div>
            )}
            <RichTextEditor
              content={bodyHtml}
              onChange={(html, text) => {
                setBodyHtml(html);
                setBodyText(text);
              }}
              placeholder="Write your message..."
              className="border-0 rounded-none"
            />
          </div>

          {/* Quoted content (reply/forward) */}
          {quotedHtml && (
            <div className="border-t px-4 py-2 shrink-0">
              <button
                type="button"
                onClick={() => setShowQuotedContent(!showQuotedContent)}
                className="text-xs text-muted-foreground hover:text-foreground flex items-center gap-1 transition-colors"
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

          {/* Attachments */}
          {attachments.length > 0 && (
            <div className="flex flex-wrap gap-2 px-4 py-2 border-t shrink-0">
              {attachments.map((file, idx) => (
                <div
                  key={`${file.name}-${file.size}-${file.lastModified}`}
                  className="flex items-center gap-1.5 px-2 py-1 border rounded-md text-xs bg-muted/50"
                >
                  <Paperclip className="h-3 w-3 text-muted-foreground" />
                  <span className="max-w-32 truncate">{file.name}</span>
                  <span className="text-muted-foreground">{formatFileSize(file.size)}</span>
                  <button
                    type="button"
                    onClick={() => removeAttachment(idx)}
                    className="ml-1 text-muted-foreground hover:text-foreground"
                    title="Remove attachment"
                  >
                    <X className="h-3 w-3" />
                  </button>
                </div>
              ))}
            </div>
          )}

          {/* Scheduled send badge */}
          {scheduledDate && (
            <div className="flex items-center gap-2 px-4 py-1.5 border-t bg-muted/30 text-xs text-muted-foreground shrink-0">
              <Clock className="h-3 w-3" />
              <span>
                Scheduled for {scheduledDate.toLocaleDateString()} at {scheduledTime}
              </span>
              <button
                type="button"
                onClick={() => setScheduledDate(undefined)}
                className="ml-auto hover:text-foreground transition-colors"
                title="Cancel scheduled send"
              >
                <X className="h-3 w-3" />
              </button>
            </div>
          )}

          {/* Footer */}
          <div className="flex items-center justify-between px-4 py-2 border-t shrink-0">
            <div className="flex items-center gap-2">
              <input
                ref={fileInputRef}
                type="file"
                multiple
                onChange={handleAttachFiles}
                className="hidden"
                title="Select files to attach"
              />
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-8 w-8"
                onClick={() => fileInputRef.current?.click()}
                title="Attach files"
              >
                <Paperclip className="h-4 w-4" />
              </Button>
              {signatures.length > 0 && (
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="h-8 w-8"
                      title="Select signature"
                    >
                      <PenLine className="h-4 w-4" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="start">
                    <DropdownMenuItem onClick={() => setSelectedSignatureId("")}>
                      <span className="flex items-center gap-2">
                        {!selectedSignatureId && <Check className="h-3 w-3" />}
                        <span className={!selectedSignatureId ? "font-medium" : ""}>None</span>
                      </span>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    {signatures.map((sig) => (
                      <DropdownMenuItem key={sig.id} onClick={() => setSelectedSignatureId(sig.id.toString())}>
                        <span className="flex items-center gap-2">
                          {selectedSignatureId === sig.id.toString() && <Check className="h-3 w-3" />}
                          <span className={selectedSignatureId === sig.id.toString() ? "font-medium" : ""}>{sig.name}</span>
                        </span>
                      </DropdownMenuItem>
                    ))}
                  </DropdownMenuContent>
                </DropdownMenu>
              )}
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
              {isOffline && (
                <span className="flex items-center gap-1 text-xs text-yellow-600 dark:text-yellow-500">
                  <WifiOff className="h-3 w-3" />
                  Offline
                </span>
              )}
            </div>
            <div className="flex items-center gap-1">
              <Popover open={showSchedulePicker} onOpenChange={setShowSchedulePicker}>
                <PopoverTrigger asChild>
                  <Button
                    variant={scheduledDate ? "secondary" : "outline"}
                    size="icon"
                    title={scheduledDate ? "Change scheduled time" : "Schedule send"}
                  >
                    <Clock className="h-4 w-4" />
                  </Button>
                </PopoverTrigger>
                <PopoverContent className="w-auto p-3" align="end">
                  <div className="space-y-3">
                    <Calendar
                      mode="single"
                      selected={scheduledDate}
                      onSelect={setScheduledDate}
                      disabled={(date) => date < new Date()}
                    />
                    <div className="flex items-center gap-2">
                      <label className="text-sm font-medium">Time:</label>
                      <Input
                        type="time"
                        value={scheduledTime}
                        onChange={(e) => setScheduledTime(e.target.value)}
                        className="w-32"
                      />
                    </div>
                    {scheduledDate && (
                      <div className="flex items-center justify-between">
                        <span className="text-xs text-muted-foreground">
                          {scheduledDate.toLocaleDateString()} at {scheduledTime}
                        </span>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => {
                            setScheduledDate(undefined);
                            setShowSchedulePicker(false);
                          }}
                        >
                          Clear
                        </Button>
                      </div>
                    )}
                  </div>
                </PopoverContent>
              </Popover>
              <TooltipProvider>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span>
                      <Button onClick={() => handleSend()} disabled={isSendDisabled}>
                        {isSending ? (
                          <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        ) : scheduledDate ? (
                          <Clock className="mr-2 h-4 w-4" />
                        ) : (
                          <Send className="mr-2 h-4 w-4" />
                        )}
                        {scheduledDate ? "Schedule" : "Send"}
                      </Button>
                    </span>
                  </TooltipTrigger>
                  {sendDisabledReason && (
                    <TooltipContent>{sendDisabledReason}</TooltipContent>
                  )}
                </Tooltip>
              </TooltipProvider>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      {/* Missing subject confirmation */}
      <AlertDialog open={showNoSubjectDialog} onOpenChange={setShowNoSubjectDialog}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Send without a subject?</AlertDialogTitle>
            <AlertDialogDescription>
              This email has no subject line.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel onClick={() => setShowNoSubjectDialog(false)}>
              Add Subject
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                setShowNoSubjectDialog(false);
                executeSend();
              }}
            >
              Send Anyway
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
