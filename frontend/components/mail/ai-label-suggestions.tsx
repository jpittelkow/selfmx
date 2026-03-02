"use client";

import { useState, useEffect, useCallback } from "react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Sparkles, Check, Plus, X } from "lucide-react";
import { api } from "@/lib/api";
import { toast } from "sonner";

interface Suggestion {
  name: string;
  existing_label_id: number | null;
  confidence: number;
  reason?: string;
}

interface AILabelSuggestionsProps {
  emailId: number;
  existingLabelIds: number[];
  onLabelsChanged?: () => void;
}

export function AILabelSuggestions({
  emailId,
  existingLabelIds,
  onLabelsChanged,
}: AILabelSuggestionsProps) {
  const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
  const [dismissed, setDismissed] = useState(false);
  const [applying, setApplying] = useState<Set<string>>(new Set());

  const labelIdsKey = existingLabelIds.join(",");
  const fetchSuggestions = useCallback(async () => {
    try {
      const res = await api.get(`/email/ai/email/${emailId}/labels`);
      if (res.data.data?.suggested_labels) {
        // Filter out labels already applied
        const filtered = res.data.data.suggested_labels.filter(
          (s: Suggestion) =>
            !s.existing_label_id || !existingLabelIds.includes(s.existing_label_id)
        );
        setSuggestions(filtered);
      }
    } catch {
      // Silently fail
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [emailId, labelIdsKey]);

  useEffect(() => {
    // Check localStorage for dismissal
    const key = `ai-label-dismissed-${emailId}`;
    if (localStorage.getItem(key)) {
      setDismissed(true);
      return;
    }
    fetchSuggestions();
  }, [fetchSuggestions, emailId]);

  const handleApply = async (suggestion: Suggestion) => {
    const key = suggestion.name;
    setApplying((prev) => new Set(prev).add(key));

    try {
      if (suggestion.existing_label_id) {
        await api.post(`/email/ai/email/${emailId}/labels/apply`, {
          label_ids: [suggestion.existing_label_id],
        });
      } else {
        await api.post(`/email/ai/email/${emailId}/labels/apply`, {
          label_ids: [],
          new_labels: [{ name: suggestion.name }],
        });
      }
      setSuggestions((prev) => prev.filter((s) => s.name !== key));
      onLabelsChanged?.();
      toast.success(`Label "${suggestion.name}" applied`);
    } catch {
      toast.error("Failed to apply label");
    } finally {
      setApplying((prev) => {
        const next = new Set(prev);
        next.delete(key);
        return next;
      });
    }
  };

  const handleApplyAll = async () => {
    const existingIds = suggestions
      .filter((s) => s.existing_label_id && s.confidence >= 0.7)
      .map((s) => s.existing_label_id as number);
    const newLabels = suggestions
      .filter((s) => !s.existing_label_id && s.confidence >= 0.7)
      .map((s) => ({ name: s.name }));

    try {
      await api.post(`/email/ai/email/${emailId}/labels/apply`, {
        label_ids: existingIds,
        new_labels: newLabels,
      });
      setSuggestions([]);
      onLabelsChanged?.();
      toast.success("Labels applied");
    } catch {
      toast.error("Failed to apply labels");
    }
  };

  const handleDismiss = () => {
    localStorage.setItem(`ai-label-dismissed-${emailId}`, "1");
    setDismissed(true);
  };

  if (dismissed || suggestions.length === 0) return null;

  return (
    <div className="flex items-center gap-2 flex-wrap">
      <span className="flex items-center gap-1 text-xs text-muted-foreground">
        <Sparkles className="h-3 w-3" />
        Suggested:
      </span>
      {suggestions.map((suggestion) => (
        <Badge
          key={suggestion.name}
          variant="outline"
          className="text-xs cursor-pointer hover:bg-muted gap-1 pr-1"
          onClick={() => handleApply(suggestion)}
          title={suggestion.reason || `Confidence: ${Math.round(suggestion.confidence * 100)}%`}
        >
          {applying.has(suggestion.name) ? (
            <span className="animate-pulse">...</span>
          ) : (
            <>
              {suggestion.existing_label_id ? (
                <Check className="h-2.5 w-2.5" />
              ) : (
                <Plus className="h-2.5 w-2.5" />
              )}
              {suggestion.name}
              <span className="text-muted-foreground ml-0.5">
                {Math.round(suggestion.confidence * 100)}%
              </span>
            </>
          )}
        </Badge>
      ))}
      {suggestions.filter((s) => s.confidence >= 0.7).length > 1 && (
        <Button
          variant="ghost"
          size="sm"
          onClick={handleApplyAll}
          className="h-5 text-xs px-2"
        >
          Apply all
        </Button>
      )}
      <Button
        variant="ghost"
        size="icon"
        onClick={handleDismiss}
        className="h-5 w-5"
        title="Dismiss suggestions"
      >
        <X className="h-3 w-3" />
      </Button>
    </div>
  );
}
