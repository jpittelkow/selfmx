"use client";

import { useState, useEffect, useRef, useCallback } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { RecipientInput } from "./recipient-input";
import {
  Dialog,
  DialogContent,
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
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { Calendar } from "@/components/ui/calendar";
import { Loader2, Send, Paperclip, X, Clock } from "lucide-react";
import { RichTextEditor } from "./rich-text-editor";
import { sanitizeEmailHtml } from "@/lib/sanitize";

interface Mailbox {
  id: number;
  address: string;
  display_name: string | null;
  signature: string | null;
  email_domain: { name: string };
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
  const [mailboxes, setMailboxes] = useState<Mailbox[]>([]);
  const [selectedMailboxId, setSelectedMailboxId] = useState<string>("");
  const [to, setTo] = useState<string[]>([]);
  const [cc, setCc] = useState<string[]>([]);
  const [bcc, setBcc] = useState<string[]>([]);
  const [showCcBcc, setShowCcBcc] = useState(false);
  const [subject, setSubject] = useState("");
  const [bodyHtml, setBodyHtml] = useState("");
  const [bodyText, setBodyText] = useState("");
  const [isSending, setIsSending] = useState(false);
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

  // Fetch mailboxes when dialog opens — filter to sendable (member/owner, non-catchall)
  useEffect(() => {
    if (open) {
      initializedRef.current = false;
      api
        .get<{ mailboxes: Mailbox[] }>("/email/mailboxes")
        .then((res) => {
          const filtered = res.data.mailboxes.filter(
            (m) => m.address !== "*" && m.user_role !== "viewer"
          );
          setMailboxes(filtered);

          // Set mailbox from reply data, active mailbox, or default to first
          if (replyData?.mailbox_id) {
            const replyMailbox = filtered.find((m) => m.id === replyData.mailbox_id);
            if (replyMailbox) {
              setSelectedMailboxId(replyMailbox.id.toString());
            }
          }

          if (!selectedMailboxId || !filtered.find((m) => m.id.toString() === selectedMailboxId)) {
            // Try activeMailboxId from localStorage as a fallback default
            const storedActiveId = localStorage.getItem("selfmx_active_mailbox_id");
            const activeMatch = storedActiveId
              ? filtered.find((m) => m.id.toString() === storedActiveId)
              : null;

            if (activeMatch) {
              setSelectedMailboxId(activeMatch.id.toString());
            } else if (filtered.length === 1) {
              setSelectedMailboxId(filtered[0].id.toString());
            }
          }
        })
        .catch(() => {
          toast.error("Failed to load mailboxes");
        });
    }
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
      // Body will be set with quoted content + signature after mailbox loads
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
      setBodyHtml(prefill + sanitizeEmailHtml(replyData.quoted_html));
    } else {
      // Reset for new compose
      setTo([]);
      setCc([]);
      setBcc([]);
      setShowCcBcc(false);
      setSubject("");
      setBodyHtml("");
      setBodyText("");
      setInReplyTo(null);
      setReferences(null);
      setThreadId(null);
    }
    setAttachments([]);
    setDraftId(null);
    setDraftStatus("");
    hasEditedRef.current = false;
  }, [open, mode, replyData]);

  // Insert signature when mailbox changes
  useEffect(() => {
    if (!selectedMailboxId || !open) return;
    const mailbox = mailboxes.find((m) => m.id.toString() === selectedMailboxId);
    if (!mailbox?.signature) return;

    // Build signature HTML
    const sigHtml = `<br><br><div data-signature="true">--<br>${mailbox.signature.replace(/\n/g, "<br>")}</div>`;

    setBodyHtml((prev) => {
      // Remove existing signature (greedy match handles nested divs; --<br> anchor prevents false matches)
      const withoutSig = prev.replace(/(<br>)*<div data-signature="true">--<br>[\s\S]*<\/div>$/, "");
      return withoutSig + sigHtml;
    });
  }, [selectedMailboxId, mailboxes, open]);

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

  const handleSend = async () => {
    if (to.length === 0 || !selectedMailboxId) {
      toast.error("Please fill in the required fields");
      return;
    }

    setIsSending(true);
    if (draftTimerRef.current) clearTimeout(draftTimerRef.current);

    try {
      if (attachments.length > 0) {
        // Use FormData for file uploads
        const formData = new FormData();
        formData.append("mailbox_id", selectedMailboxId);
        to.forEach((addr) => formData.append("to[]", addr));
        cc.forEach((addr) => formData.append("cc[]", addr));
        bcc.forEach((addr) => formData.append("bcc[]", addr));
        formData.append("subject", subject);
        formData.append("body_html", bodyHtml);
        formData.append("body_text", bodyText);
        if (inReplyTo) formData.append("in_reply_to", inReplyTo);
        if (references) formData.append("references", references);
        if (threadId) formData.append("thread_id", threadId.toString());
        attachments.forEach((file) => formData.append("attachments[]", file));

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
          body_html: bodyHtml,
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
      toast.error("Failed to send email");
    } finally {
      setIsSending(false);
    }
  };

  const handleClose = (isOpen: boolean) => {
    if (!isSending) {
      if (draftTimerRef.current) clearTimeout(draftTimerRef.current);
      onOpenChange(isOpen);
    }
  };

  const handleAttachFiles = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = e.target.files;
    if (!files) return;
    setAttachments((prev) => [...prev, ...Array.from(files)]);
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
  const dragCounterRef = useRef(0);

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
      setAttachments((prev) => [...prev, ...files]);
    }
  };

  return (
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
                      ? `${m.display_name} <${m.address}@${m.email_domain.name}>`
                      : `${m.address}@${m.email_domain.name}`}
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
              />
            </div>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-7 text-xs px-2 shrink-0"
              onClick={() => setShowCcBcc(!showCcBcc)}
            >
              {showCcBcc ? "Hide" : "CC/BCC"}
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
            {draftStatus && (
              <span className="text-xs text-muted-foreground">
                {draftStatus === "saving" ? "Saving draft..." : "Draft saved"}
              </span>
            )}
          </div>
          <div className="flex items-center gap-1">
            <Popover open={showSchedulePicker} onOpenChange={setShowSchedulePicker}>
              <PopoverTrigger asChild>
                <Button variant="outline" size="icon" title="Schedule send">
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
            <Button onClick={handleSend} disabled={isSending || to.length === 0 || !selectedMailboxId}>
              {isSending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : scheduledDate ? (
                <Clock className="mr-2 h-4 w-4" />
              ) : (
                <Send className="mr-2 h-4 w-4" />
              )}
              {scheduledDate ? "Schedule" : "Send"}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
