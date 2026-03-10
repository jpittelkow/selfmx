"use client";

import { useRouter } from "next/navigation";
import { Bell, CheckCheck } from "lucide-react";
import { useNotifications } from "@/lib/notifications";
import { Button } from "@/components/ui/button";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";
import { NotificationItem } from "./notification-item";
import { useIsMobile } from "@/lib/use-mobile";

const DROPDOWN_LIMIT = 8;

export interface NotificationDropdownProps {
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  trigger: React.ReactNode;
}

export function NotificationDropdown({
  open,
  onOpenChange,
  trigger,
}: NotificationDropdownProps) {
  const router = useRouter();
  const isMobile = useIsMobile();
  const {
    notifications,
    unreadCount,
    isLoading,
    markAsRead,
    markAllAsRead,
  } = useNotifications();

  const recent = notifications.slice(0, DROPDOWN_LIMIT);
  const hasUnread = unreadCount > 0;

  const handleMarkRead = (id: string) => {
    markAsRead([id]);
  };

  const handleMarkAllRead = () => {
    markAllAsRead();
  };

  const handleViewAll = () => {
    onOpenChange?.(false);
    router.push("/notifications");
  };

  const handleItemClick = () => {
    onOpenChange?.(false);
  };

  const content = (
    <div className="flex flex-col w-full">
      <div className="flex items-center justify-between px-1 pb-2">
        <span className="text-sm font-semibold">Notifications</span>
        {hasUnread && (
          <Button
            variant="ghost"
            size="sm"
            className="h-7 text-xs"
            onClick={handleMarkAllRead}
          >
            <CheckCheck className="mr-1 h-3.5 w-3.5" />
            Mark all read
          </Button>
        )}
      </div>
      <div className="space-y-1 max-h-[min(60vh,400px)] overflow-y-auto -mx-1 px-1">
        {isLoading ? (
          <div className="py-6 text-center text-sm text-muted-foreground">
            Loading…
          </div>
        ) : recent.length === 0 ? (
          <div className="py-10 text-center text-muted-foreground space-y-2">
            <Bell className="mx-auto h-12 w-12 opacity-30" />
            <p className="font-heading font-medium">No notifications</p>
            <p className="text-xs text-muted-foreground">When you receive notifications, they&apos;ll appear here.</p>
          </div>
        ) : (
          recent.map((n, i) => (
            <div
              key={n.id}
              className={`animate-in fade-in slide-in-from-bottom-1 duration-300 notification-stagger-${i}`}
            >
              <NotificationItem
                notification={n}
                compact
                showMarkRead
                onMarkRead={handleMarkRead}
                onClick={handleItemClick}
              />
            </div>
          ))
        )}
      </div>
      {recent.length > 0 && (
        <div className="pt-2 mt-2 border-t">
          <Button
            variant="ghost"
            size="sm"
            className="w-full text-xs"
            onClick={handleViewAll}
          >
            View all
          </Button>
        </div>
      )}
    </div>
  );

  if (isMobile) {
    return (
      <Sheet open={open} onOpenChange={onOpenChange}>
        <SheetTrigger asChild>{trigger}</SheetTrigger>
        <SheetContent side="right" className="w-full sm:max-w-md">
          <SheetHeader>
            <SheetTitle>Notifications</SheetTitle>
          </SheetHeader>
          <div className="mt-4">{content}</div>
        </SheetContent>
      </Sheet>
    );
  }

  return (
    <Popover open={open} onOpenChange={onOpenChange}>
      <PopoverTrigger asChild>{trigger}</PopoverTrigger>
      <PopoverContent align="end" className="w-[380px] p-0">
        <div className="p-3">{content}</div>
      </PopoverContent>
    </Popover>
  );
}
