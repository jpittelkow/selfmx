"use client";

import { useState, useEffect, useCallback } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { getErrorMessage } from "@/lib/utils";
import type { AIProvider, DiscoveredModel } from "./ai-types";
import { providerTemplates } from "./ai-types";
import { ProviderCredentialFields } from "./provider-credential-fields";
import { ProviderModelSelection, getCachedModels } from "./provider-model-selection";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Loader2 } from "lucide-react";

interface ProviderDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** When set, the dialog operates in edit mode */
  editingProvider: AIProvider | null;
  onProviderAdded: (provider: AIProvider) => void;
  onProviderUpdated: (provider: AIProvider) => void;
}

export function ProviderDialog({
  open,
  onOpenChange,
  editingProvider,
  onProviderAdded,
  onProviderUpdated,
}: ProviderDialogProps) {
  const isEditMode = !!editingProvider;

  // Form fields
  const [selectedTemplate, setSelectedTemplate] = useState("");
  const [model, setModel] = useState("");
  const [apiKey, setApiKey] = useState("");
  const [baseUrl, setBaseUrl] = useState("");
  const [endpoint, setEndpoint] = useState("");
  const [region, setRegion] = useState("us-east-1");
  const [accessKey, setAccessKey] = useState("");
  const [secretKey, setSecretKey] = useState("");

  // API key validation
  const [isTestingKey, setIsTestingKey] = useState(false);
  const [keyValid, setKeyValid] = useState<boolean | null>(null);
  const [keyError, setKeyError] = useState<string | null>(null);

  // Model discovery
  const [discoveredModels, setDiscoveredModels] = useState<DiscoveredModel[]>([]);

  const [isSaving, setIsSaving] = useState(false);

  const templateId = isEditMode ? editingProvider.provider : selectedTemplate;
  const templateData = providerTemplates.find((t) => t.id === templateId);

  const resetFields = useCallback(() => {
    setSelectedTemplate("");
    setModel("");
    setApiKey("");
    setBaseUrl("");
    setEndpoint("");
    setRegion("us-east-1");
    setAccessKey("");
    setSecretKey("");
    setKeyValid(null);
    setKeyError(null);
    setDiscoveredModels([]);
  }, []);

  // Populate fields when opening in edit mode, reset when closing
  useEffect(() => {
    if (open && editingProvider) {
      setSelectedTemplate(editingProvider.provider);
      setModel(editingProvider.model);
      setApiKey("");
      setBaseUrl(editingProvider.base_url || "");
      setEndpoint(editingProvider.endpoint || "");
      setRegion(editingProvider.region || "us-east-1");
      setAccessKey("");
      setSecretKey("");
      setKeyValid(null);
      setKeyError(null);
      const cached = getCachedModels(editingProvider.provider);
      setDiscoveredModels(cached ?? []);
    }
    if (!open) {
      resetFields();
    }
  }, [open, editingProvider, resetFields]);

  const handleOpenChange = (newOpen: boolean) => {
    onOpenChange(newOpen);
  };

  const handleCredentialChange = () => {
    setKeyValid(null);
    setKeyError(null);
    if (!isEditMode) {
      setDiscoveredModels([]);
    }
  };

  const testApiKey = async () => {
    if (templateId === "ollama") {
      if (!baseUrl?.trim()) {
        setKeyError("Enter Ollama host (e.g. http://localhost:11434)");
        return;
      }
    } else if (templateId === "azure") {
      if (!endpoint?.trim()) {
        setKeyError("Enter Azure OpenAI endpoint (e.g. https://your-resource.openai.azure.com)");
        return;
      }
      if (!apiKey?.trim()) {
        setKeyError("Enter your API key");
        return;
      }
    } else if (templateId === "bedrock") {
      if (!accessKey?.trim() || !secretKey?.trim()) {
        setKeyError("Enter AWS access key and secret key");
        return;
      }
    } else if (!apiKey?.trim()) {
      setKeyError("Enter your API key");
      return;
    }
    setIsTestingKey(true);
    setKeyError(null);
    setKeyValid(null);
    try {
      const response = await api.post("/llm-settings/test-key", {
        provider: templateId,
        api_key: apiKey || undefined,
        host: templateId === "ollama" ? (baseUrl || "http://localhost:11434") : undefined,
        endpoint: templateId === "azure" ? endpoint || undefined : undefined,
        region: templateId === "bedrock" ? region || undefined : undefined,
        access_key: templateId === "bedrock" ? accessKey || undefined : undefined,
        secret_key: templateId === "bedrock" ? secretKey || undefined : undefined,
      });
      setKeyValid(response.data.valid);
      if (!response.data.valid && response.data.error) {
        setKeyError(response.data.error);
      }
    } catch (err: unknown) {
      const data = err && typeof err === "object" && "response" in err
        ? (err as { response?: { data?: { error?: string; message?: string } } }).response?.data
        : null;
      setKeyValid(false);
      setKeyError(data?.error ?? data?.message ?? "Failed to validate API key");
    } finally {
      setIsTestingKey(false);
    }
  };

  const handleAdd = async () => {
    if (!selectedTemplate || !model) {
      toast.error("Please select a provider and model");
      return;
    }
    if (templateData?.requires_api_key && !apiKey && selectedTemplate !== "bedrock") {
      toast.error("API key is required for this provider");
      return;
    }
    if (selectedTemplate === "azure" && !endpoint?.trim()) {
      toast.error("Azure OpenAI endpoint is required");
      return;
    }
    if (selectedTemplate === "bedrock" && (!accessKey?.trim() || !secretKey?.trim())) {
      toast.error("AWS access key and secret key are required for Bedrock");
      return;
    }

    setIsSaving(true);
    try {
      const response = await api.post("/llm/providers", {
        provider: selectedTemplate,
        model,
        api_key: apiKey || undefined,
        base_url: selectedTemplate === "ollama" ? (baseUrl || undefined) : undefined,
        endpoint: selectedTemplate === "azure" ? endpoint || undefined : undefined,
        region: selectedTemplate === "bedrock" ? region || undefined : undefined,
        access_key: selectedTemplate === "bedrock" ? accessKey || undefined : undefined,
        secret_key: selectedTemplate === "bedrock" ? secretKey || undefined : undefined,
      });
      onProviderAdded(response.data.provider);
      handleOpenChange(false);
      toast.success("Provider added successfully");
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to add provider"));
    } finally {
      setIsSaving(false);
    }
  };

  const handleEdit = async () => {
    if (!editingProvider || !model) {
      toast.error("Please select a model");
      return;
    }
    if (editingProvider.provider === "azure" && !endpoint?.trim()) {
      toast.error("Azure OpenAI endpoint is required");
      return;
    }

    setIsSaving(true);
    try {
      const payload: Record<string, unknown> = {};
      if (model !== editingProvider.model) {
        payload.model = model;
      }
      if (apiKey) {
        payload.api_key = apiKey;
      }
      if (editingProvider.provider === "ollama") {
        const newUrl = baseUrl || "";
        const oldUrl = editingProvider.base_url || "";
        if (newUrl !== oldUrl) payload.base_url = newUrl || null;
      }
      if (editingProvider.provider === "azure") {
        const newEp = endpoint || "";
        const oldEp = editingProvider.endpoint || "";
        if (newEp !== oldEp) payload.endpoint = newEp || null;
      }
      if (editingProvider.provider === "bedrock") {
        const newRegion = region || "";
        const oldRegion = editingProvider.region || "";
        if (newRegion !== oldRegion) payload.region = newRegion || null;
        if (accessKey) payload.access_key = accessKey;
        if (secretKey) payload.secret_key = secretKey;
      }

      const response = await api.put(`/llm/providers/${editingProvider.id}`, payload);
      onProviderUpdated(response.data.provider);
      handleOpenChange(false);
      toast.success("Provider updated successfully");
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to update provider"));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{isEditMode ? "Edit AI Provider" : "Add AI Provider"}</DialogTitle>
          <DialogDescription>
            {isEditMode
              ? `Update settings for ${templateData?.name ?? editingProvider?.provider}.`
              : "Configure a new AI provider for your application."}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-4">
          {/* Provider selector (add mode) or read-only (edit mode) */}
          <div className="space-y-2">
            <Label>Provider</Label>
            {isEditMode ? (
              <Input
                value={templateData?.name ?? editingProvider?.provider ?? ""}
                disabled
                className="bg-muted"
              />
            ) : (
              <Select
                value={selectedTemplate}
                onValueChange={(v) => {
                  setSelectedTemplate(v);
                  setModel("");
                  setEndpoint("");
                  setRegion("us-east-1");
                  setAccessKey("");
                  setSecretKey("");
                  setKeyValid(null);
                  setKeyError(null);
                  const cached = getCachedModels(v);
                  setDiscoveredModels(cached ?? []);
                }}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select a provider" />
                </SelectTrigger>
                <SelectContent>
                  {providerTemplates.map((template) => (
                    <SelectItem key={template.id} value={template.id}>
                      {template.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </div>

          {templateData && (
            <>
              <ProviderCredentialFields
                templateId={templateId}
                isEditMode={isEditMode}
                editingProvider={editingProvider}
                apiKey={apiKey}
                setApiKey={setApiKey}
                baseUrl={baseUrl}
                setBaseUrl={setBaseUrl}
                endpoint={endpoint}
                setEndpoint={setEndpoint}
                region={region}
                setRegion={setRegion}
                accessKey={accessKey}
                setAccessKey={setAccessKey}
                secretKey={secretKey}
                setSecretKey={setSecretKey}
                keyValid={keyValid}
                keyError={keyError}
                isTestingKey={isTestingKey}
                requiresApiKey={templateData.requires_api_key}
                onTest={testApiKey}
                onCredentialChange={handleCredentialChange}
              />

              <ProviderModelSelection
                templateId={templateId}
                isEditMode={isEditMode}
                model={model}
                setModel={setModel}
                apiKey={apiKey}
                baseUrl={baseUrl}
                endpoint={endpoint}
                region={region}
                accessKey={accessKey}
                secretKey={secretKey}
                supportsDiscovery={templateData.supports_discovery}
                requiresApiKey={templateData.requires_api_key}
                discoveredModels={discoveredModels}
                setDiscoveredModels={setDiscoveredModels}
              />

              {/* Capability badges (add mode only) */}
              {!isEditMode && (
                <div className="flex gap-2">
                  {templateData.supports_vision && (
                    <Badge variant="secondary">Vision Support</Badge>
                  )}
                  {!templateData.requires_api_key && (
                    <Badge variant="outline">No API Key Required</Badge>
                  )}
                </div>
              )}
            </>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => handleOpenChange(false)}>
            Cancel
          </Button>
          <Button onClick={isEditMode ? handleEdit : handleAdd} disabled={isSaving}>
            {isSaving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {isEditMode ? "Save Changes" : "Add Provider"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
