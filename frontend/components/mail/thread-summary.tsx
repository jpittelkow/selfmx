"use client";

import { useState, useEffect, useCallback } from "react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";
import { Sparkles, ChevronDown, RefreshCw, ListChecks, Lightbulb } from "lucide-react";
import { cn } from "@/lib/utils";
import { api } from "@/lib/api";

interface ThreadSummaryProps {
  threadId: number;
}

interface SummaryData {
  summary: string;
  key_points: string[];
  action_items: string[];
}

export function ThreadSummary({ threadId }: ThreadSummaryProps) {
  const [data, setData] = useState<SummaryData | null>(null);
  const [stale, setStale] = useState(false);
  const [loading, setLoading] = useState(false);
  const [open, setOpen] = useState(true);
  const [fetched, setFetched] = useState(false);

  const fetchSummary = useCallback(async () => {
    try {
      const res = await api.get(`/email/ai/thread/${threadId}/summary`);
      setData(res.data.data);
      setStale(res.data.stale ?? false);
    } catch {
      // Silently fail — AI may not be available
    } finally {
      setFetched(true);
    }
  }, [threadId]);

  const generateSummary = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.post(`/email/ai/thread/${threadId}/summarize`);
      setData(res.data.data);
      setStale(false);
    } catch {
      // Silently fail
    } finally {
      setLoading(false);
    }
  }, [threadId]);

  useEffect(() => {
    fetchSummary();
  }, [fetchSummary]);

  // Don't render until we've checked for existing summary
  if (!fetched) return null;

  // No summary exists — show generate button
  if (!data && !loading) {
    return (
      <div className="px-6 py-3 border-b bg-muted/30">
        <Button
          variant="outline"
          size="sm"
          onClick={generateSummary}
          className="h-7 text-xs"
        >
          <Sparkles className="mr-1.5 h-3 w-3" />
          Summarize thread
        </Button>
      </div>
    );
  }

  // Loading state
  if (loading && !data) {
    return (
      <div className="px-6 py-3 border-b bg-muted/30 space-y-2">
        <Skeleton className="h-4 w-3/4" />
        <Skeleton className="h-3 w-1/2" />
        <Skeleton className="h-3 w-2/3" />
      </div>
    );
  }

  if (!data) return null;

  return (
    <Collapsible open={open} onOpenChange={setOpen}>
      <div className="border-b bg-muted/30">
        <CollapsibleTrigger asChild>
          <button className="w-full px-6 py-3 flex items-center justify-between hover:bg-muted/50 transition-colors">
            <div className="flex items-center gap-2 text-sm">
              <Sparkles className="h-3.5 w-3.5 text-primary" />
              <span className="font-medium">AI Summary</span>
              {stale && (
                <span className="text-xs text-muted-foreground">(outdated)</span>
              )}
            </div>
            <ChevronDown
              className={cn(
                "h-4 w-4 text-muted-foreground transition-transform",
                open && "rotate-180"
              )}
            />
          </button>
        </CollapsibleTrigger>

        <CollapsibleContent>
          <div className="px-6 pb-4 space-y-3">
            {/* Summary text */}
            <p className="text-sm text-foreground leading-relaxed">{data.summary}</p>

            {/* Key points */}
            {data.key_points && data.key_points.length > 0 && (
              <div>
                <div className="flex items-center gap-1.5 mb-1">
                  <Lightbulb className="h-3 w-3 text-muted-foreground" />
                  <span className="text-xs font-medium text-muted-foreground">Key Points</span>
                </div>
                <ul className="space-y-0.5 ml-4">
                  {data.key_points.map((point, i) => (
                    <li key={i} className="text-sm text-muted-foreground list-disc">
                      {point}
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {/* Action items */}
            {data.action_items && data.action_items.length > 0 && (
              <div>
                <div className="flex items-center gap-1.5 mb-1">
                  <ListChecks className="h-3 w-3 text-muted-foreground" />
                  <span className="text-xs font-medium text-muted-foreground">Action Items</span>
                </div>
                <ul className="space-y-0.5 ml-4">
                  {data.action_items.map((item, i) => (
                    <li key={i} className="text-sm text-muted-foreground list-disc">
                      {item}
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {/* Refresh button when stale */}
            {stale && (
              <Button
                variant="ghost"
                size="sm"
                onClick={generateSummary}
                disabled={loading}
                className="h-7 text-xs"
              >
                <RefreshCw className={cn("mr-1.5 h-3 w-3", loading && "animate-spin")} />
                {loading ? "Refreshing..." : "Refresh summary"}
              </Button>
            )}
          </div>
        </CollapsibleContent>
      </div>
    </Collapsible>
  );
}
