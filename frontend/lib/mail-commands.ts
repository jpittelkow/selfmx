import {
  Inbox,
  PenLine,
  Star,
  Send,
  FileEdit,
  Trash2,
  AlertOctagon,
  Search,
  Tag,
  Clock,
  Sparkles,
  Upload,
  type LucideIcon,
} from "lucide-react";

export interface MailCommand {
  id: string;
  label: string;
  icon: LucideIcon;
  keywords: string[];
  action: string; // action key to resolve at runtime
}

export const mailCommands: MailCommand[] = [
  { id: "compose", label: "Compose new email", icon: PenLine, keywords: ["write", "new", "create"], action: "compose" },
  { id: "go-inbox", label: "Go to Inbox", icon: Inbox, keywords: ["inbox", "home"], action: "navigate:inbox" },
  { id: "go-starred", label: "Go to Starred", icon: Star, keywords: ["starred", "favorites"], action: "navigate:starred" },
  { id: "go-sent", label: "Go to Sent", icon: Send, keywords: ["sent", "outbox"], action: "navigate:sent" },
  { id: "go-drafts", label: "Go to Drafts", icon: FileEdit, keywords: ["drafts"], action: "navigate:drafts" },
  { id: "go-snoozed", label: "Go to Snoozed", icon: Clock, keywords: ["snoozed", "later"], action: "navigate:snoozed" },
  { id: "go-spam", label: "Go to Spam", icon: AlertOctagon, keywords: ["spam", "junk"], action: "navigate:spam" },
  { id: "go-trash", label: "Go to Trash", icon: Trash2, keywords: ["trash", "deleted", "bin"], action: "navigate:trash" },
  { id: "go-priority", label: "Go to Priority", icon: Sparkles, keywords: ["priority", "important"], action: "navigate:priority" },
  { id: "search-mail", label: "Search emails", icon: Search, keywords: ["search", "find"], action: "search" },
  { id: "import", label: "Import emails", icon: Upload, keywords: ["import", "upload", "mbox", "eml"], action: "import" },
];
