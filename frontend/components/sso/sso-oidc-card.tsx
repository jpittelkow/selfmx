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

interface SSOOidcCardProps {
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

export function SSOOidcCard({
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
}: SSOOidcCardProps) {
  const configured = !!(watch("oidc_client_id")?.trim() && watch("oidc_issuer_url")?.trim());
  const testPassed = !!watch("oidc_test_passed");
  const enabled = watch("oidc_enabled");
  const statusLabel = !configured ? "Not configured" : !testPassed ? "Test required" : enabled ? "Enabled" : "Test passed";
  const statusVariant = !configured ? "default" : !testPassed ? "warning" : enabled ? "success" : "default";

  return (
    <CollapsibleCard
      title="Enterprise SSO (OIDC)"
      description="Generic OIDC provider for Okta, Auth0, Keycloak, or other IdPs."
      icon={<ProviderIcon provider="key" size="sm" style="mono" />}
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
        {configured && !testPassed && (
          <p className="text-muted-foreground text-sm">
            Run &quot;Test connection&quot; successfully to enable this provider on the login page.
          </p>
        )}
        {configured && testPassed && (
          <SettingsSwitchRow
            label="Enable Enterprise SSO on login page"
            description="Show this provider on the sign-in page. Turn off to hide without removing credentials."
            checked={enabled}
            onCheckedChange={(checked) => setValue("oidc_enabled", checked, { shouldDirty: true })}
          />
        )}
        {appUrl && (
          <div>
            <p className="mb-1 text-sm font-medium">Redirect URI (callback URL)</p>
            <p className="text-muted-foreground mb-2 text-xs">
              Add this URL in your IdP application settings.
            </p>
            <div className="flex items-center gap-2 rounded-md border bg-muted/50 px-3 py-2 font-mono text-xs break-all">
              <span className="flex-1">{getRedirectUri(appUrl, "oidc")}</span>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-8 w-8 shrink-0"
                onClick={() => void navigator.clipboard.writeText(getRedirectUri(appUrl, "oidc")).then(() => toast.success("Redirect URI copied to clipboard"))}
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
            disabled={!!testingProvider || !watch("oidc_client_secret")?.trim()}
            onClick={onTest}
            title={!watch("oidc_client_secret")?.trim() ? "Enter client secret to test credentials" : undefined}
          >
            {testingProvider === "oidc" ? (
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
            id="oidc_client_id"
            label="Client ID"
            description="OIDC client identifier from your IdP."
            error={errors.oidc_client_id?.message}
          >
            <Input
              id="oidc_client_id"
              type="text"
              placeholder="Optional"
              {...reg("oidc_client_id", {
                onBlur: (e) => {
                  const issuer = watch("oidc_issuer_url")?.trim();
                  if (!(e.target.value?.trim()) || !issuer) setValue("oidc_enabled", false, { shouldDirty: true });
                },
              })}
              className="min-h-[44px]"
            />
          </FormField>
          <FormField
            id="oidc_client_secret"
            label="Client secret"
            description="OIDC client secret. Keep this confidential."
            error={errors.oidc_client_secret?.message}
          >
            <Input
              id="oidc_client_secret"
              type="password"
              placeholder="Optional"
              {...reg("oidc_client_secret")}
              className="min-h-[44px]"
            />
          </FormField>
          <FormField
            id="oidc_issuer_url"
            label="Issuer URL"
            description="OIDC issuer URL (discovery endpoint base, e.g. https://your-tenant.auth0.com/)."
            error={errors.oidc_issuer_url?.message}
          >
            <Input
              id="oidc_issuer_url"
              type="url"
              placeholder="https://..."
              {...reg("oidc_issuer_url", {
                onBlur: (e) => {
                  const clientId = watch("oidc_client_id")?.trim();
                  if (!(e.target.value?.trim()) || !clientId) setValue("oidc_enabled", false, { shouldDirty: true });
                },
              })}
              className="min-h-[44px]"
            />
          </FormField>
          <FormField
            id="oidc_provider_name"
            label="Provider name"
            description="Display name shown on the login page (e.g. Enterprise SSO)."
            error={errors.oidc_provider_name?.message}
          >
            <Input
              id="oidc_provider_name"
              type="text"
              placeholder="Enterprise SSO"
              {...reg("oidc_provider_name")}
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
