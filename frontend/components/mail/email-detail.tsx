"use client";

import { useRef, useEffect, useState } from "react";
import { useTheme } from "@/components/theme-provider";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Separator } from "@/components/ui/separator";
import {
  ArrowLeft,
  Star,
  Trash2,
  Mail,
  MailOpen,
  AlertOctagon,
  Paperclip,
  Download,
  Reply,
  ReplyAll,
  Forward,
  CheckCircle2,
  XCircle,
  Clock,
  MoreHorizontal,
  ChevronRight,
  Image,
  FileText,
  FileSpreadsheet,
  File,
  Sun,
  Loader2 as Loader2Icon,
  Activity,
} from "lucide-react";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
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
import { cn } from "@/lib/utils";
import { useIsMobile } from "@/lib/use-mobile";
import { api } from "@/lib/api";
import type { Email } from "@/lib/mail-types";
import { EmptyState } from "@/components/ui/empty-state";
import { EmailDetailSkeleton } from "@/components/mail/email-detail-skeleton";
import { ThreadSummary } from "@/components/mail/thread-summary";
import { SnoozePicker } from "@/components/mail/snooze-picker";
import { AILabelSuggestions } from "@/components/mail/ai-label-suggestions";
import { SmartReplySuggestions } from "@/components/mail/smart-reply-suggestions";

interface EmailDetailProps {
  emails: Email[];
  threadId?: number | null;
  isLoading: boolean;
  onBack?: () => void;
  onToggleStar: (emailId: number) => void;
  onMarkRead: (emailId: number, isRead: boolean) => void;
  onTrash: (emailId: number) => void;
  onToggleSpam: (emailId: number) => void;
  onReply?: (emailId: number) => void;
  onReplyAll?: (emailId: number) => void;
  onForward?: (emailId: number) => void;
  onUseSmartReply?: (emailId: number, text: string) => void;
  onLabelsChanged?: () => void;
}

function formatFullDate(dateStr: string): string {
  return new Date(dateStr).toLocaleString(undefined, {
    weekday: "short",
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function getFileIcon(mimeType: string) {
  if (mimeType.startsWith("image/")) return Image;
  if (mimeType.includes("spreadsheet") || mimeType.includes("excel") || mimeType === "text/csv") return FileSpreadsheet;
  if (mimeType.includes("pdf") || mimeType.includes("document") || mimeType.includes("text/")) return FileText;
  return File;
}

function linkifyText(text: string): React.ReactNode[] {
  const urlRegex = /(https?:\/\/[^\s<]+)/g;
  const parts = text.split(urlRegex);
  return parts.map((part, i) => {
    // Odd-indexed parts from split on a capturing group are the matched URLs
    if (i % 2 === 1) {
      return (
        <a key={i} href={part} target="_blank" rel="noopener noreferrer" className="text-primary underline break-all">
          {part}
        </a>
      );
    }
    return <span key={i}>{part}</span>;
  });
}

function DeliveryStatusBadge({ status }: { status: string | null }) {
  if (!status) return null;

  const config: Record<string, { label: string; className: string; icon: React.ReactNode }> = {
    sending: {
      label: "Sending",
      className: "bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400",
      icon: <Clock className="h-3 w-3" />,
    },
    queued: {
      label: "Queued",
      className: "bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400",
      icon: <Clock className="h-3 w-3" />,
    },
    delivered: {
      label: "Delivered",
      className: "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400",
      icon: <CheckCircle2 className="h-3 w-3" />,
    },
    bounced: {
      label: "Bounced",
      className: "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400",
      icon: <XCircle className="h-3 w-3" />,
    },
    failed: {
      label: "Failed",
      className: "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400",
      icon: <XCircle className="h-3 w-3" />,
    },
  };

  const c = config[status];
  if (!c) return null;

  return (
    <span className={cn("inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium", c.className)}>
      {c.icon}
      {c.label}
    </span>
  );
}

interface ProviderEvent {
  timestamp: number;
  event: string;
  recipient?: string;
}

const eventColorMap: Record<string, string> = {
  delivered: "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400",
  opened: "bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400",
  clicked: "bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400",
  bounced: "bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400",
  failed: "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400",
  complained: "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400",
  accepted: "bg-muted text-muted-foreground",
};

function ProviderEventsPopover({ emailId, onStatusSync }: { emailId: number; onStatusSync?: (status: string) => void }) {
  const [events, setEvents] = useState<ProviderEvent[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [fetched, setFetched] = useState(false);

  // Reset when emailId changes to avoid showing stale data
  useEffect(() => {
    setFetched(false);
    setEvents([]);
  }, [emailId]);

  const fetchEvents = () => {
    if (fetched) return;
    setIsLoading(true);
    api.get<{ items: ProviderEvent[]; delivery_status?: string }>(`/email/messages/${emailId}/provider-events`)
      .then((res) => {
        setEvents(res.data.items ?? []);
        if (res.data.delivery_status && onStatusSync) {
          onStatusSync(res.data.delivery_status);
        }
      })
      .catch(() => {})
      .finally(() => { setIsLoading(false); setFetched(true); });
  };

  return (
    <Popover onOpenChange={(open) => { if (open) fetchEvents(); }}>
      <PopoverTrigger asChild>
        <Button variant="ghost" size="sm" className="h-6 px-1.5 text-xs gap-1 text-muted-foreground">
          <Activity className="h-3 w-3" />
          Events
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-72 p-0" align="start">
        {isLoading ? (
          <div className="flex items-center justify-center py-4">
            <Loader2Icon className="h-4 w-4 animate-spin text-muted-foreground" />
          </div>
        ) : events.length === 0 ? (
          <p className="text-sm text-muted-foreground p-3">No provider events found.</p>
        ) : (
          <div className="divide-y max-h-48 overflow-y-auto">
            {events.map((e, i) => (
              <div key={i} className="px-3 py-2 flex items-center gap-2 text-xs">
                <span className="text-muted-foreground whitespace-nowrap">
                  {new Date(e.timestamp * 1000).toLocaleString()}
                </span>
                <span className={cn("inline-flex items-center rounded-full px-1.5 py-0.5 font-medium", eventColorMap[e.event] ?? "bg-muted text-muted-foreground")}>
                  {e.event}
                </span>
                {e.recipient && <span className="truncate text-muted-foreground">{e.recipient}</span>}
              </div>
            ))}
          </div>
        )}
      </PopoverContent>
    </Popover>
  );
}

function EmailMessage({
  email,
  isLast,
  resolvedTheme,
  onToggleStar,
  onMarkRead,
  onTrash,
  onToggleSpam,
  onReply,
  onReplyAll,
  onForward,
  onUseSmartReply,
  onLabelsChanged,
}: {
  email: Email;
  isLast: boolean;
  resolvedTheme: "dark" | "light";
  onToggleStar: (id: number) => void;
  onMarkRead: (id: number, isRead: boolean) => void;
  onTrash: (id: number) => void;
  onToggleSpam: (id: number) => void;
  onReply?: (id: number) => void;
  onReplyAll?: (id: number) => void;
  onForward?: (id: number) => void;
  onUseSmartReply?: (emailId: number, text: string) => void;
  onLabelsChanged?: () => void;
}) {
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const isMobile = useIsMobile();
  const [forceLightMode, setForceLightMode] = useState(false);
  const [deliveryStatus, setDeliveryStatus] = useState(email.delivery_status);

  // Reset when email changes
  useEffect(() => {
    setDeliveryStatus(email.delivery_status);
  }, [email.id, email.delivery_status]);
  const isDark = resolvedTheme === "dark" && !forceLightMode;

  const getIframeStyles = (dark: boolean) => `
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      font-size: 14px;
      line-height: 1.6;
      color: ${dark ? "#e2e8f0" : "#333"};
      background: ${dark ? "transparent" : "#ffffff"};
      color-scheme: ${dark ? "dark" : "light"};
      margin: 0;
      padding: 16px;
    }
    img { max-width: 100%; height: auto; }
    a { color: ${dark ? "#93c5fd" : "#2563eb"}; }
    pre, code { overflow-x: auto; }
  `;

  // Write iframe content when email changes.
  // Intentionally omits isDark — the theme-update effect below patches styles in-place
  // to avoid rewriting the entire document (and causing a flash) on theme toggle.
  useEffect(() => {
    if (email.body_html && iframeRef.current) {
      const doc = iframeRef.current.contentDocument;
      if (doc) {
        doc.open();
        doc.write(`
          <!DOCTYPE html>
          <html>
          <head>
            <style id="theme-styles">${getIframeStyles(isDark)}</style>
          </head>
          <body>${email.body_html}</body>
          </html>
        `);
        doc.close();

        // Auto-resize iframe
        const resizeObserver = new ResizeObserver(() => {
          if (iframeRef.current && doc.body) {
            iframeRef.current.style.height = doc.body.scrollHeight + "px";
          }
        });
        if (doc.body) resizeObserver.observe(doc.body);
        return () => resizeObserver.disconnect();
      }
    }
  }, [email.body_html]);

  // Update iframe theme styles in-place without re-rendering content
  useEffect(() => {
    if (!iframeRef.current) return;
    const doc = iframeRef.current.contentDocument;
    const styleEl = doc?.getElementById("theme-styles");
    if (styleEl) {
      styleEl.textContent = getIframeStyles(isDark);
    }
  }, [isDark, forceLightMode]);

  const toRecipients = email.recipients.filter((r) => r.type === "to");
  const ccRecipients = email.recipients.filter((r) => r.type === "cc");

  const handleDownloadAttachment = async (attachmentId: number, filename: string) => {
    try {
      const res = await api.get(`/email/attachments/${attachmentId}/download`, {
        responseType: "blob",
      });
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      link.click();
      window.URL.revokeObjectURL(url);
    } catch {
      // Silent fail
    }
  };

  return (
    <div className="border-b last:border-b-0">
      {/* Email header */}
      <div className="px-6 py-4">
        <div className="flex items-start justify-between gap-4">
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2">
              <span className="font-semibold text-sm">
                {email.from_name || email.from_address}
              </span>
              {email.from_name && (
                <span className="text-xs text-muted-foreground">&lt;{email.from_address}&gt;</span>
              )}
              {email.direction === "outbound" && (
                <>
                  <DeliveryStatusBadge status={deliveryStatus} />
                  <ProviderEventsPopover emailId={email.id} onStatusSync={setDeliveryStatus} />
                </>
              )}
              {email.is_spam && email.spam_score != null && (
                <TooltipProvider>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Badge variant="secondary" className="text-xs font-normal">
                        <AlertOctagon className="h-3 w-3 mr-1" />
                        Spam score: {Number(email.spam_score).toFixed(1)}
                      </Badge>
                    </TooltipTrigger>
                    <TooltipContent>
                      This email was flagged as spam because its score ({Number(email.spam_score).toFixed(1)}) exceeds the configured threshold.
                    </TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              )}
            </div>
            <div className="text-xs text-muted-foreground mt-0.5">
              To: {toRecipients.map((r) => r.name || r.address).join(", ")}
              {ccRecipients.length > 0 && (
                <span> | CC: {ccRecipients.map((r) => r.name || r.address).join(", ")}</span>
              )}
            </div>
            {isMobile && (
              <div className="text-xs text-muted-foreground mt-0.5">
                {formatFullDate(email.sent_at)}
              </div>
            )}
          </div>
          <div className="flex items-center gap-1 shrink-0">
            <span className="text-xs text-muted-foreground mr-2 hidden md:inline">
              {formatFullDate(email.sent_at)}
            </span>
            {isMobile ? (
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="icon" className="h-8 w-8">
                    <MoreHorizontal className="h-4 w-4" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem onClick={() => onToggleStar(email.id)}>
                    <Star className={cn("h-4 w-4 mr-2", email.is_starred && "fill-yellow-400 text-yellow-400")} />
                    {email.is_starred ? "Unstar" : "Star"}
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={() => onMarkRead(email.id, !email.is_read)}>
                    {email.is_read ? <MailOpen className="h-4 w-4 mr-2" /> : <Mail className="h-4 w-4 mr-2" />}
                    {email.is_read ? "Mark unread" : "Mark read"}
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={() => onToggleSpam(email.id)}>
                    <AlertOctagon className="h-4 w-4 mr-2" />
                    Report spam
                  </DropdownMenuItem>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem onClick={() => onTrash(email.id)} className="text-destructive">
                    <Trash2 className="h-4 w-4 mr-2" />
                    Delete
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            ) : (
              <>
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8"
                  onClick={() => onToggleStar(email.id)}
                  title={email.is_starred ? "Unstar" : "Star"}
                >
                  <Star
                    className={cn(
                      "h-4 w-4",
                      email.is_starred ? "fill-yellow-400 text-yellow-400" : "text-muted-foreground"
                    )}
                  />
                </Button>
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8"
                  onClick={() => onMarkRead(email.id, !email.is_read)}
                  title={email.is_read ? "Mark unread" : "Mark read"}
                >
                  {email.is_read ? (
                    <MailOpen className="h-4 w-4 text-muted-foreground" />
                  ) : (
                    <Mail className="h-4 w-4 text-muted-foreground" />
                  )}
                </Button>
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8"
                  onClick={() => onToggleSpam(email.id)}
                  title="Report spam"
                >
                  <AlertOctagon className="h-4 w-4 text-muted-foreground" />
                </Button>
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8"
                  onClick={() => onTrash(email.id)}
                  title="Delete"
                >
                  <Trash2 className="h-4 w-4 text-muted-foreground" />
                </Button>
                <SnoozePicker emailId={email.id} onSnoozed={() => onTrash(email.id)} />
              </>
            )}
          </div>
        </div>

        {/* Labels */}
        {email.labels.length > 0 && (
          <div className="flex flex-wrap gap-1 mt-2">
            {email.labels.map((label) => (
              <Badge
                key={label.id}
                variant="outline"
                className="text-xs"
                style={label.color ? { borderColor: label.color, color: label.color } : undefined}
              >
                {label.name}
              </Badge>
            ))}
          </div>
        )}

        {/* AI Label Suggestions */}
        <div className="mt-2">
          <AILabelSuggestions
            emailId={email.id}
            existingLabelIds={email.labels.map((l) => l.id)}
            onLabelsChanged={onLabelsChanged}
          />
        </div>
      </div>

      {/* Email body */}
      <div className="px-6 pb-4">
        {email.body_html ? (
          <div>
            {resolvedTheme === "dark" && (
              <div className="flex justify-end mb-1">
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-6 px-2 text-xs text-muted-foreground"
                  onClick={() => setForceLightMode(!forceLightMode)}
                  title={forceLightMode ? "Use dark theme" : "View in light mode"}
                >
                  <Sun className="h-3 w-3 mr-1" />
                  {forceLightMode ? "Dark mode" : "Light mode"}
                </Button>
              </div>
            )}
            <iframe
              ref={iframeRef}
              title="Email content"
              className="w-full border-0 min-h-24"
              sandbox="allow-same-origin"
            />
          </div>
        ) : (
          <pre className="whitespace-pre-wrap text-sm font-sans text-foreground">
            {email.body_text ? linkifyText(email.body_text) : "(no content)"}
          </pre>
        )}
      </div>

      {/* Attachments */}
      {email.attachments.length > 0 && (
        <div className="px-6 pb-4">
          <Separator className="mb-3" />
          <div className="flex items-center gap-2 mb-2 text-sm text-muted-foreground">
            <Paperclip className="h-4 w-4" />
            <span>{email.attachments.length} attachment{email.attachments.length > 1 ? "s" : ""}</span>
          </div>
          <div className="flex flex-wrap gap-2">
            {email.attachments.map((att) => {
              const FileIcon = getFileIcon(att.mime_type);
              return (
                <button
                  key={att.id}
                  onClick={() => handleDownloadAttachment(att.id, att.filename)}
                  className="flex items-center gap-2 px-3 py-2 border rounded-md text-sm hover:bg-muted transition-colors"
                  title={`Download ${att.filename}`}
                >
                  <FileIcon className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                  <span className="truncate max-w-48">{att.filename}</span>
                  <span className="text-xs text-muted-foreground shrink-0">{formatSize(att.size)}</span>
                  <Download className="h-3 w-3 text-muted-foreground shrink-0" />
                </button>
              );
            })}
          </div>
        </div>
      )}

      {/* Smart Reply Suggestions (shown on last email in thread) */}
      {isLast && onUseSmartReply && email.direction === "inbound" && (
        <div className="px-6 pb-3">
          <SmartReplySuggestions
            emailId={email.id}
            onUseReply={(text) => onUseSmartReply(email.id, text)}
          />
        </div>
      )}

      {/* Reply/Forward buttons (shown on last email in thread) */}
      {isLast && (onReply || onReplyAll || onForward) && (
        <div className="px-6 pb-4">
          <Separator className="mb-3" />
          <div className="flex items-center gap-2">
            {onReply && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => onReply(email.id)}
                className="h-8"
              >
                <Reply className="mr-1.5 h-3.5 w-3.5" />
                Reply
              </Button>
            )}
            {onReplyAll && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => onReplyAll(email.id)}
                className="h-8"
              >
                <ReplyAll className="mr-1.5 h-3.5 w-3.5" />
                Reply All
              </Button>
            )}
            {onForward && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => onForward(email.id)}
                className="h-8"
              >
                <Forward className="mr-1.5 h-3.5 w-3.5" />
                Forward
              </Button>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

function CollapsedEmailMessage({
  email,
  onExpand,
}: {
  email: Email;
  onExpand: () => void;
}) {
  return (
    <button
      type="button"
      className="flex items-center gap-3 w-full text-left px-6 py-3 border-b hover:bg-muted/50 transition-colors"
      onClick={onExpand}
    >
      <ChevronRight className="h-4 w-4 text-muted-foreground shrink-0" />
      <span className="text-sm font-medium truncate">
        {email.from_name || email.from_address}
      </span>
      <span className="text-xs text-muted-foreground shrink-0 ml-auto">
        {formatFullDate(email.sent_at)}
      </span>
    </button>
  );
}

export function EmailDetail({
  emails,
  threadId,
  isLoading,
  onBack,
  onToggleStar,
  onMarkRead,
  onTrash,
  onToggleSpam,
  onReply,
  onReplyAll,
  onForward,
  onUseSmartReply,
  onLabelsChanged,
}: EmailDetailProps) {
  const { resolvedTheme } = useTheme();
  const shouldCollapse = emails.length >= 3;
  const [expandedIds, setExpandedIds] = useState<Set<number>>(new Set());

  // Reset expanded state when emails change (new thread selected)
  useEffect(() => {
    setExpandedIds(new Set());
  }, [emails.map(e => e.id).join(",")]);

  if (isLoading) {
    return <EmailDetailSkeleton />;
  }

  if (emails.length === 0) {
    return (
      <div className="flex items-center justify-center h-full">
        <EmptyState
          icon={MailOpen}
          title="Select an email"
          description="Choose an email from the list to view it"
        />
      </div>
    );
  }

  const firstEmail = emails[0];

  return (
    <div className="flex flex-col h-full">
      {/* Subject bar */}
      <div className="px-6 py-4 border-b shrink-0">
        <div className="flex items-center gap-3">
          {onBack && (
            <Button variant="ghost" size="icon" className="h-8 w-8 shrink-0" onClick={onBack} title="Back">
              <ArrowLeft className="h-4 w-4" />
            </Button>
          )}
          <h2 className="text-lg font-semibold truncate">
            {firstEmail.subject || "(no subject)"}
          </h2>
        </div>
      </div>

      {/* Email messages */}
      <div className="flex-1 overflow-y-auto">
        {/* Thread summary (shown for multi-email threads) */}
        {threadId && emails.length >= 2 && (
          <ThreadSummary threadId={threadId} />
        )}

        {emails.map((email, idx) => {
          const isLast = idx === emails.length - 1;
          const isCollapsible = shouldCollapse && !isLast;
          const isExpanded = expandedIds.has(email.id);

          if (isCollapsible && !isExpanded) {
            return (
              <CollapsedEmailMessage
                key={email.id}
                email={email}
                onExpand={() => setExpandedIds(prev => new Set([...prev, email.id]))}
              />
            );
          }

          return (
            <EmailMessage
              key={email.id}
              email={email}
              isLast={isLast}
              resolvedTheme={resolvedTheme}
              onToggleStar={onToggleStar}
              onMarkRead={onMarkRead}
              onTrash={onTrash}
              onToggleSpam={onToggleSpam}
              onReply={onReply}
              onReplyAll={onReplyAll}
              onForward={onForward}
              onUseSmartReply={onUseSmartReply}
              onLabelsChanged={onLabelsChanged}
            />
          );
        })}
      </div>
    </div>
  );
}
