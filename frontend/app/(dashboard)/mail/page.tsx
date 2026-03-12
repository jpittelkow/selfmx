"use client";

import { useState, useEffect, useCallback, useMemo, useRef } from "react";
import { useSearchParams } from "next/navigation";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { searchEmails } from "@/lib/email-search";
import { useMailData } from "@/lib/mail-data-provider";
import { usePageTitle } from "@/lib/use-page-title";
import { ThreadList } from "@/components/mail/thread-list";
import { EmailDetail } from "@/components/mail/email-detail";
import { useIsMobile } from "@/lib/use-mobile";
import { useMailKeyboard } from "@/lib/use-mail-keyboard";
import { MailCommandPalette } from "@/components/mail/mail-command-palette";
import { MailOpen } from "lucide-react";
import type { EmailThread, Email, MailView } from "@/lib/mail-types";
import type { ReplyData } from "@/components/mail/compose-dialog";

const viewLabels: Record<string, string> = {
  inbox: "Inbox",
  sent: "Sent",
  drafts: "Drafts",
  starred: "Starred",
  spam: "Spam",
  trash: "Trash",
  snoozed: "Snoozed",
  priority: "Priority",
  label: "Label",
  search: "Search",
};

export default function MailPage() {
  const isMobile = useIsMobile();
  const searchParams = useSearchParams();
  const {
    labels,
    activeMailboxId,
    unreadCount,
    refreshUnreadCount,
    openReply: ctxOpenReply,
    openReplyAll: ctxOpenReplyAll,
    openForward: ctxOpenForward,
    openCompose,
    setOnSent,
    onNewEmailReceived,
  } = useMailData();

  // Derive view from URL
  const currentView = (searchParams.get("view") as MailView) || "inbox";
  const currentLabelId = searchParams.get("labelId") ? Number(searchParams.get("labelId")) : null;
  const initialSearchQuery = searchParams.get("search") || null;

  const [threads, setThreads] = useState<EmailThread[]>([]);
  const [selectedThread, setSelectedThread] = useState<EmailThread | null>(null);
  const [selectedEmails, setSelectedEmails] = useState<Email[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isLoadingDetail, setIsLoadingDetail] = useState(false);
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [isSearchActive, setIsSearchActive] = useState(false);
  const [commandPaletteOpen, setCommandPaletteOpen] = useState(false);

  const fetchThreads = useCallback(async () => {
    setIsLoading(true);
    try {
      const params = new URLSearchParams({ page: currentPage.toString(), per_page: "25" });

      // Scope to active mailbox if one is selected
      if (activeMailboxId !== null) {
        params.set("mailbox_id", activeMailboxId.toString());
      }

      if (currentView === "inbox" || currentView === "priority") {
        if (currentView === "priority") params.set("sort", "priority");
        const res = await api.get<{ data: EmailThread[]; meta?: { last_page: number } }>(
          `/email/threads?${params}`
        );
        setThreads(res.data.data);
        setTotalPages(res.data.meta?.last_page || 1);
      } else {
        if (currentView === "starred") params.set("starred", "1");
        if (currentView === "sent") params.set("direction", "outbound");
        if (currentView === "drafts") params.set("drafts", "1");
        if (currentView === "snoozed") params.set("snoozed", "1");
        if (currentView === "spam") params.set("spam", "1");
        if (currentView === "trash") params.set("trashed", "1");
        if (currentView === "label" && currentLabelId) params.set("label_id", currentLabelId.toString());

        const res = await api.get<{ data: Email[]; meta?: { last_page: number } }>(
          `/email/messages?${params}`
        );
        const emailThreads: EmailThread[] = res.data.data.map((email: Email) => ({
          id: email.id,
          subject: email.subject,
          last_message_at: email.sent_at,
          message_count: 1,
          emails_count: 1,
          latest_email: {
            id: email.id,
            from_address: email.from_address,
            from_name: email.from_name,
            subject: email.subject,
            is_read: email.is_read,
            effective_is_read: email.effective_is_read ?? email.is_read,
            is_starred: email.is_starred,
            effective_is_starred: email.effective_is_starred ?? email.is_starred,
            sent_at: email.sent_at,
            direction: email.direction,
            mailbox_id: email.mailbox_id,
            mailbox: email.mailbox,
            recipients: email.recipients,
          },
        }));
        setThreads(emailThreads);
        setTotalPages(res.data.meta?.last_page || 1);
      }
    } catch {
      toast.error("Failed to load emails");
    } finally {
      setIsLoading(false);
    }
  }, [currentView, currentPage, currentLabelId, activeMailboxId]);

  const handleSearch = useCallback(async (query: string) => {
    setIsSearchActive(true);
    setSelectedThread(null);
    setSelectedEmails([]);
    setIsLoading(true);
    try {
      const res = await searchEmails(query, 1);
      const emailThreads: EmailThread[] = (res.data || []).map((email: Email) => ({
        id: email.id,
        subject: email.subject,
        last_message_at: email.sent_at,
        message_count: 1,
        emails_count: 1,
        latest_email: {
          id: email.id,
          from_address: email.from_address,
          from_name: email.from_name,
          subject: email.subject,
          is_read: email.is_read,
          effective_is_read: email.effective_is_read ?? email.is_read,
          is_starred: email.is_starred,
          effective_is_starred: email.effective_is_starred ?? email.is_starred,
          sent_at: email.sent_at,
          direction: email.direction,
          mailbox_id: email.mailbox_id,
          mailbox: email.mailbox,
          recipients: email.recipients,
        },
      }));
      setThreads(emailThreads);
      setTotalPages(res.meta?.last_page || 1);
      setCurrentPage(1);
    } catch {
      toast.error("Search failed");
    } finally {
      setIsLoading(false);
    }
  }, []);

  const handleClearSearch = useCallback(() => {
    setIsSearchActive(false);
    setCurrentPage(1);
  }, []);

  // Reset selection when view or mailbox changes (skip if launching with a search query)
  const initialSearchRef = useRef(initialSearchQuery);
  useEffect(() => {
    if (initialSearchRef.current) {
      // On mount with a search param, trigger search instead of resetting
      handleSearch(initialSearchRef.current);
      initialSearchRef.current = null;
      return;
    }
    setSelectedThread(null);
    setSelectedEmails([]);
    setCurrentPage(1);
    setIsSearchActive(false);
  }, [currentView, currentLabelId, activeMailboxId]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!isSearchActive) {
      fetchThreads();
    }
  }, [fetchThreads, isSearchActive]);

  // Refresh thread list when a new email arrives via real-time push
  useEffect(() => {
    if (!onNewEmailReceived || isSearchActive) return;
    // If scoped to a mailbox, only refresh if the new email is in that mailbox
    if (activeMailboxId !== null && onNewEmailReceived.mailbox_id !== activeMailboxId) return;
    fetchThreads();
  }, [onNewEmailReceived]); // eslint-disable-line react-hooks/exhaustive-deps

  // Register onSent callback so compose dialog refreshes thread list
  useEffect(() => {
    setOnSent(() => {
      fetchThreads();
      refreshUnreadCount();
    });
  }, [setOnSent, fetchThreads, refreshUnreadCount]);

  // Update browser tab title with view label and unread count
  const viewLabel = viewLabels[isSearchActive ? "search" : currentView] || "Mail";
  usePageTitle(viewLabel, undefined, unreadCount);

  const handleSelectThread = async (thread: EmailThread) => {
    setSelectedThread(thread);
    setIsLoadingDetail(true);
    try {
      if (thread.emails_count > 1 || currentView === "inbox") {
        const res = await api.get<{ thread: { emails: Email[] } }>(`/email/threads/${thread.id}`);
        setSelectedEmails(res.data.thread.emails);
      } else {
        const emailId = thread.latest_email?.id || thread.id;
        const res = await api.get<{ email: Email }>(`/email/messages/${emailId}`);
        setSelectedEmails([res.data.email]);
      }
      // Optimistically mark the thread as read in the list (backend already marked it)
      const isUnread = thread.latest_email && !(thread.latest_email.effective_is_read ?? thread.latest_email.is_read);
      if (isUnread) {
        setThreads(prev => prev.map(t =>
          t.id === thread.id && t.latest_email
            ? { ...t, latest_email: { ...t.latest_email, is_read: true, effective_is_read: true } }
            : t
        ));
        refreshUnreadCount();
      }
    } catch {
      toast.error("Failed to load email");
    } finally {
      setIsLoadingDetail(false);
    }
  };

  const handleToggleStar = async (emailId: number) => {
    try {
      await api.patch(`/email/messages/${emailId}/star`);
      fetchThreads();
    } catch {
      toast.error("Failed to update");
    }
  };

  const handleMarkRead = async (emailId: number, isRead: boolean) => {
    try {
      await api.patch(`/email/messages/${emailId}/read`, { is_read: isRead });
      fetchThreads();
      refreshUnreadCount();
    } catch {
      toast.error("Failed to update");
    }
  };

  const handleTrash = async (emailId: number) => {
    try {
      await api.delete(`/email/messages/${emailId}`);
      toast.success("Moved to trash");
      setSelectedThread(null);
      setSelectedEmails([]);
      fetchThreads();
      refreshUnreadCount();
    } catch {
      toast.error("Failed to delete");
    }
  };

  const handleToggleSpam = async (emailId: number) => {
    // Find the email to check its current spam state and sender
    const email = selectedEmails.find((e) => e.id === emailId);
    const wasSpam = email?.is_spam ?? false;
    const senderAddress = email?.from_address;

    try {
      await api.patch(`/email/messages/${emailId}/spam`);
      await fetchThreads();
      // Update local state so a second toggle reads the correct is_spam value
      setSelectedEmails(prev =>
        prev.map(e => e.id === emailId ? { ...e, is_spam: !e.is_spam } : e)
      );

      if (senderAddress) {
        if (!wasSpam) {
          // Was not spam, now marked as spam — offer to block sender
          toast.success("Marked as spam", {
            action: {
              label: "Block sender",
              onClick: async () => {
                try {
                  await api.post("/email/spam-filter", {
                    type: "block",
                    match_type: "exact",
                    value: senderAddress,
                  });
                  toast.success(`Blocked ${senderAddress}`);
                } catch (err: unknown) {
                  const status = (err as { response?: { status?: number } })?.response?.status;
                  if (status === 422) {
                    toast.success(`${senderAddress} already blocked`);
                  } else {
                    toast.error(`Failed to block ${senderAddress}`);
                  }
                }
              },
            },
          });
        } else {
          // Was spam, now un-marked — offer to allow sender
          toast.success("Removed from spam", {
            action: {
              label: "Allow sender",
              onClick: async () => {
                try {
                  await api.post("/email/spam-filter", {
                    type: "allow",
                    match_type: "exact",
                    value: senderAddress,
                  });
                  toast.success(`Allowed ${senderAddress}`);
                } catch (err: unknown) {
                  const status = (err as { response?: { status?: number } })?.response?.status;
                  if (status === 422) {
                    toast.success(`${senderAddress} already allowed`);
                  } else {
                    toast.error(`Failed to allow ${senderAddress}`);
                  }
                }
              },
            },
          });
        }
      } else {
        toast.success("Updated");
      }
    } catch {
      toast.error("Failed to update");
    }
  };

  const handleReply = async (emailId: number) => {
    try {
      const res = await api.get<ReplyData>(`/email/messages/${emailId}/reply-data?type=reply`);
      ctxOpenReply(res.data);
    } catch {
      toast.error("Failed to load reply data");
    }
  };

  const handleReplyAll = async (emailId: number) => {
    try {
      const res = await api.get<ReplyData>(`/email/messages/${emailId}/reply-data?type=reply_all`);
      ctxOpenReplyAll(res.data);
    } catch {
      toast.error("Failed to load reply data");
    }
  };

  const handleForward = async (emailId: number) => {
    try {
      const res = await api.get<ReplyData>(`/email/messages/${emailId}/reply-data?type=forward`);
      ctxOpenForward(res.data);
    } catch {
      toast.error("Failed to load reply data");
    }
  };

  const handleUseSmartReply = async (emailId: number, text: string) => {
    try {
      const res = await api.get<ReplyData>(`/email/messages/${emailId}/reply-data?type=reply`);
      // Pre-fill compose with smart reply text
      ctxOpenReply({ ...res.data, prefill_body: text });
    } catch {
      toast.error("Failed to load reply data");
    }
  };

  // Keyboard shortcuts
  const selectedThreadIndex = useMemo(
    () => selectedThread ? threads.findIndex((t) => t.id === selectedThread.id) : -1,
    [threads, selectedThread]
  );

  const firstSelectedEmail = selectedEmails.length > 0 ? selectedEmails[selectedEmails.length - 1] : null;

  useMailKeyboard({
    onNextThread: () => {
      const nextIdx = selectedThreadIndex + 1;
      if (nextIdx < threads.length) {
        handleSelectThread(threads[nextIdx]);
      }
    },
    onPrevThread: () => {
      const prevIdx = selectedThreadIndex - 1;
      if (prevIdx >= 0) {
        handleSelectThread(threads[prevIdx]);
      }
    },
    onStar: () => {
      if (firstSelectedEmail) handleToggleStar(firstSelectedEmail.id);
    },
    onTrash: () => {
      if (firstSelectedEmail) handleTrash(firstSelectedEmail.id);
    },
    onReply: () => {
      if (firstSelectedEmail) handleReply(firstSelectedEmail.id);
    },
    onReplyAll: () => {
      if (firstSelectedEmail) handleReplyAll(firstSelectedEmail.id);
    },
    onForward: () => {
      if (firstSelectedEmail) handleForward(firstSelectedEmail.id);
    },
    onCompose: () => {
      openCompose();
    },
    onMarkUnread: () => {
      if (firstSelectedEmail) handleMarkRead(firstSelectedEmail.id, false);
    },
    onEscape: () => {
      setSelectedThread(null);
      setSelectedEmails([]);
    },
    onSearch: () => {
      // Focus the search input if it exists
      const searchInput = document.querySelector<HTMLInputElement>('[data-mail-search]');
      searchInput?.focus();
    },
  });

  // Mobile: show either list or detail
  if (isMobile) {
    if (selectedThread && selectedEmails.length > 0) {
      return (
        <div className="flex flex-col h-full">
          <EmailDetail
            emails={selectedEmails}
            threadId={selectedThread.id}
            isLoading={isLoadingDetail}
            onBack={() => {
              setSelectedThread(null);
              setSelectedEmails([]);
            }}
            onToggleStar={handleToggleStar}
            onMarkRead={handleMarkRead}
            onTrash={handleTrash}
            onToggleSpam={handleToggleSpam}
            onReply={handleReply}
            onReplyAll={handleReplyAll}
            onForward={handleForward}
            onUseSmartReply={handleUseSmartReply}
            onLabelsChanged={fetchThreads}
          />
        </div>
      );
    }

    return (
      <div className="flex flex-col h-full">
        <ThreadList
          threads={threads}
          isLoading={isLoading}
          selectedThreadId={null}
          currentView={isSearchActive ? "search" : currentView}
          onSelectThread={handleSelectThread}
          currentPage={currentPage}
          totalPages={totalPages}
          onPageChange={setCurrentPage}
          onSearch={handleSearch}
          onClearSearch={handleClearSearch}
          isSearchActive={isSearchActive}
          labels={labels}
          activeMailboxId={activeMailboxId}
          onBulkActionComplete={() => { fetchThreads(); refreshUnreadCount(); }}
          onToggleStar={handleToggleStar}
          onTrash={handleTrash}
          onMarkRead={handleMarkRead}
        />
      </div>
    );
  }

  // Desktop: two-panel layout — edge-to-edge, no floating card
  return (
    <>
    <MailCommandPalette open={commandPaletteOpen} onOpenChange={setCommandPaletteOpen} />
    <div className="flex h-full overflow-hidden bg-background">
      {/* Thread list */}
      <div className="border-r shrink-0 overflow-y-auto w-80 xl:w-96">
        <ThreadList
          threads={threads}
          isLoading={isLoading}
          selectedThreadId={selectedThread?.id ?? null}
          currentView={isSearchActive ? "search" : currentView}
          onSelectThread={handleSelectThread}
          currentPage={currentPage}
          totalPages={totalPages}
          onPageChange={setCurrentPage}
          onSearch={handleSearch}
          onClearSearch={handleClearSearch}
          isSearchActive={isSearchActive}
          labels={labels}
          activeMailboxId={activeMailboxId}
          onBulkActionComplete={() => { fetchThreads(); refreshUnreadCount(); }}
          onToggleStar={handleToggleStar}
          onTrash={handleTrash}
          onMarkRead={handleMarkRead}
        />
      </div>

      {/* Email detail / reading pane */}
      <div className="flex-1 overflow-y-auto">
        {selectedThread ? (
          <EmailDetail
            emails={selectedEmails}
            threadId={selectedThread.id}
            isLoading={isLoadingDetail}
            onToggleStar={handleToggleStar}
            onMarkRead={handleMarkRead}
            onTrash={handleTrash}
            onToggleSpam={handleToggleSpam}
            onReply={handleReply}
            onReplyAll={handleReplyAll}
            onForward={handleForward}
            onUseSmartReply={handleUseSmartReply}
            onLabelsChanged={fetchThreads}
          />
        ) : (
          <div className="flex flex-col items-center justify-center h-full text-muted-foreground">
            <MailOpen className="h-12 w-12 mb-3" />
            <p className="text-lg font-medium">Select an email</p>
            <p className="text-sm">Choose an email from the list to read it</p>
          </div>
        )}
      </div>
    </div>
    </>
  );
}
