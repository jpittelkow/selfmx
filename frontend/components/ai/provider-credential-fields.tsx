"use client";

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
import { Loader2, CheckCircle, XCircle } from "lucide-react";

interface ProviderCredentialFieldsProps {
  templateId: string;
  isEditMode: boolean;
  editingProvider: { api_key_set?: boolean; access_key_set?: boolean; secret_key_set?: boolean } | null;
  apiKey: string;
  setApiKey: (v: string) => void;
  baseUrl: string;
  setBaseUrl: (v: string) => void;
  endpoint: string;
  setEndpoint: (v: string) => void;
  region: string;
  setRegion: (v: string) => void;
  accessKey: string;
  setAccessKey: (v: string) => void;
  secretKey: string;
  setSecretKey: (v: string) => void;
  keyValid: boolean | null;
  keyError: string | null;
  isTestingKey: boolean;
  requiresApiKey: boolean;
  onTest: () => void;
  onCredentialChange: () => void;
}

export function ProviderCredentialFields({
  templateId,
  isEditMode,
  editingProvider,
  apiKey,
  setApiKey,
  baseUrl,
  setBaseUrl,
  endpoint,
  setEndpoint,
  region,
  setRegion,
  accessKey,
  setAccessKey,
  secretKey,
  setSecretKey,
  keyValid,
  keyError,
  isTestingKey,
  requiresApiKey,
  onTest,
  onCredentialChange,
}: ProviderCredentialFieldsProps) {
  const handleChange = (setter: (v: string) => void) => (e: React.ChangeEvent<HTMLInputElement>) => {
    setter(e.target.value);
    onCredentialChange();
  };

  const getTestDisabled = () => {
    if (templateId === "ollama") return !baseUrl?.trim() || isTestingKey;
    if (templateId === "azure") return !endpoint?.trim() || !apiKey?.trim() || isTestingKey;
    if (templateId === "bedrock") return !accessKey?.trim() || !secretKey?.trim() || isTestingKey;
    return !apiKey?.trim() || isTestingKey;
  };

  return (
    <>
      {/* API Key */}
      {requiresApiKey && (
        <div className="space-y-2">
          <Label>API Key</Label>
          <div className="flex gap-2">
            <div className="relative flex-1">
              <Input
                type="password"
                value={apiKey}
                onChange={handleChange(setApiKey)}
                placeholder={
                  isEditMode && editingProvider?.api_key_set
                    ? "Leave blank to keep current"
                    : "Enter your API key"
                }
              />
              {keyValid === true && (
                <CheckCircle className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-green-600 dark:text-green-400" />
              )}
              {keyValid === false && (
                <XCircle className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-destructive" />
              )}
            </div>
            <Button
              type="button"
              variant="outline"
              onClick={onTest}
              disabled={getTestDisabled()}
            >
              {isTestingKey ? <Loader2 className="h-4 w-4 animate-spin" /> : "Test"}
            </Button>
          </div>
          {keyError && <p className="text-sm text-destructive">{keyError}</p>}
        </div>
      )}

      {/* Azure endpoint */}
      {templateId === "azure" && (
        <div className="space-y-2">
          <Label>Azure OpenAI endpoint</Label>
          <Input
            type="url"
            value={endpoint}
            onChange={handleChange(setEndpoint)}
            placeholder="https://your-resource.openai.azure.com"
          />
        </div>
      )}

      {/* Bedrock credentials */}
      {templateId === "bedrock" && (
        <>
          <div className="space-y-2">
            <Label>AWS Region</Label>
            <Select
              value={region}
              onValueChange={(v) => {
                setRegion(v);
                onCredentialChange();
              }}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="us-east-1">us-east-1</SelectItem>
                <SelectItem value="us-west-2">us-west-2</SelectItem>
                <SelectItem value="eu-west-1">eu-west-1</SelectItem>
                <SelectItem value="eu-central-1">eu-central-1</SelectItem>
                <SelectItem value="ap-northeast-1">ap-northeast-1</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label>Access Key ID</Label>
            <div className="flex gap-2">
              <div className="relative flex-1">
                <Input
                  type="password"
                  value={accessKey}
                  onChange={handleChange(setAccessKey)}
                  placeholder={
                    isEditMode && editingProvider?.access_key_set
                      ? "Leave blank to keep current"
                      : "AKIA..."
                  }
                />
                {keyValid === true && (
                  <CheckCircle className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-green-600 dark:text-green-400" />
                )}
                {keyValid === false && (
                  <XCircle className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-destructive" />
                )}
              </div>
              <Button
                type="button"
                variant="outline"
                onClick={onTest}
                disabled={!accessKey?.trim() || !secretKey?.trim() || isTestingKey}
              >
                {isTestingKey ? <Loader2 className="h-4 w-4 animate-spin" /> : "Test"}
              </Button>
            </div>
          </div>
          <div className="space-y-2">
            <Label>Secret Access Key</Label>
            <Input
              type="password"
              value={secretKey}
              onChange={handleChange(setSecretKey)}
              placeholder={
                isEditMode && editingProvider?.secret_key_set
                  ? "Leave blank to keep current"
                  : "Enter secret key"
              }
            />
          </div>
          {keyError && <p className="text-sm text-destructive">{keyError}</p>}
        </>
      )}

      {/* Ollama host */}
      {templateId === "ollama" && (
        <div className="space-y-2">
          <Label>Ollama host</Label>
          <div className="flex gap-2">
            <div className="relative flex-1">
              <Input
                type="text"
                value={baseUrl}
                onChange={handleChange(setBaseUrl)}
                placeholder="http://localhost:11434"
              />
              {keyValid === true && (
                <CheckCircle className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-green-600 dark:text-green-400" />
              )}
              {keyValid === false && (
                <XCircle className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-destructive" />
              )}
            </div>
            <Button
              type="button"
              variant="outline"
              onClick={onTest}
              disabled={!baseUrl?.trim() || isTestingKey}
            >
              {isTestingKey ? <Loader2 className="h-4 w-4 animate-spin" /> : "Test"}
            </Button>
          </div>
          {keyError && <p className="text-sm text-destructive">{keyError}</p>}
        </div>
      )}
    </>
  );
}
