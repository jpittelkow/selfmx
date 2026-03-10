"use client";

import type { AIProvider } from "./ai-types";
import { CollapsibleCard } from "@/components/ui/collapsible-card";
import { ProviderIcon } from "@/components/provider-icons";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { Label } from "@/components/ui/label";
import {
  Loader2,
  Pencil,
  Play,
  Star,
  StarOff,
  Trash2,
} from "lucide-react";

interface ProviderCardProps {
  provider: AIProvider;
  isTesting: boolean;
  onEdit: (provider: AIProvider) => void;
  onTest: (providerId: number, providerName: string) => void;
  onSetPrimary: (providerId: number) => void;
  onToggle: (providerId: number, enabled: boolean) => void;
  onDelete: (providerId: number) => void;
}

export function ProviderCard({
  provider,
  isTesting,
  onEdit,
  onTest,
  onSetPrimary,
  onToggle,
  onDelete,
}: ProviderCardProps) {
  return (
    <CollapsibleCard
      title={provider.provider.charAt(0).toUpperCase() + provider.provider.slice(1)}
      description={provider.model}
      icon={
        <ProviderIcon provider={provider.provider} size="sm" style="mono" />
      }
      status={{
        label: provider.is_primary
          ? "Primary"
          : provider.api_key_set
            ? "API Key Set"
            : "No API Key",
        variant: provider.is_primary
          ? "default"
          : provider.api_key_set
            ? "success"
            : "warning",
      }}
      defaultOpen={provider.is_primary}
      headerActions={
        <div className="flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
          <Button
            variant="ghost"
            size="icon"
            onClick={() => onEdit(provider)}
          >
            <Pencil className="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            onClick={() => onTest(provider.id, provider.provider)}
            disabled={isTesting}
          >
            {isTesting ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <Play className="h-4 w-4" />
            )}
          </Button>
          <Button
            variant="ghost"
            size="icon"
            onClick={() => onSetPrimary(provider.id)}
            disabled={provider.is_primary}
          >
            {provider.is_primary ? (
              <Star className="h-4 w-4 fill-current" />
            ) : (
              <StarOff className="h-4 w-4" />
            )}
          </Button>
          <Switch
            checked={provider.is_enabled}
            onCheckedChange={(checked) => onToggle(provider.id, checked)}
          />
          <Button
            variant="ghost"
            size="icon"
            onClick={() => onDelete(provider.id)}
            className="text-destructive hover:text-destructive"
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        </div>
      }
    >
      <div className="flex flex-wrap items-center gap-2 pt-2 border-t">
        <Button
          variant="outline"
          size="sm"
          onClick={() => onEdit(provider)}
        >
          <Pencil className="mr-2 h-4 w-4" />
          Edit settings
        </Button>
        <Button
          variant="outline"
          size="sm"
          onClick={() => onTest(provider.id, provider.provider)}
          disabled={isTesting}
        >
          {isTesting ? (
            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
          ) : (
            <Play className="mr-2 h-4 w-4" />
          )}
          Test connection
        </Button>
        <Button
          variant="outline"
          size="sm"
          onClick={() => onSetPrimary(provider.id)}
          disabled={provider.is_primary}
        >
          {provider.is_primary ? (
            <Star className="mr-2 h-4 w-4 fill-current" />
          ) : (
            <StarOff className="mr-2 h-4 w-4" />
          )}
          {provider.is_primary ? "Primary" : "Set as primary"}
        </Button>
        <div className="flex items-center gap-2">
          <Label className="text-sm text-muted-foreground">Enabled</Label>
          <Switch
            checked={provider.is_enabled}
            onCheckedChange={(checked) => onToggle(provider.id, checked)}
          />
        </div>
        <Button
          variant="outline"
          size="sm"
          onClick={() => onDelete(provider.id)}
          className="text-destructive hover:text-destructive"
        >
          <Trash2 className="mr-2 h-4 w-4" />
          Remove provider
        </Button>
      </div>
    </CollapsibleCard>
  );
}
