"use client";

import { useState, useEffect, useCallback } from "react";
import { api } from "@/lib/api";
import type { DiscoveredModel } from "./ai-types";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Loader2, RefreshCw } from "lucide-react";

const LLM_MODELS_CACHE_KEY = "llm_discovered_models";
const LLM_MODELS_CACHE_TTL_MS = 60 * 60 * 1000; // 1 hour

export function getCachedModels(provider: string): DiscoveredModel[] | null {
  if (typeof sessionStorage === "undefined") return null;
  try {
    const raw = sessionStorage.getItem(`${LLM_MODELS_CACHE_KEY}_${provider}`);
    if (!raw) return null;
    const { models, ts } = JSON.parse(raw) as { models: DiscoveredModel[]; ts: number };
    if (Date.now() - ts > LLM_MODELS_CACHE_TTL_MS) return null;
    return models;
  } catch {
    return null;
  }
}

export function setCachedModels(provider: string, models: DiscoveredModel[]) {
  try {
    sessionStorage.setItem(
      `${LLM_MODELS_CACHE_KEY}_${provider}`,
      JSON.stringify({ models, ts: Date.now() })
    );
  } catch {
    // ignore
  }
}

export function clearCachedModels(provider: string) {
  try {
    sessionStorage.removeItem(`${LLM_MODELS_CACHE_KEY}_${provider}`);
  } catch {
    // ignore
  }
}

interface ProviderModelSelectionProps {
  templateId: string;
  isEditMode: boolean;
  model: string;
  setModel: (v: string) => void;
  apiKey: string;
  baseUrl: string;
  endpoint: string;
  region: string;
  accessKey: string;
  secretKey: string;
  supportsDiscovery: boolean;
  requiresApiKey: boolean;
  discoveredModels: DiscoveredModel[];
  setDiscoveredModels: (models: DiscoveredModel[]) => void;
}

export function ProviderModelSelection({
  templateId,
  isEditMode,
  model,
  setModel,
  apiKey,
  baseUrl,
  endpoint,
  region,
  accessKey,
  secretKey,
  supportsDiscovery,
  requiresApiKey,
  discoveredModels,
  setDiscoveredModels,
}: ProviderModelSelectionProps) {
  const [isDiscovering, setIsDiscovering] = useState(false);
  const [discoveryError, setDiscoveryError] = useState<string | null>(null);

  const discoverModels = useCallback(async () => {
    if (templateId === "ollama") {
      if (!baseUrl?.trim()) {
        setDiscoveryError("Enter Ollama host first");
        return;
      }
    } else if (templateId === "azure") {
      if (!endpoint?.trim()) {
        setDiscoveryError("Enter Azure OpenAI endpoint first");
        return;
      }
      if (!apiKey?.trim()) {
        setDiscoveryError("Enter your API key first");
        return;
      }
    } else if (templateId === "bedrock") {
      if (!accessKey?.trim() || !secretKey?.trim()) {
        setDiscoveryError("Enter AWS access key and secret key first");
        return;
      }
    } else if (!apiKey?.trim()) {
      setDiscoveryError("Enter your API key first");
      return;
    }
    setIsDiscovering(true);
    setDiscoveryError(null);
    try {
      const response = await api.post("/llm-settings/discover-models", {
        provider: templateId,
        api_key: apiKey || undefined,
        host: templateId === "ollama" ? (baseUrl || "http://localhost:11434") : undefined,
        endpoint: templateId === "azure" ? endpoint || undefined : undefined,
        region: templateId === "bedrock" ? region || undefined : undefined,
        access_key: templateId === "bedrock" ? accessKey || undefined : undefined,
        secret_key: templateId === "bedrock" ? secretKey || undefined : undefined,
      });
      const models = response.data.models ?? [];
      setDiscoveredModels(models);
      if (templateId) {
        setCachedModels(templateId, models);
      }
      if (models.length === 0) {
        setDiscoveryError("No models returned. Check your API key or host.");
      }
    } catch (err: unknown) {
      const data = err && typeof err === "object" && "response" in err
        ? (err as { response?: { data?: { error?: string; message?: string } } }).response?.data
        : null;
      setDiscoveryError(data?.message ?? data?.error ?? "Failed to fetch models. Check your API key.");
    } finally {
      setIsDiscovering(false);
    }
  }, [templateId, apiKey, baseUrl, endpoint, region, accessKey, secretKey, setDiscoveredModels]);

  const refreshModels = () => {
    if (templateId) {
      clearCachedModels(templateId);
    }
    setDiscoveryError(null);
    discoverModels();
  };

  const isDiscoverDisabled =
    (requiresApiKey && !apiKey?.trim()) ||
    (templateId === "ollama" && !baseUrl?.trim()) ||
    (templateId === "azure" && (!endpoint?.trim() || !apiKey?.trim())) ||
    (templateId === "bedrock" && (!accessKey?.trim() || !secretKey?.trim())) ||
    isDiscovering;

  if (!supportsDiscovery) return null;

  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2 flex-wrap">
        <Label className="mb-0">{isEditMode ? "Model" : "Models"}</Label>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={discoverModels}
          disabled={isDiscoverDisabled}
        >
          {isDiscovering ? <Loader2 className="mr-1 h-3 w-3 animate-spin" /> : null}
          Fetch Models
        </Button>
        {discoveredModels.length > 0 && (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={refreshModels}
            disabled={isDiscovering}
          >
            <RefreshCw className={`mr-1 h-3 w-3 ${isDiscovering ? "animate-spin" : ""}`} />
            Refresh
          </Button>
        )}
      </div>
      {discoveryError && (
        <p className="text-sm text-destructive">{discoveryError}</p>
      )}
      {discoveredModels.length > 0 ? (
        <Select value={model} onValueChange={setModel}>
          <SelectTrigger>
            <SelectValue placeholder="Select a model" />
          </SelectTrigger>
          <SelectContent>
            {isEditMode && model && !discoveredModels.some((m) => m.id === model) && (
              <SelectItem key={model} value={model}>
                {model} (current)
              </SelectItem>
            )}
            {discoveredModels.map((m) => (
              <SelectItem key={m.id} value={m.id}>
                {m.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      ) : isEditMode ? (
        <div className="space-y-1">
          <Input
            value={model}
            onChange={(e) => setModel(e.target.value)}
            placeholder="Enter model name"
          />
          <p className="text-sm text-muted-foreground">
            Enter your API key above and click Fetch Models to browse, or type a model name directly.
          </p>
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">
          Enter your API key (or host for Ollama) and click Fetch Models.
        </p>
      )}
    </div>
  );
}
