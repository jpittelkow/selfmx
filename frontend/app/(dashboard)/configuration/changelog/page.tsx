"use client";

import { useState, useEffect, useCallback } from "react";
import { api } from "@/lib/api";
import { errorLogger } from "@/lib/error-logger";
import { getErrorMessage, formatDate as utilFormatDate } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";
import {
  ChevronDown,
  ChevronRight,
  Plus,
  Wrench,
  Bug,
  Trash2,
  ShieldCheck,
  RefreshCw,
  FileText,
} from "lucide-react";
import { HelpLink } from "@/components/help/help-link";

// Types
interface ChangelogEntry {
  version: string;
  date: string | null;
  categories: Record<string, string[]>;
}

interface ChangelogMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

// Category display config with dark mode variants
const CATEGORY_CONFIG: Record<
  string,
  { label: string; icon: React.ElementType; className: string }
> = {
  added: {
    label: "Added",
    icon: Plus,
    className: "bg-green-500/10 text-green-600 dark:text-green-400 border-green-500/20",
  },
  changed: {
    label: "Changed",
    icon: RefreshCw,
    className: "bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20",
  },
  fixed: {
    label: "Fixed",
    icon: Bug,
    className: "bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20",
  },
  removed: {
    label: "Removed",
    icon: Trash2,
    className: "bg-red-500/10 text-red-600 dark:text-red-400 border-red-500/20",
  },
  security: {
    label: "Security",
    icon: ShieldCheck,
    className: "bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20",
  },
  deprecated: {
    label: "Deprecated",
    icon: Wrench,
    className: "bg-orange-500/10 text-orange-600 dark:text-orange-400 border-orange-500/20",
  },
};

function getCategoryConfig(category: string) {
  return CATEGORY_CONFIG[category] ?? {
    label: category.charAt(0).toUpperCase() + category.slice(1),
    icon: FileText,
    className: "bg-muted text-muted-foreground border-border",
  };
}

function formatDate(dateStr: string | null): string {
  if (!dateStr) return "";
  // Append T00:00:00 to prevent timezone-shifted dates, then use the shared utility
  return utilFormatDate(dateStr + "T00:00:00", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
}

// Loading skeleton
function ChangelogSkeleton() {
  return (
    <div className="space-y-4">
      {[1, 2, 3].map((i) => (
        <Card key={i}>
          <CardHeader>
            <div className="flex items-center gap-3">
              <Skeleton className="h-6 w-20" />
              <Skeleton className="h-4 w-32" />
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <Skeleton className="h-5 w-16" />
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-4 w-3/4" />
            </div>
            <div className="space-y-2">
              <Skeleton className="h-5 w-16" />
              <Skeleton className="h-4 w-5/6" />
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
  );
}

// Single version entry
function VersionEntry({
  entry,
  defaultOpen,
}: {
  entry: ChangelogEntry;
  defaultOpen: boolean;
}) {
  const [isOpen, setIsOpen] = useState(defaultOpen);
  const categoryKeys = Object.keys(entry.categories);
  const totalChanges = categoryKeys.reduce(
    (sum, key) => sum + entry.categories[key].length,
    0
  );

  return (
    <Collapsible open={isOpen} onOpenChange={setIsOpen}>
      <Card>
        <CollapsibleTrigger asChild>
          <CardHeader className="cursor-pointer hover:bg-muted/50 transition-colors">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3 flex-wrap">
                <Badge variant="default" className="text-sm">
                  v{entry.version}
                </Badge>
                {entry.date && (
                  <span className="text-sm text-muted-foreground">
                    {formatDate(entry.date)}
                  </span>
                )}
                <span className="text-xs text-muted-foreground">
                  {totalChanges} {totalChanges === 1 ? "change" : "changes"}
                </span>
              </div>
              <div className="flex-shrink-0 text-muted-foreground">
                {isOpen ? (
                  <ChevronDown className="h-4 w-4" />
                ) : (
                  <ChevronRight className="h-4 w-4" />
                )}
              </div>
            </div>
          </CardHeader>
        </CollapsibleTrigger>
        <CollapsibleContent>
          <CardContent className="pt-0 space-y-4">
            {categoryKeys.map((category) => {
              const config = getCategoryConfig(category);
              const Icon = config.icon;
              const items = entry.categories[category];

              return (
                <div key={category}>
                  <div className="flex items-center gap-2 mb-2">
                    <Badge
                      variant="outline"
                      className={config.className}
                    >
                      <Icon className="h-3 w-3 mr-1" />
                      {config.label}
                    </Badge>
                  </div>
                  <ul className="space-y-1 ml-4 list-disc">
                    {items.map((item, idx) => (
                      <li
                        key={idx}
                        className="text-sm text-foreground text-muted-foreground"
                      >
                        <span className="text-foreground">{item}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              );
            })}
          </CardContent>
        </CollapsibleContent>
      </Card>
    </Collapsible>
  );
}

export default function ChangelogPage() {
  const [entries, setEntries] = useState<ChangelogEntry[]>([]);
  const [meta, setMeta] = useState<ChangelogMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);

  const fetchChangelog = useCallback(async (pageNum: number) => {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get("/changelog", {
        params: { page: pageNum, per_page: 10 },
      });
      setEntries(response.data.data);
      setMeta(response.data.meta);
    } catch (err) {
      const message = getErrorMessage(err, "Failed to load changelog");
      setError(message);
      errorLogger.captureMessage("Failed to load changelog", "error", { component: "ChangelogPage", error: message });
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchChangelog(page);
  }, [page, fetchChangelog]);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Changelog</h1>
        <p className="text-muted-foreground mt-1">
          Version history and release notes.{" "}
          <HelpLink articleId="changelog" />
        </p>
      </div>

      {loading && <ChangelogSkeleton />}

      {error && (
        <Card>
          <CardContent className="py-8 text-center">
            <p className="text-destructive">{error}</p>
            <Button
              variant="outline"
              size="sm"
              className="mt-4"
              onClick={() => fetchChangelog(page)}
            >
              Try again
            </Button>
          </CardContent>
        </Card>
      )}

      {!loading && !error && entries.length === 0 && (
        <Card>
          <CardContent className="py-12 text-center">
            <FileText className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
            <CardTitle className="text-lg mb-2">No changelog entries yet</CardTitle>
            <p className="text-sm text-muted-foreground">
              Release notes will appear here as new versions are published.
            </p>
          </CardContent>
        </Card>
      )}

      {!loading && !error && entries.length > 0 && (
        <>
          <div className="space-y-4">
            {entries.map((entry, index) => (
              <VersionEntry
                key={entry.version}
                entry={entry}
                defaultOpen={page === 1 && index === 0}
              />
            ))}
          </div>

          {/* Pagination */}
          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-center gap-2 pt-2">
              <Button
                variant="outline"
                size="sm"
                disabled={page <= 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
              >
                Previous
              </Button>
              <span className="text-sm text-muted-foreground px-2">
                Page {meta.current_page} of {meta.last_page}
              </span>
              <Button
                variant="outline"
                size="sm"
                disabled={page >= meta.last_page}
                onClick={() => setPage((p) => p + 1)}
              >
                Next
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
