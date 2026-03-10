"use client";

import type { UseFormRegister, UseFormWatch, UseFormSetValue, FieldErrors } from "react-hook-form";
import { Copy, Loader2 } from "lucide-react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { SettingsSwitchRow } from "@/components/ui/settings-switch-row";
import { CardFooter } from "@/components/ui/card";
import { CollapsibleCard } from "@/components/ui/collapsible-card";
import { ProviderIcon } from "@/components/provider-icons";
import { FormField } from "@/components/ui/form-field";
import { SaveButton } from "@/components/ui/save-button";
import type { SSOSetupProviderId } from "@/components/admin/sso-setup-modal";
import { toast } from "sonner";
import type { SSOForm } from "./types";
import { getRedirectUri } from "./types";

interface SSOProviderCardProps {
  id: SSOSetupProviderId;
  label: string;
  clientIdKey: keyof SSOForm;
  clientSecretKey: keyof SSOForm;
  enabledKey: keyof SSOForm;
  testPassedKey: keyof SSOForm;
  appUrl: string;
  register: UseFormRegister<SSOForm>;
  watch: UseFormWatch<SSOForm>;
  setValue: UseFormSetValue<SSOForm>;
  errors: FieldErrors<SSOForm>;
  isDirty: boolean;
  isSaving: boolean;
  testingProvider: SSOSetupProviderId | null;
  onSave: () => void;
  onTest: () => void;
  onSetupInstructions: () => void;
}

export function SSOProviderCard({
  id,
  label,
  clientIdKey,
  clientSecretKey,
  enabledKey,
  testPassedKey,
  appUrl,
  register: reg,
  watch,
  setValue,
  errors,
  isDirty,
  isSaving,
  testingProvider,
  onSave,
  onTest,
  onSetupInstructions,
}: SSOProviderCardProps) {
  const clientIdValue = watch(clientIdKey);
  const clientSecretValue = watch(clientSecretKey);
  const configured = !!(typeof clientIdValue === "string" && clientIdValue.trim());
  const hasSecret = !!(typeof clientSecretValue === "string" && clientSecretValue.trim());
  const canTest = configured && hasSecret;
  const testPassed = !!watch(testPassedKey);
  const enabled = !!watch(enabledKey);
  const statusLabel = !configured
    ? "Not configured"
    : !testPassed
      ? "Test required"
      : enabled
        ? "Enabled"
        : "Test passed";
  const statusVariant = !configured ? "default" : !testPassed ? "warning" : enabled ? "success" : "default";
  const redirectUri = appUrl ? getRedirectUri(appUrl, id) : "";
  const canEnable = configured && testPassed;

  return (
    <CollapsibleCard
      title={label}
      description={`OAuth client ID and secret from your ${label} developer console.`}
      icon={<ProviderIcon provider={id} size="sm" style="mono" />}
      status={{ label: statusLabel, variant: statusVariant }}
      defaultOpen={false}
      headerActions={
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="text-muted-foreground hover:text-foreground"
          onClick={(e) => {
            e.preventDefault();
            e.stopPropagation();
            onSetupInstructions();
          }}
        >
          Setup instructions
        </Button>
      }
    >
      <div className="space-y-4">
        {!testPassed && configured && (
          <p className="text-muted-foreground text-sm">
            Run &quot;Test connection&quot; successfully to enable this provider on the login page.
          </p>
        )}
        {canEnable && (
          <SettingsSwitchRow
            label={`Enable ${label} on login page`}
            description="Show this provider on the sign-in page. Turn off to hide without removing credentials."
            checked={enabled}
            onCheckedChange={(checked) => setValue(enabledKey, checked, { shouldDirty: true })}
          />
        )}
        {redirectUri && (
          <div>
            <p className="mb-1 text-sm font-medium">Redirect URI (callback URL)</p>
            <p className="text-muted-foreground mb-2 text-xs">
              Add this URL in your {label} application settings.
            </p>
            <div className="flex items-center gap-2 rounded-md border bg-muted/50 px-3 py-2 font-mono text-xs break-all">
              <span className="flex-1">{redirectUri}</span>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-8 w-8 shrink-0"
                onClick={() => void navigator.clipboard.writeText(redirectUri).then(() => toast.success("Redirect URI copied to clipboard"))}
                aria-label="Copy redirect URI"
              >
                <Copy className="h-4 w-4" aria-hidden />
              </Button>
            </div>
          </div>
        )}
        {configured && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={!!testingProvider || !canTest}
            onClick={onTest}
            title={!hasSecret ? "Enter client secret to test credentials" : undefined}
          >
            {testingProvider === id ? (
              <>
                <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden />
                Testing…
              </>
            ) : (
              "Test connection"
            )}
          </Button>
        )}
        <div className="grid gap-4 md:grid-cols-2">
          <FormField
            id={clientIdKey}
            label="Client ID"
            description="OAuth 2.0 client identifier from your provider's developer console."
            error={errors[clientIdKey]?.message}
          >
            <Input
              id={clientIdKey}
              type="text"
              placeholder={id === "google" ? "1234567890-abc.apps.googleusercontent.com" : "Optional"}
              {...reg(clientIdKey, {
                onBlur: (e) => {
                  if (!(e.target.value?.trim())) setValue(enabledKey, false, { shouldDirty: true });
                },
              })}
              className="min-h-[44px]"
            />
          </FormField>
          <FormField
            id={clientSecretKey}
            label="Client secret"
            description="OAuth 2.0 client secret. Keep this confidential."
            error={errors[clientSecretKey]?.message}
          >
            <Input
              id={clientSecretKey}
              type="password"
              placeholder="Optional"
              {...reg(clientSecretKey)}
              className="min-h-[44px]"
            />
          </FormField>
        </div>
        <CardFooter className="flex justify-end">
          <SaveButton
            type="button"
            isDirty={isDirty}
            isSaving={isSaving}
            onClick={onSave}
          />
        </CardFooter>
      </div>
    </CollapsibleCard>
  );
}
