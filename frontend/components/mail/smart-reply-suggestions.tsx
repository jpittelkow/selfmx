"use client";

import { useState, useEffect, useCallback } from "react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { Sparkles } from "lucide-react";
import { api } from "@/lib/api";

interface ReplySuggestion {
  text: string;
  tone: string;
  action: string;
}

interface SmartReplySuggestionsProps {
  emailId: number;
  onUseReply: (text: string) => void;
}

export function SmartReplySuggestions({ emailId, onUseReply }: SmartReplySuggestionsProps) {
  const [suggestions, setSuggestions] = useState<ReplySuggestion[] | null>(null);
  const [loading, setLoading] = useState(false);
  const [fetched, setFetched] = useState(false);

  const fetchCached = useCallback(async () => {
    try {
      const res = await api.get(`/email/ai/email/${emailId}/replies`);
      if (res.data.data?.suggestions) {
        setSuggestions(res.data.data.suggestions);
      }
    } catch {
      // Silently fail
    } finally {
      setFetched(true);
    }
  }, [emailId]);

  const generate = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.post(`/email/ai/email/${emailId}/replies/generate`);
      if (res.data.data?.suggestions) {
        setSuggestions(res.data.data.suggestions);
      }
    } catch {
      // Silently fail
    } finally {
      setLoading(false);
    }
  }, [emailId]);

  // Fetch cached on mount
  useEffect(() => {
    fetchCached();
  }, [fetchCached]);

  // Loading skeleton
  if (loading) {
    return (
      <div className="flex flex-wrap gap-2">
        <Skeleton className="h-8 w-40 rounded-full" />
        <Skeleton className="h-8 w-36 rounded-full" />
        <Skeleton className="h-8 w-44 rounded-full" />
      </div>
    );
  }

  // Not yet fetched
  if (!fetched) return null;

  // No suggestions — show generate button
  if (!suggestions || suggestions.length === 0) {
    return (
      <Button
        variant="ghost"
        size="sm"
        onClick={generate}
        className="h-7 text-xs text-muted-foreground"
      >
        <Sparkles className="mr-1.5 h-3 w-3" />
        Suggest replies
      </Button>
    );
  }

  return (
    <div className="flex flex-wrap gap-2">
      {suggestions.map((s, i) => (
        <Button
          key={i}
          variant="outline"
          size="sm"
          onClick={() => onUseReply(s.text)}
          className="h-auto py-1.5 px-3 text-xs max-w-xs text-left whitespace-normal"
          title={`Tone: ${s.tone}`}
        >
          {s.text}
        </Button>
      ))}
    </div>
  );
}
