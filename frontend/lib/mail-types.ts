export interface EmailThread {
  id: number;
  subject: string | null;
  last_message_at: string;
  message_count: number;
  emails_count: number;
  latest_email: {
    id: number;
    from_address: string;
    from_name: string | null;
    subject: string | null;
    preview_text?: string | null;
    is_read: boolean;
    is_starred?: boolean;
    effective_is_read?: boolean;
    effective_is_starred?: boolean;
    attachments_count?: number;
    sent_at: string;
    direction: string;
    mailbox_id: number;
    mailbox?: { id: number; address: string; domain_name?: string | null; email_domain?: { name: string } | null };
    recipients: Array<{ type: string; address: string; name: string | null }>;
  } | null;
}

export interface Email {
  id: number;
  mailbox_id: number;
  message_id: string;
  thread_id: number | null;
  direction: string;
  from_address: string;
  from_name: string | null;
  subject: string | null;
  body_text: string | null;
  body_html: string | null;
  is_read: boolean;
  is_starred: boolean;
  is_draft: boolean;
  is_spam: boolean;
  spam_score: number | null;
  is_trashed: boolean;
  effective_is_read?: boolean;
  effective_is_starred?: boolean;
  is_archived: boolean;
  delivery_status: string | null;
  sent_at: string;
  send_at: string | null;
  snoozed_until: string | null;
  recipients: Array<{ id: number; type: string; address: string; name: string | null }>;
  attachments: Array<{ id: number; filename: string; mime_type: string; size: number }>;
  labels: Array<{ id: number; name: string; color: string | null }>;
  mailbox?: { id: number; address: string; domain_name?: string | null; email_domain?: { name: string } | null };
}

export interface EmailLabel {
  id: number;
  name: string;
  color: string | null;
  emails_count: number;
}

export interface AccessibleMailbox {
  id: number;
  address: string;
  domain_name: string | null;
  display_name: string | null;
  email_domain: { id: number; name: string } | null;
  user_role: "viewer" | "member" | "owner";
  is_active: boolean;
}

export type MailView = "inbox" | "starred" | "sent" | "drafts" | "spam" | "trash" | "snoozed" | "label" | "search" | "priority";

/**
 * Get the full email address for a mailbox, handling orphaned mailboxes (null email_domain).
 */
export function getMailboxAddress(mailbox: { address: string; domain_name?: string | null; email_domain?: { name: string } | null }): string {
  const domain = mailbox.email_domain?.name ?? mailbox.domain_name;
  if (!domain) return mailbox.address;
  if (mailbox.address === "*") return `Catchall (${domain})`;
  return `${mailbox.address}@${domain}`;
}
