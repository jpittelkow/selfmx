"use client";

import { useState, useEffect, useCallback } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { errorLogger } from "@/lib/error-logger";
import { getErrorMessage } from "@/lib/utils";
import { useAuth, isAdminUser } from "@/lib/auth";
import { HelpLink } from "@/components/help/help-link";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { AISettingsForm } from "@/components/ai/ai-settings-form";
import { OrchestrationModeCard } from "@/components/ai/orchestration-mode-card";
import { ProviderListCard } from "@/components/ai/provider-list-card";
import { ProviderDialog } from "@/components/ai/provider-dialog";
import type { AIProvider, LLMMode } from "@/components/ai/ai-types";

export default function AISettingsPage() {
  const { user } = useAuth();
  const isAdmin = isAdminUser(user);

  const [providers, setProviders] = useState<AIProvider[]>([]);
  const [mode, setMode] = useState<LLMMode>("single");
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [testingProviders, setTestingProviders] = useState<Set<number>>(new Set());

  // Dialog state
  const [showDialog, setShowDialog] = useState(false);
  const [editingProvider, setEditingProvider] = useState<AIProvider | null>(null);

  const fetchAIConfig = useCallback(async () => {
    try {
      const response = await api.get("/llm/config");
      setProviders(response.data.providers || []);
      setMode(response.data.mode || "single");
    } catch (error) {
      errorLogger.report(
        error instanceof Error ? error : new Error("Failed to fetch AI config"),
        { source: "ai-page" }
      );
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchAIConfig();
  }, [fetchAIConfig]);

  const handleModeChange = async (newMode: LLMMode) => {
    setMode(newMode);
    setIsSaving(true);

    try {
      await api.put("/llm/config", { mode: newMode });
      toast.success(`LLM mode set to ${newMode}`);
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to update mode"));
      fetchAIConfig();
    } finally {
      setIsSaving(false);
    }
  };

  const handleToggleProvider = async (providerId: number, enabled: boolean) => {
    setProviders((prev) =>
      prev.map((p) => (p.id === providerId ? { ...p, is_enabled: enabled } : p))
    );

    try {
      await api.put(`/llm/providers/${providerId}`, { is_enabled: enabled });
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to update provider"));
      fetchAIConfig();
    }
  };

  const handleSetPrimary = async (providerId: number) => {
    setProviders((prev) =>
      prev.map((p) => ({
        ...p,
        is_primary: p.id === providerId,
      }))
    );

    try {
      await api.put(`/llm/providers/${providerId}`, { is_primary: true });
      toast.success("Primary provider updated");
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to update provider"));
      fetchAIConfig();
    }
  };

  const handleDeleteProvider = async (providerId: number) => {
    try {
      await api.delete(`/llm/providers/${providerId}`);
      setProviders((prev) => prev.filter((p) => p.id !== providerId));
      toast.success("Provider removed");
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to remove provider"));
    }
  };

  const handleTestProvider = async (providerId: number, providerName: string) => {
    setTestingProviders((prev) => new Set(prev).add(providerId));

    try {
      const response = await api.post(`/llm/test/${providerName}`);
      if (response.data.success) {
        toast.success("Provider connection successful!");
      } else {
        toast.error(response.data.error || "Connection test failed");
      }
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Connection test failed"));
    } finally {
      setTestingProviders((prev) => {
        const newSet = new Set(prev);
        newSet.delete(providerId);
        return newSet;
      });
    }
  };

  const openEditDialog = (provider: AIProvider) => {
    setEditingProvider(provider);
    setShowDialog(true);
  };

  const handleDialogOpenChange = (open: boolean) => {
    setShowDialog(open);
    if (!open) setEditingProvider(null);
  };

  if (isLoading) {
    return <SettingsPageSkeleton />;
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">AI / LLM Settings</h1>
        <p className="text-muted-foreground">
          Configure AI providers and orchestration modes.{" "}
          <HelpLink articleId="ai-llm-settings" />
        </p>
      </div>

      <OrchestrationModeCard mode={mode} onModeChange={handleModeChange} />

      <ProviderListCard
        providers={providers}
        testingProviders={testingProviders}
        onAddClick={() => setShowDialog(true)}
        onEdit={openEditDialog}
        onTest={handleTestProvider}
        onSetPrimary={handleSetPrimary}
        onToggle={handleToggleProvider}
        onDelete={handleDeleteProvider}
      />

      <ProviderDialog
        open={showDialog}
        onOpenChange={handleDialogOpenChange}
        editingProvider={editingProvider}
        onProviderAdded={(provider) => setProviders((prev) => [...prev, provider])}
        onProviderUpdated={(provider) =>
          setProviders((prev) => prev.map((p) => (p.id === provider.id ? provider : p)))
        }
      />

      {/* Mode Requirements Alert */}
      {mode === "council" && providers.filter((p) => p.is_enabled).length < 2 && (
        <Alert variant="warning">
          <AlertTitle>Council Mode Requirement</AlertTitle>
          <AlertDescription>
            Council mode requires at least 2 enabled providers to reach consensus.
            Please enable more providers or switch to a different mode.
          </AlertDescription>
        </Alert>
      )}

      {/* System Defaults (admin-only) */}
      {isAdmin && <AISettingsForm mode={mode} />}
    </div>
  );
}
