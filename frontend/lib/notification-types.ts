import {
  Archive,
  ShieldAlert,
  Settings,
  Brain,
  HardDrive,
  AlertTriangle,
  DollarSign,
  CreditCard,
  type LucideIcon,
} from "lucide-react";

export type NotificationCategory =
  | "backup"
  | "auth"
  | "system"
  | "llm"
  | "storage"
  | "security"
  | "usage"
  | "payment";

interface NotificationTypeMeta {
  label: string;
  category: NotificationCategory;
  icon: LucideIcon;
  actionUrl?: string;
  color: string;
  bg: string;
}

const NOTIFICATION_TYPE_MAP: Record<string, NotificationTypeMeta> = {
  "backup.completed": { label: "Backup Completed", category: "backup", icon: Archive, actionUrl: "/configuration/backup", color: "text-green-600 dark:text-green-400", bg: "bg-green-100 dark:bg-green-900/30" },
  "backup.failed": { label: "Backup Failed", category: "backup", icon: Archive, actionUrl: "/configuration/backup", color: "text-red-600 dark:text-red-400", bg: "bg-red-100 dark:bg-red-900/30" },
  "auth.login": { label: "New Sign-in", category: "auth", icon: ShieldAlert, actionUrl: "/user/preferences", color: "text-blue-600 dark:text-blue-400", bg: "bg-blue-100 dark:bg-blue-900/30" },
  "auth.password_reset": { label: "Password Changed", category: "auth", icon: ShieldAlert, actionUrl: "/user/preferences", color: "text-blue-600 dark:text-blue-400", bg: "bg-blue-100 dark:bg-blue-900/30" },
  "system.update": { label: "Update Available", category: "system", icon: Settings, actionUrl: "/configuration", color: "text-blue-600 dark:text-blue-400", bg: "bg-blue-100 dark:bg-blue-900/30" },
  "llm.quota_warning": { label: "Quota Warning", category: "llm", icon: Brain, actionUrl: "/configuration", color: "text-amber-600 dark:text-amber-400", bg: "bg-amber-100 dark:bg-amber-900/30" },
  "storage.warning": { label: "Storage Warning", category: "storage", icon: HardDrive, actionUrl: "/configuration/storage", color: "text-amber-600 dark:text-amber-400", bg: "bg-amber-100 dark:bg-amber-900/30" },
  "storage.critical": { label: "Storage Critical", category: "storage", icon: HardDrive, actionUrl: "/configuration/storage", color: "text-red-600 dark:text-red-400", bg: "bg-red-100 dark:bg-red-900/30" },
  "suspicious_activity": { label: "Suspicious Activity", category: "security", icon: AlertTriangle, actionUrl: "/configuration/audit", color: "text-red-600 dark:text-red-400", bg: "bg-red-100 dark:bg-red-900/30" },
  "usage.budget_warning": { label: "Budget Warning", category: "usage", icon: DollarSign, actionUrl: "/configuration/ai", color: "text-amber-600 dark:text-amber-400", bg: "bg-amber-100 dark:bg-amber-900/30" },
  "usage.budget_exceeded": { label: "Budget Exceeded", category: "usage", icon: DollarSign, actionUrl: "/configuration/ai", color: "text-red-600 dark:text-red-400", bg: "bg-red-100 dark:bg-red-900/30" },
  "payment.succeeded": { label: "Payment Succeeded", category: "payment", icon: CreditCard, actionUrl: "/configuration/payments", color: "text-green-600 dark:text-green-400", bg: "bg-green-100 dark:bg-green-900/30" },
  "payment.failed": { label: "Payment Failed", category: "payment", icon: CreditCard, actionUrl: "/configuration/payments", color: "text-red-600 dark:text-red-400", bg: "bg-red-100 dark:bg-red-900/30" },
  "payment.refunded": { label: "Payment Refunded", category: "payment", icon: CreditCard, actionUrl: "/configuration/payments", color: "text-blue-600 dark:text-blue-400", bg: "bg-blue-100 dark:bg-blue-900/30" },
};

export const CHANNEL_GROUP_LABELS: Record<string, string> = {
  push: "Push",
  inapp: "In-App",
  chat: "Chat",
  email: "Email",
};

const CATEGORY_LABELS: Record<NotificationCategory, string> = {
  backup: "Backup",
  auth: "Authentication",
  system: "System",
  llm: "AI / LLM",
  storage: "Storage",
  security: "Security",
  usage: "Usage & Costs",
  payment: "Payments",
};

const DEFAULT_META: NotificationTypeMeta = {
  label: "Notification",
  category: "system",
  icon: Settings,
  color: "text-muted-foreground",
  bg: "bg-muted",
};

export function getNotificationType(type: string): NotificationTypeMeta {
  return NOTIFICATION_TYPE_MAP[type] ?? DEFAULT_META;
}

export function getNotificationTypeLabel(type: string): string {
  return NOTIFICATION_TYPE_MAP[type]?.label ?? formatTypeString(type);
}

export function getNotificationTypeIcon(type: string): LucideIcon {
  return NOTIFICATION_TYPE_MAP[type]?.icon ?? DEFAULT_META.icon;
}

export function getNotificationCategory(type: string): NotificationCategory {
  return NOTIFICATION_TYPE_MAP[type]?.category ?? "system";
}

export function getCategoryLabel(category: NotificationCategory): string {
  return CATEGORY_LABELS[category] ?? category;
}

export function getDefaultActionUrl(type: string): string | undefined {
  return NOTIFICATION_TYPE_MAP[type]?.actionUrl;
}

/** Convert "backup.completed" → "Backup Completed" as fallback */
function formatTypeString(type: string): string {
  return type
    .replace(/[._]/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

/** Get all known categories for filter UIs */
export function getAllCategories(): { value: NotificationCategory; label: string }[] {
  return Object.entries(CATEGORY_LABELS).map(([value, label]) => ({
    value: value as NotificationCategory,
    label,
  }));
}

/** Get all known notification types grouped by category */
export function getTypesByCategory(): { category: NotificationCategory; categoryLabel: string; types: { type: string; label: string; icon: LucideIcon }[] }[] {
  const grouped: Record<string, { type: string; label: string; icon: LucideIcon }[]> = {};
  for (const [type, meta] of Object.entries(NOTIFICATION_TYPE_MAP)) {
    if (!grouped[meta.category]) grouped[meta.category] = [];
    grouped[meta.category].push({ type, label: meta.label, icon: meta.icon });
  }
  return Object.entries(CATEGORY_LABELS)
    .filter(([cat]) => grouped[cat]?.length)
    .map(([cat, label]) => ({
      category: cat as NotificationCategory,
      categoryLabel: label,
      types: grouped[cat],
    }));
}
