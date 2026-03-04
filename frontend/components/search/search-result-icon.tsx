import { User, Bell, Mail, Key, Bot, Webhook, FileText, Users, MessageSquareText, Contact, type LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";

const TYPE_ICONS: Record<string, LucideIcon> = {
  user: User,
  group: Users,
  notification: Bell,
  email_template: Mail,
  notification_template: MessageSquareText,
  api_token: Key,
  ai_provider: Bot,
  webhook: Webhook,
  page: FileText,
  emails: Mail,
  contacts: Contact,
};

interface SearchResultIconProps {
  type: string;
  className?: string;
}

export function SearchResultIcon({ type: typeKey, className }: SearchResultIconProps) {
  const Icon = TYPE_ICONS[typeKey] ?? User;
  return (
    <Icon
      className={cn("h-4 w-4 shrink-0 text-muted-foreground", className)}
      aria-hidden
    />
  );
}
