"use client";

import { useState, useCallback, useMemo } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { EmptyState } from "@/components/ui/empty-state";
import { cn } from "@/lib/utils";
import { Inbox, Search, ChevronLeft, ChevronRight, MailOpen, Mail, Star, Trash2, Send, FileEdit, AlertOctagon, Clock, Paperclip, Tag } from "lucide-react";
import { SearchBar } from "@/components/mail/search-bar";
import { ThreadListSkeleton } from "@/components/mail/thread-list-skeleton";
import { SenderAvatar } from "@/components/mail/sender-avatar";
import type { EmailThread, EmailLabel, MailView } from "@/lib/mail-types";
import { getMailboxAddress } from "@/lib/mail-types";

interface ThreadListProps {
  threads: EmailThread[];
  isLoading: boolean;
  selectedThreadId: number | null;
  currentView: MailView;
  onSelectThread: (thread: EmailThread) => void;
  currentPage: number;
  totalPages: number;
  onPageChange: (page: number) => void;
  onSearch?: (query: string) => void;
  onClearSearch?: () => void;
  isSearchActive?: boolean;
  labels: EmailLabel[];
  activeMailboxId?: number | null;
  onBulkActionComplete?: () => void;
  onToggleStar?: (emailId: number) => void;
  onTrash?: (emailId: number) => void;
  onMarkRead?: (emailId: number, isRead: boolean) => void;
}

const viewLabels: Record<MailView, string> = {
  inbox: "Inbox",
  starred: "Starred",
  sent: "Sent",
  drafts: "Drafts",
  snoozed: "Snoozed",
  spam: "Spam",
  trash: "Trash",
  label: "Label",
  search: "Search Results",
  priority: "Priority",
};

function formatDate(dateStr: string): string {
  const date = new Date(dateStr);
  const now = new Date();
  const isToday = date.toDateString() === now.toDateString();

  if (isToday) {
    return date.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
  }

  const daysDiff = Math.floor((now.getTime() - date.getTime()) / (1000 * 60 * 60 * 24));
  if (daysDiff < 7) {
    return date.toLocaleDateString(undefined, { weekday: "short" });
  }

  return date.toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

function getDateGroupLabel(dateStr: string): string {
  const date = new Date(dateStr);
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const yesterday = new Date(today);
  yesterday.setDate(yesterday.getDate() - 1);
  const weekAgo = new Date(today);
  weekAgo.setDate(weekAgo.getDate() - 7);
  const monthAgo = new Date(today);
  monthAgo.setDate(monthAgo.getDate() - 30);

  const emailDay = new Date(date.getFullYear(), date.getMonth(), date.getDate());

  if (emailDay.getTime() >= today.getTime()) return "Today";
  if (emailDay.getTime() >= yesterday.getTime()) return "Yesterday";
  if (emailDay.getTime() >= weekAgo.getTime()) return "This Week";
  if (emailDay.getTime() >= monthAgo.getTime()) return "This Month";
  return date.toLocaleDateString(undefined, { month: "long", year: "numeric" });
}

function getDisplayName(thread: EmailThread): string {
  const email = thread.latest_email;
  if (!email) return "Unknown";
  return email.from_name || email.from_address;
}

function getSubject(thread: EmailThread): string {
  return thread.subject || "(no subject)";
}

const viewEmptyStates: Record<string, { icon: typeof Inbox; title: string; description: string }> = {
  inbox: { icon: Inbox, title: "Your inbox is empty", description: "New messages will appear here" },
  sent: { icon: Send, title: "No sent emails", description: "Emails you send will appear here" },
  drafts: { icon: FileEdit, title: "No drafts", description: "Saved drafts will appear here" },
  starred: { icon: Star, title: "No starred emails", description: "Star emails to find them here later" },
  spam: { icon: AlertOctagon, title: "No spam", description: "Emails marked as spam will appear here" },
  trash: { icon: Trash2, title: "Trash is empty", description: "Deleted emails will appear here" },
  snoozed: { icon: Clock, title: "No snoozed emails", description: "Snoozed emails will reappear here" },
  label: { icon: Tag, title: "No emails with this label", description: "Labeled emails will appear here" },
  priority: { icon: Inbox, title: "No priority emails", description: "Priority emails will appear here" },
};

export function ThreadList({
  threads,
  isLoading,
  selectedThreadId,
  currentView,
  onSelectThread,
  labels,
  currentPage,
  totalPages,
  onPageChange,
  onSearch,
  onClearSearch,
  isSearchActive,
  activeMailboxId,
  onBulkActionComplete,
  onToggleStar,
  onTrash,
  onMarkRead,
}: ThreadListProps) {
  const showMailboxIndicator = activeMailboxId === null || activeMailboxId === undefined;
  const title = viewLabels[currentView] || "Mail";
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  const [isBulkLoading, setIsBulkLoading] = useState(false);

  const toggleSelect = useCallback((threadId: number, e: React.MouseEvent) => {
    e.stopPropagation();
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(threadId)) {
        next.delete(threadId);
      } else {
        next.add(threadId);
      }
      return next;
    });
  }, []);

  const toggleSelectAll = useCallback(() => {
    if (selectedIds.size === threads.length) {
      setSelectedIds(new Set());
    } else {
      setSelectedIds(new Set(threads.map((t) => t.id)));
    }
  }, [selectedIds.size, threads]);

  const getSelectedEmailIds = useCallback(() => {
    return threads
      .filter((t) => selectedIds.has(t.id))
      .map((t) => t.latest_email?.id)
      .filter((id): id is number => id !== undefined);
  }, [threads, selectedIds]);

  const handleBulkAction = useCallback(async (action: string) => {
    const emailIds = getSelectedEmailIds();
    if (emailIds.length === 0) return;

    setIsBulkLoading(true);
    try {
      await api.post("/email/messages/bulk", { action, email_ids: emailIds });
      toast.success(`Bulk action completed (${emailIds.length} emails)`);
      setSelectedIds(new Set());
      onBulkActionComplete?.();
    } catch {
      toast.error("Bulk action failed");
    } finally {
      setIsBulkLoading(false);
    }
  }, [getSelectedEmailIds, onBulkActionComplete]);

  // Group threads by date
  const groupedThreads = useMemo(() => {
    const groups: { label: string; threads: EmailThread[] }[] = [];
    let currentGroup: string | null = null;

    for (const thread of threads) {
      const dateStr = thread.latest_email?.sent_at || thread.last_message_at;
      const groupLabel = dateStr ? getDateGroupLabel(dateStr) : "Unknown";

      if (groupLabel !== currentGroup) {
        currentGroup = groupLabel;
        groups.push({ label: groupLabel, threads: [thread] });
      } else {
        groups[groups.length - 1].threads.push(thread);
      }
    }

    return groups;
  }, [threads]);

  const hasBulkSelection = selectedIds.size > 0;

  return (
    <div className="flex flex-col h-full">
      {/* Header with search */}
      <div className="px-4 py-3 border-b shrink-0 space-y-2">
        <div className="flex items-center justify-between">
          <h2 className="font-semibold text-lg">{title}</h2>
          {threads.length > 0 && (
            <Checkbox
              checked={selectedIds.size === threads.length && threads.length > 0}
              onCheckedChange={toggleSelectAll}
              aria-label="Select all"
              className="mr-1"
            />
          )}
        </div>
        {hasBulkSelection && (
          <div className="flex items-center gap-1 rounded-md bg-muted p-1.5">
            <span className="text-xs text-muted-foreground px-1.5">{selectedIds.size} selected</span>
            <div className="flex gap-0.5 ml-auto">
              <Button variant="ghost" size="sm" className="h-7 px-2 text-xs" disabled={isBulkLoading} onClick={() => handleBulkAction("read")}>
                <MailOpen className="h-3.5 w-3.5 mr-1" /> Read
              </Button>
              <Button variant="ghost" size="sm" className="h-7 px-2 text-xs" disabled={isBulkLoading} onClick={() => handleBulkAction("unread")}>
                <Mail className="h-3.5 w-3.5 mr-1" /> Unread
              </Button>
              <Button variant="ghost" size="sm" className="h-7 px-2 text-xs" disabled={isBulkLoading} onClick={() => handleBulkAction("star")}>
                <Star className="h-3.5 w-3.5 mr-1" /> Star
              </Button>
              <Button variant="ghost" size="sm" className="h-7 px-2 text-xs" disabled={isBulkLoading} onClick={() => handleBulkAction("trash")}>
                <Trash2 className="h-3.5 w-3.5 mr-1" /> Trash
              </Button>
            </div>
          </div>
        )}
        {onSearch && onClearSearch && !hasBulkSelection && (
          <SearchBar
            onSearch={onSearch}
            onClear={onClearSearch}
            isSearching={isSearchActive ?? false}
            labels={labels}
          />
        )}
      </div>

      {/* Thread list */}
      <div className="flex-1 overflow-y-auto">
        {isLoading ? (
          <ThreadListSkeleton />
        ) : threads.length === 0 ? (
          isSearchActive ? (
            <EmptyState
              icon={Search}
              title="No results found"
              description="Try adjusting your search query"
            />
          ) : (
            <EmptyState
              icon={viewEmptyStates[currentView]?.icon || Inbox}
              title={viewEmptyStates[currentView]?.title || "No emails"}
              description={viewEmptyStates[currentView]?.description || "Messages you receive will appear here"}
            />
          )
        ) : (
          <div>
            {groupedThreads.map((group, groupIdx) => (
              <div key={`${group.label}-${groupIdx}`}>
                {/* Date group header */}
                <div className="sticky top-0 z-10 px-4 py-1.5 bg-muted/80 backdrop-blur-sm border-b">
                  <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {group.label}
                  </span>
                </div>

                {group.threads.map((thread) => {
                  const isFocused = selectedThreadId === thread.id;
                  const isUnread = thread.latest_email && !(thread.latest_email.effective_is_read ?? thread.latest_email.is_read);
                  const isChecked = selectedIds.has(thread.id);
                  const senderName = thread.latest_email?.from_name || null;
                  const senderEmail = thread.latest_email?.from_address || "";
                  return (
                    <div
                      key={thread.id}
                      className={cn(
                        "group flex items-start gap-3 w-full text-left px-4 py-3 border-b transition-colors hover:bg-muted/50 cursor-pointer",
                        isFocused && "bg-primary/5 border-l-2 border-l-primary",
                        !isFocused && isUnread && "bg-muted/30 border-l-2 border-l-primary",
                        !isFocused && !isUnread && "border-l-2 border-l-transparent",
                        isChecked && "bg-primary/5"
                      )}
                      onClick={() => onSelectThread(thread)}
                    >
                      <div
                        className="pt-0.5 shrink-0"
                        onClick={(e) => toggleSelect(thread.id, e)}
                      >
                        <Checkbox
                          checked={isChecked}
                          className="pointer-events-none"
                          aria-label={`Select ${thread.subject}`}
                        />
                      </div>
                      {/* Sender avatar */}
                      <SenderAvatar
                        name={senderName}
                        email={senderEmail}
                        size="sm"
                        className="mt-0.5 shrink-0"
                      />
                      <div className="flex-1 min-w-0">
                        <div className="flex items-start justify-between gap-2">
                          <span
                            className={cn(
                              "text-sm truncate",
                              isUnread ? "font-semibold text-foreground" : "text-muted-foreground"
                            )}
                          >
                            {getDisplayName(thread)}
                          </span>
                          <div className="flex items-center gap-1.5 shrink-0">
                            {thread.latest_email?.attachments_count && thread.latest_email.attachments_count > 0 && (
                              <Paperclip className="h-3 w-3 text-muted-foreground" />
                            )}
                            {(thread.latest_email?.effective_is_starred ?? thread.latest_email?.is_starred) && (
                              <Star className="h-3 w-3 fill-yellow-400 text-yellow-400" />
                            )}
                            <span className="text-xs text-muted-foreground whitespace-nowrap">
                              {thread.latest_email && formatDate(thread.latest_email.sent_at)}
                            </span>
                          </div>
                        </div>
                        <div className="flex items-center gap-2 mt-0.5">
                          <span
                            className={cn(
                              "text-sm truncate",
                              isUnread ? "font-medium text-foreground" : "text-muted-foreground"
                            )}
                          >
                            {getSubject(thread)}
                          </span>
                          {thread.message_count > 1 && (
                            <span className="text-xs text-muted-foreground shrink-0">
                              ({thread.message_count})
                            </span>
                          )}
                        </div>
                        <div className="flex items-center justify-between mt-0.5">
                          <div className="flex-1 min-w-0">
                            {thread.latest_email?.preview_text && (
                              <p className="text-xs text-muted-foreground truncate">
                                {thread.latest_email.preview_text}
                              </p>
                            )}
                            {showMailboxIndicator && thread.latest_email?.mailbox && (
                              <span className="text-xs text-muted-foreground">
                                {getMailboxAddress(thread.latest_email.mailbox)}
                              </span>
                            )}
                          </div>
                          {/* Hover quick actions */}
                          {(onToggleStar || onTrash || onMarkRead) && thread.latest_email && (() => {
                            const email = thread.latest_email;
                            if (!email) return null;
                            return (
                            <div className="hidden group-hover:flex items-center gap-0.5 shrink-0 ml-2">
                              {onToggleStar && (
                                <button
                                  type="button"
                                  className="p-1 rounded hover:bg-muted transition-colors"
                                  onClick={(e) => { e.stopPropagation(); onToggleStar(email.id); }}
                                  title={email.is_starred ? "Unstar" : "Star"}
                                >
                                  <Star className={cn("h-3.5 w-3.5", email.is_starred ? "fill-yellow-400 text-yellow-400" : "text-muted-foreground")} />
                                </button>
                              )}
                              {onMarkRead && (
                                <button
                                  type="button"
                                  className="p-1 rounded hover:bg-muted transition-colors"
                                  onClick={(e) => { e.stopPropagation(); onMarkRead(email.id, !(email.effective_is_read ?? email.is_read)); }}
                                  title={isUnread ? "Mark read" : "Mark unread"}
                                >
                                  {isUnread
                                    ? <MailOpen className="h-3.5 w-3.5 text-muted-foreground" />
                                    : <Mail className="h-3.5 w-3.5 text-muted-foreground" />
                                  }
                                </button>
                              )}
                              {onTrash && (
                                <button
                                  type="button"
                                  className="p-1 rounded hover:bg-muted transition-colors"
                                  onClick={(e) => { e.stopPropagation(); onTrash(email.id); }}
                                  title="Delete"
                                >
                                  <Trash2 className="h-3.5 w-3.5 text-muted-foreground" />
                                </button>
                              )}
                            </div>
                            );
                          })()}
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Pagination */}
      {totalPages > 1 && (
        <div className="px-4 py-2 border-t flex items-center justify-between shrink-0">
          <Button
            variant="ghost"
            size="sm"
            disabled={currentPage <= 1}
            onClick={() => onPageChange(currentPage - 1)}
          >
            <ChevronLeft className="h-4 w-4" />
          </Button>
          <span className="text-xs text-muted-foreground">
            Page {currentPage} of {totalPages}
          </span>
          <Button
            variant="ghost"
            size="sm"
            disabled={currentPage >= totalPages}
            onClick={() => onPageChange(currentPage + 1)}
          >
            <ChevronRight className="h-4 w-4" />
          </Button>
        </div>
      )}
    </div>
  );
}
