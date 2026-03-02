"use client";

import { useState, useEffect, useCallback } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { Copy, Loader2 } from "lucide-react";
import { api } from "@/lib/api";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { SettingsSwitchRow } from "@/components/ui/settings-switch-row";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { CollapsibleCard } from "@/components/ui/collapsible-card";
import { ProviderIcon } from "@/components/provider-icons";
import { FormField } from "@/components/ui/form-field";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { SaveButton } from "@/components/ui/save-button";
import {
  SSOSetupModal,
  type SSOSetupProviderId,
} from "@/components/admin/sso-setup-modal";
import { HelpLink } from "@/components/help/help-link";
import { TOOLTIP_CONTENT } from "@/lib/tooltip-content";

const ssoSchema = z.object({
  enabled: z.boolean(),
  allow_linking: z.boolean(),
  auto_register: z.boolean(),
  trust_provider_email: z.boolean(),
  google_enabled: z.boolean(),
  github_enabled: z.boolean(),
  microsoft_enabled: z.boolean(),
  apple_enabled: z.boolean(),
  discord_enabled: z.boolean(),
  gitlab_enabled: z.boolean(),
  oidc_enabled: z.boolean(),
  google_test_passed: z.boolean().optional(),
  github_test_passed: z.boolean().optional(),
  microsoft_test_passed: z.boolean().optional(),
  apple_test_passed: z.boolean().optional(),
  discord_test_passed: z.boolean().optional(),
  gitlab_test_passed: z.boolean().optional(),
  oidc_test_passed: z.boolean().optional(),
  google_client_id: z
    .string()
    .optional()
    .refine((val) => !val || val.endsWith(".apps.googleusercontent.com"), {
      message: "Google Client ID should end with .apps.googleusercontent.com",
    }),
  google_client_secret: z.string().optional(),
  github_client_id: z.string().optional(),
  github_client_secret: z.string().optional(),
  microsoft_client_id: z
    .string()
    .optional()
    .refine(
      (val) =>
        !val ||
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(val),
      { message: "Microsoft Client ID should be a UUID (e.g., 12345678-1234-1234-1234-123456789abc)" }
    ),
  microsoft_client_secret: z.string().optional(),
  apple_client_id: z.string().optional(),
  apple_client_secret: z.string().optional(),
  discord_client_id: z.string().optional(),
  discord_client_secret: z.string().optional(),
  gitlab_client_id: z.string().optional(),
  gitlab_client_secret: z.string().optional(),
  oidc_client_id: z.string().optional(),
  oidc_client_secret: z.string().optional(),
  oidc_issuer_url: z
    .union([z.literal(""), z.string().url("Invalid URL")])
    .optional()
    .refine((val) => !val || val.trim() === "" || val.startsWith("https://"), {
      message: "Issuer URL must use HTTPS",
    }),
  oidc_provider_name: z.string().optional(),
});
type SSOForm = z.infer<typeof ssoSchema>;

const defaultValues: SSOForm = {
  enabled: true,
  allow_linking: true,
  auto_register: true,
  trust_provider_email: true,
  google_enabled: true,
  github_enabled: true,
  microsoft_enabled: true,
  apple_enabled: true,
  discord_enabled: true,
  gitlab_enabled: true,
  oidc_enabled: true,
  google_test_passed: false,
  github_test_passed: false,
  microsoft_test_passed: false,
  apple_test_passed: false,
  discord_test_passed: false,
  gitlab_test_passed: false,
  oidc_test_passed: false,
  google_client_id: "",
  google_client_secret: "",
  github_client_id: "",
  github_client_secret: "",
  microsoft_client_id: "",
  microsoft_client_secret: "",
  apple_client_id: "",
  apple_client_secret: "",
  discord_client_id: "",
  discord_client_secret: "",
  gitlab_client_id: "",
  gitlab_client_secret: "",
  oidc_client_id: "",
  oidc_client_secret: "",
  oidc_issuer_url: "",
  oidc_provider_name: "Enterprise SSO",
};

const providers: Array<{
  id: SSOSetupProviderId;
  label: string;
  clientIdKey: keyof SSOForm;
  clientSecretKey: keyof SSOForm;
  enabledKey: keyof SSOForm;
  testPassedKey: keyof SSOForm;
}> = [
  { id: "google", label: "Google", clientIdKey: "google_client_id", clientSecretKey: "google_client_secret", enabledKey: "google_enabled", testPassedKey: "google_test_passed" },
  { id: "github", label: "GitHub", clientIdKey: "github_client_id", clientSecretKey: "github_client_secret", enabledKey: "github_enabled", testPassedKey: "github_test_passed" },
  { id: "microsoft", label: "Microsoft", clientIdKey: "microsoft_client_id", clientSecretKey: "microsoft_client_secret", enabledKey: "microsoft_enabled", testPassedKey: "microsoft_test_passed" },
  { id: "apple", label: "Apple", clientIdKey: "apple_client_id", clientSecretKey: "apple_client_secret", enabledKey: "apple_enabled", testPassedKey: "apple_test_passed" },
  { id: "discord", label: "Discord", clientIdKey: "discord_client_id", clientSecretKey: "discord_client_secret", enabledKey: "discord_enabled", testPassedKey: "discord_test_passed" },
  { id: "gitlab", label: "GitLab", clientIdKey: "gitlab_client_id", clientSecretKey: "gitlab_client_secret", enabledKey: "gitlab_enabled", testPassedKey: "gitlab_test_passed" },
];

function toBool(v: unknown): boolean {
  if (typeof v === "boolean") return v;
  if (v === "true" || v === "1") return true;
  if (v === "false" || v === "0" || v === "" || v == null) return false;
  return Boolean(v);
}

function getRedirectUri(baseUrl: string, provider: string): string {
  const base = baseUrl.replace(/\/$/, "");
  return `${base}/api/auth/callback/${provider}`;
}

const GLOBAL_KEYS: (keyof SSOForm)[] = [
  "enabled",
  "allow_linking",
  "auto_register",
  "trust_provider_email",
];

function getProviderKeys(provider: SSOSetupProviderId): (keyof SSOForm)[] {
  if (provider === "oidc") {
    return ["oidc_enabled", "oidc_client_id", "oidc_client_secret", "oidc_issuer_url", "oidc_provider_name"];
  }
  return [
    `${provider}_enabled` as keyof SSOForm,
    `${provider}_client_id` as keyof SSOForm,
    `${provider}_client_secret` as keyof SSOForm,
  ];
}

export default function SSOSettingsPage() {
  const [isLoading, setIsLoading] = useState(true);
  const [savingProvider, setSavingProvider] = useState<SSOSetupProviderId | null>(null);
  const [isSavingGlobal, setIsSavingGlobal] = useState(false);
  const [originalValues, setOriginalValues] = useState<SSOForm>(defaultValues);
  const [appUrl, setAppUrl] = useState("");
  const [setupModalProvider, setSetupModalProvider] = useState<SSOSetupProviderId | null>(null);
  const [testingProvider, setTestingProvider] = useState<SSOSetupProviderId | null>(null);

  const {
    register,
    formState: { errors },
    setValue,
    watch,
    reset,
    getValues,
    trigger,
  } = useForm<SSOForm>({
    resolver: zodResolver(ssoSchema),
    mode: "onBlur",
    defaultValues,
  });

  const fetchAppUrl = useCallback(async () => {
    try {
      const response = await api.get("/system-settings");
      const data = response.data?.settings ?? {};
      const url = data.general?.app_url?.trim() || (typeof window !== "undefined" ? window.location.origin : "");
      setAppUrl(url);
    } catch {
      setAppUrl(typeof window !== "undefined" ? window.location.origin : "");
    }
  }, []);

  const fetchSettings = useCallback(async () => {
    setIsLoading(true);
    try {
      const response = await api.get("/sso-settings");
      const settings = response.data?.settings ?? {};
      const formValues: SSOForm = {
        enabled: toBool(settings.enabled ?? defaultValues.enabled),
        allow_linking: toBool(settings.allow_linking ?? defaultValues.allow_linking),
        auto_register: toBool(settings.auto_register ?? defaultValues.auto_register),
        trust_provider_email: toBool(settings.trust_provider_email ?? defaultValues.trust_provider_email),
        google_enabled: toBool(settings.google_enabled ?? true),
        github_enabled: toBool(settings.github_enabled ?? true),
        microsoft_enabled: toBool(settings.microsoft_enabled ?? true),
        apple_enabled: toBool(settings.apple_enabled ?? true),
        discord_enabled: toBool(settings.discord_enabled ?? true),
        gitlab_enabled: toBool(settings.gitlab_enabled ?? true),
        oidc_enabled: toBool(settings.oidc_enabled ?? true),
        google_test_passed: toBool(settings.google_test_passed ?? false),
        github_test_passed: toBool(settings.github_test_passed ?? false),
        microsoft_test_passed: toBool(settings.microsoft_test_passed ?? false),
        apple_test_passed: toBool(settings.apple_test_passed ?? false),
        discord_test_passed: toBool(settings.discord_test_passed ?? false),
        gitlab_test_passed: toBool(settings.gitlab_test_passed ?? false),
        oidc_test_passed: toBool(settings.oidc_test_passed ?? false),
        google_client_id: settings.google_client_id ?? "",
        google_client_secret: settings.google_client_secret ?? "",
        github_client_id: settings.github_client_id ?? "",
        github_client_secret: settings.github_client_secret ?? "",
        microsoft_client_id: settings.microsoft_client_id ?? "",
        microsoft_client_secret: settings.microsoft_client_secret ?? "",
        apple_client_id: settings.apple_client_id ?? "",
        apple_client_secret: settings.apple_client_secret ?? "",
        discord_client_id: settings.discord_client_id ?? "",
        discord_client_secret: settings.discord_client_secret ?? "",
        gitlab_client_id: settings.gitlab_client_id ?? "",
        gitlab_client_secret: settings.gitlab_client_secret ?? "",
        oidc_client_id: settings.oidc_client_id ?? "",
        oidc_client_secret: settings.oidc_client_secret ?? "",
        oidc_issuer_url: settings.oidc_issuer_url ?? "",
        oidc_provider_name: settings.oidc_provider_name ?? defaultValues.oidc_provider_name,
      };
      reset(formValues);
      setOriginalValues(formValues);
    } catch {
      toast.error("Failed to load SSO settings");
    } finally {
      setIsLoading(false);
    }
  }, [reset]);

  useEffect(() => {
    void fetchSettings();
    void fetchAppUrl();
  }, [fetchSettings, fetchAppUrl]);

  const isGlobalDirty = (): boolean => {
    const current = getValues();
    return GLOBAL_KEYS.some((k) => current[k] !== originalValues[k]);
  };

  const isProviderDirty = (provider: SSOSetupProviderId): boolean => {
    const current = getValues();
    const keys = getProviderKeys(provider);
    return keys.some((k) => current[k] !== originalValues[k]);
  };

  const getGlobalPayload = (): Partial<SSOForm> => {
    const current = getValues();
    return Object.fromEntries(GLOBAL_KEYS.map((k) => [k, current[k]])) as Partial<SSOForm>;
  };

  const getProviderPayload = (provider: SSOSetupProviderId): Partial<SSOForm> => {
    const current = getValues();
    const keys = getProviderKeys(provider);
    return Object.fromEntries(keys.map((k) => [k, current[k]])) as Partial<SSOForm>;
  };

  const saveGlobal = async () => {
    const valid = await trigger(GLOBAL_KEYS as (keyof SSOForm)[]);
    if (!valid) return;
    const payload = getGlobalPayload();
    setIsSavingGlobal(true);
    try {
      await api.put("/sso-settings", payload);
      toast.success("Global SSO options saved");
      setOriginalValues((prev) => ({ ...prev, ...payload }));
    } catch (err: unknown) {
      const msg =
        err && typeof err === "object" && "response" in err
          ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
          : null;
      toast.error(msg ?? "Failed to save global options");
    } finally {
      setIsSavingGlobal(false);
    }
  };

  const saveProvider = async (provider: SSOSetupProviderId) => {
    const keys = getProviderKeys(provider);
    const valid = await trigger(keys);
    if (!valid) return;
    const payload = getProviderPayload(provider);
    setSavingProvider(provider);
    try {
      await api.put("/sso-settings", payload);
      toast.success(`${provider === "oidc" ? "Enterprise SSO" : provider.charAt(0).toUpperCase() + provider.slice(1)} settings saved`);
      setOriginalValues((prev) => ({ ...prev, ...payload }));
    } catch (err: unknown) {
      const msg =
        err && typeof err === "object" && "response" in err
          ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
          : null;
      toast.error(msg ?? "Failed to save settings");
    } finally {
      setSavingProvider(null);
    }
  };

  const copyRedirectUri = (uri: string) => {
    void navigator.clipboard.writeText(uri).then(() => toast.success("Redirect URI copied to clipboard"));
  };

  const testConnection = async (provider: SSOSetupProviderId) => {
    setTestingProvider(provider);
    try {
      const response = await api.post(`/sso-settings/test/${provider}`);
      const message = response.data?.message ?? "Connection successful";
      toast.success(message);

      // Update form directly instead of refetching (avoids full page reload which collapses all cards)
      const testPassedKey = `${provider}_test_passed` as keyof SSOForm;
      const enabledKey = `${provider}_enabled` as keyof SSOForm;
      setValue(testPassedKey, true as never, { shouldDirty: true });
      setValue(enabledKey, true as never, { shouldDirty: true });
      setOriginalValues((prev) => ({ ...prev, [testPassedKey]: true }));
    } catch (err: unknown) {
      const msg =
        err && typeof err === "object" && "response" in err
          ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
          : null;
      toast.error(msg ?? "Connection test failed");
    } finally {
      setTestingProvider(null);
    }
  };

  if (isLoading) {
    return <SettingsPageSkeleton />;
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">SSO settings</h1>
        <p className="text-muted-foreground mt-1">
          Configure single sign-on providers. Credentials are stored securely and take effect immediately.{" "}
          <HelpLink articleId="sso-configuration" />
        </p>
      </div>

      <form onSubmit={(e) => e.preventDefault()} className="space-y-6">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Global options</CardTitle>
            <CardDescription>
              Master switch and behavior for SSO login and account linking.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <SettingsSwitchRow
              label="Enable SSO"
              description="Allow users to sign in with SSO providers"
              checked={watch("enabled")}
              onCheckedChange={(checked) => setValue("enabled", checked, { shouldDirty: true })}
            />
            <SettingsSwitchRow
              label="Allow account linking"
              description="Let users link multiple SSO providers to one account"
              tooltip={TOOLTIP_CONTENT.sso.allow_linking}
              checked={watch("allow_linking")}
              onCheckedChange={(checked) => setValue("allow_linking", checked, { shouldDirty: true })}
            />
            <SettingsSwitchRow
              label="Auto-register"
              description="Create accounts for new SSO logins"
              tooltip={TOOLTIP_CONTENT.sso.auto_register}
              checked={watch("auto_register")}
              onCheckedChange={(checked) => setValue("auto_register", checked, { shouldDirty: true })}
            />
            <SettingsSwitchRow
              label="Trust provider email"
              description="Treat SSO provider emails as verified"
              tooltip={TOOLTIP_CONTENT.sso.trust_provider_email}
              checked={watch("trust_provider_email")}
              onCheckedChange={(checked) => setValue("trust_provider_email", checked, { shouldDirty: true })}
            />
          </CardContent>
          <CardFooter className="flex flex-col gap-4 sm:flex-row sm:justify-between">
            <p className="text-sm text-muted-foreground">
              Master switch and SSO behavior options.
            </p>
            <SaveButton
              type="button"
              isDirty={isGlobalDirty()}
              isSaving={isSavingGlobal}
              onClick={saveGlobal}
            />
          </CardFooter>
        </Card>

        {providers.map(({ id, label, clientIdKey, clientSecretKey, enabledKey, testPassedKey }) => {
          const clientIdValue = watch(clientIdKey);
          const clientSecretValue = watch(clientSecretKey);
          const configured = !!(typeof clientIdValue === 'string' && clientIdValue.trim());
          const hasSecret = !!(typeof clientSecretValue === 'string' && clientSecretValue.trim());
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
              key={id}
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
                    setSetupModalProvider(id);
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
                        onClick={() => copyRedirectUri(redirectUri)}
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
                    onClick={() => testConnection(id)}
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
                      {...register(clientIdKey, {
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
                      {...register(clientSecretKey)}
                      className="min-h-[44px]"
                    />
                  </FormField>
                </div>
                <CardFooter className="flex justify-end">
                  <SaveButton
                    type="button"
                    isDirty={isProviderDirty(id)}
                    isSaving={savingProvider === id}
                    onClick={() => saveProvider(id)}
                  />
                </CardFooter>
              </div>
            </CollapsibleCard>
          );
        })}

        <CollapsibleCard
          title="Enterprise SSO (OIDC)"
          description="Generic OIDC provider for Okta, Auth0, Keycloak, or other IdPs."
          icon={<ProviderIcon provider="key" size="sm" style="mono" />}
          status={{
            label: (() => {
              const configured = !!(watch("oidc_client_id")?.trim() && watch("oidc_issuer_url")?.trim());
              const testPassed = !!watch("oidc_test_passed");
              const enabled = watch("oidc_enabled");
              return !configured ? "Not configured" : !testPassed ? "Test required" : enabled ? "Enabled" : "Test passed";
            })(),
            variant: (() => {
              const configured = !!(watch("oidc_client_id")?.trim() && watch("oidc_issuer_url")?.trim());
              const testPassed = !!watch("oidc_test_passed");
              const enabled = watch("oidc_enabled");
              return !configured ? "default" : !testPassed ? "warning" : enabled ? "success" : "default";
            })(),
          }}
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
                setSetupModalProvider("oidc");
              }}
            >
              Setup instructions
            </Button>
          }
        >
          <div className="space-y-4">
            {!!(watch("oidc_client_id")?.trim() && watch("oidc_issuer_url")?.trim()) && !watch("oidc_test_passed") && (
              <p className="text-muted-foreground text-sm">
                Run &quot;Test connection&quot; successfully to enable this provider on the login page.
              </p>
            )}
            {!!(watch("oidc_client_id")?.trim() && watch("oidc_issuer_url")?.trim()) && !!watch("oidc_test_passed") && (
              <SettingsSwitchRow
                label="Enable Enterprise SSO on login page"
                description="Show this provider on the sign-in page. Turn off to hide without removing credentials."
                checked={watch("oidc_enabled")}
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
                    onClick={() => copyRedirectUri(getRedirectUri(appUrl, "oidc"))}
                    aria-label="Copy redirect URI"
                  >
                    <Copy className="h-4 w-4" aria-hidden />
                  </Button>
                </div>
              </div>
            )}
            {!!(watch("oidc_client_id")?.trim() && watch("oidc_issuer_url")?.trim()) && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={!!testingProvider || !watch("oidc_client_secret")?.trim()}
                onClick={() => testConnection("oidc")}
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
                  {...register("oidc_client_id", {
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
                  {...register("oidc_client_secret")}
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
                  {...register("oidc_issuer_url", {
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
                  {...register("oidc_provider_name")}
                  className="min-h-[44px]"
                />
              </FormField>
            </div>
            <CardFooter className="flex justify-end">
              <SaveButton
                type="button"
                isDirty={isProviderDirty("oidc")}
                isSaving={savingProvider === "oidc"}
                onClick={() => saveProvider("oidc")}
              />
            </CardFooter>
          </div>
        </CollapsibleCard>
      </form>

      {setupModalProvider && (
        <SSOSetupModal
          open={setupModalProvider !== null}
          onOpenChange={(open) => !open && setSetupModalProvider(null)}
          provider={setupModalProvider}
          redirectUri={appUrl ? getRedirectUri(appUrl, setupModalProvider) : ""}
        />
      )}
    </div>
  );
}
