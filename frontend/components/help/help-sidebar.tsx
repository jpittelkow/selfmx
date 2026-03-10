"use client";

import { cn } from "@/lib/utils";
import { ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";
import { type HelpCategory } from "@/lib/help/help-content";

interface HelpSidebarProps {
  categories: HelpCategory[];
  selectedCategory: string | null;
  selectedArticle: string | null;
  onSelectCategory: (categorySlug: string) => void;
  onSelectArticle: (articleId: string) => void;
  className?: string;
}

export function HelpSidebar({
  categories,
  selectedCategory,
  selectedArticle,
  onSelectCategory,
  onSelectArticle,
  className,
}: HelpSidebarProps) {
  return (
    <nav className={cn("space-y-1", className)}>
      {categories.map((category) => {
        const isExpanded = selectedCategory === category.slug;
        const hasSelectedArticle = category.articles.some(
          (a) => a.id === selectedArticle
        );

        return (
          <Collapsible
            key={category.slug}
            open={isExpanded}
            onOpenChange={() => onSelectCategory(category.slug)}
          >
            <CollapsibleTrigger asChild>
              <Button
                variant="ghost"
                className={cn(
                  "w-full justify-between px-3 py-2 h-auto text-sm font-medium",
                  isExpanded || hasSelectedArticle
                    ? "bg-accent text-accent-foreground"
                    : "text-muted-foreground hover:text-foreground"
                )}
              >
                <span className="flex items-center gap-2">
                  {category.icon && (
                    <category.icon className="h-4 w-4" />
                  )}
                  {category.name}
                </span>
                <span className="flex items-center gap-1.5">
                  <Badge variant="secondary" className="h-5 px-1.5 text-[10px] font-normal">
                    {category.articles.length}
                  </Badge>
                  <ChevronRight
                    className={cn(
                      "h-4 w-4 transition-transform duration-200",
                      isExpanded && "rotate-90"
                    )}
                  />
                </span>
              </Button>
            </CollapsibleTrigger>

            <CollapsibleContent className="overflow-hidden data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:fade-in-0 data-[state=closed]:fade-out-0 data-[state=open]:slide-in-from-top-1 data-[state=closed]:slide-out-to-top-1 duration-200">
              <div className="mt-1 ml-4 space-y-0.5">
                {category.articles.map((article) => (
                  <Button
                    key={article.id}
                    variant="ghost"
                    size="sm"
                    className={cn(
                      "w-full justify-start px-3 py-1.5 h-auto text-sm font-normal",
                      selectedArticle === article.id
                        ? "bg-primary/10 text-primary font-medium"
                        : "text-muted-foreground hover:text-foreground"
                    )}
                    onClick={() => onSelectArticle(article.id)}
                  >
                    {article.title}
                  </Button>
                ))}
              </div>
            </CollapsibleContent>
          </Collapsible>
        );
      })}
    </nav>
  );
}
