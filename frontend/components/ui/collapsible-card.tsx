"use client";

import * as React from "react";
import { ChevronDown } from "lucide-react";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { Card, CardContent, CardHeader } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";

export interface CollapsibleCardProps {
  /** Card title (always visible) */
  title: string;
  /** Optional description (visible in header) */
  description?: string;
  /** Optional icon component (React element or Lucide icon) */
  icon?: React.ReactNode;
  /** Status badge content (e.g., "Configured", "Enabled") */
  status?: { label: string; variant: "default" | "success" | "warning" | "destructive" };
  /** Whether card starts expanded */
  defaultOpen?: boolean;
  /** Disable expand/collapse interaction */
  disabled?: boolean;
  /** Controlled open state (optional) */
  open?: boolean;
  /** Callback when open state changes */
  onOpenChange?: (open: boolean) => void;
  /** Card content (shown when expanded) */
  children: React.ReactNode;
  /** Additional header content (right side, before chevron) */
  headerActions?: React.ReactNode;
  /** Additional CSS classes for the card */
  className?: string;
  /** Visual intent — "danger" adds a destructive border tint, "info" adds a primary tint */
  intent?: "default" | "danger" | "info";
}

const statusToBadgeVariant: Record<
  "default" | "success" | "warning" | "destructive",
  "default" | "secondary" | "destructive" | "outline" | "success" | "warning"
> = {
  default: "secondary",
  success: "success",
  warning: "warning",
  destructive: "destructive",
};

export function CollapsibleCard({
  title,
  description,
  icon,
  status,
  defaultOpen = false,
  disabled = false,
  open: controlledOpen,
  onOpenChange,
  children,
  headerActions,
  className,
  intent = "default",
}: CollapsibleCardProps) {
  const isControlled = controlledOpen !== undefined;
  const [uncontrolledOpen, setUncontrolledOpen] = React.useState(defaultOpen);
  const open = isControlled ? controlledOpen : uncontrolledOpen;

  const handleOpenChange = React.useCallback(
    (next: boolean) => {
      if (!isControlled) setUncontrolledOpen(next);
      onOpenChange?.(next);
    },
    [isControlled, onOpenChange]
  );

  const badgeVariant = status ? statusToBadgeVariant[status.variant] : undefined;

  return (
    <Collapsible
      open={open}
      onOpenChange={handleOpenChange}
      disabled={disabled}
      className={cn("group/card", className)}
      data-state={open ? "open" : "closed"}
    >
      <Card className={cn(
        intent === "danger" && "border-destructive/30",
        intent === "info" && "border-primary/20"
      )}>
        <CollapsibleTrigger
          asChild
          disabled={disabled}
          className="w-full text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background rounded-lg [&[data-state=open]>div]:rounded-b-none"
          aria-expanded={open}
        >
          <div className="group/trigger cursor-pointer select-none">
            <CardHeader className="flex flex-row items-start gap-3 p-6 pb-4">
              <div className="flex min-w-0 flex-1 items-start gap-3">
                {icon && (
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground group-data-[state=open]/card:bg-primary/10 group-data-[state=open]/card:text-primary">
                    {icon}
                  </div>
                )}
                <div className="min-w-0 flex-1 space-y-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold leading-none tracking-tight">{title}</span>
                    {status && (
                      <Badge variant={badgeVariant} className="shrink-0">
                        {status.label}
                      </Badge>
                    )}
                  </div>
                  {description && (
                    <p className="line-clamp-2 text-sm text-muted-foreground">{description}</p>
                  )}
                </div>
              </div>
              <div className="flex shrink-0 items-center gap-2">
                {headerActions}
                <ChevronDown
                  className="h-5 w-5 shrink-0 text-muted-foreground transition-transform duration-200 group-data-[state=open]/card:rotate-180"
                  aria-hidden
                />
              </div>
            </CardHeader>
          </div>
        </CollapsibleTrigger>
        <CollapsibleContent asChild>
          <div className="grid transition-[grid-template-rows] duration-200 ease-in-out grid-rows-[0fr] data-[state=open]:grid-rows-[1fr]">
            <div className="overflow-hidden">
              <CardContent className="p-6 pt-0">{children}</CardContent>
            </div>
          </div>
        </CollapsibleContent>
      </Card>
    </Collapsible>
  );
}
