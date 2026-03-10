"use client";

import { HelpCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useHelp } from "@/components/help/help-provider";
import { cn } from "@/lib/utils";

interface HelpLinkProps {
  articleId: string;
  label?: string;
  variant?: "inline" | "button";
  className?: string;
}

export function HelpLink({
  articleId,
  label = "Learn more",
  variant = "inline",
  className,
}: HelpLinkProps) {
  const { openArticle } = useHelp();

  if (variant === "button") {
    return (
      <Button
        type="button"
        variant="ghost"
        size="sm"
        className={cn("gap-1.5", className)}
        onClick={() => openArticle(articleId)}
      >
        <HelpCircle className="h-3.5 w-3.5" />
        {label}
      </Button>
    );
  }

  return (
    <button
      type="button"
      onClick={() => openArticle(articleId)}
      className={cn(
        "inline-flex items-center gap-1 text-sm text-primary hover:underline",
        className
      )}
    >
      {label}
      <HelpCircle className="h-3 w-3" />
    </button>
  );
}
