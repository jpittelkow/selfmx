"use client";

import { useState, useEffect, useCallback } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import {
  SSOSetupModal,
  type SSOSetupProviderId,
} from "@/components/admin/sso-setup-modal";
import { HelpLink } from "@/components/help/help-link";
import { SSOGlobalOptionsCard } from "@/components/sso/sso-global-options-card";
import { SSOProviderCard } from "@/components/sso/sso-provider-card";
import { SSOOidcCard } from "@/components/sso/sso-oidc-card";
import {
  ssoSchema,
  type SSOForm,
  defaultValues,
  providers,
  toBool,
  getRedirectUri,
  GLOBAL_KEYS,
  getProviderKeys,
} from "@/components/sso/types";

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

  const getPayload = (keys: (keyof SSOForm)[]): Partial<SSOForm> => {
    const current = getValues();
    return Object.fromEntries(keys.map((k) => [k, current[k]])) as Partial<SSOForm>;
  };

  const saveGlobal = async () => {
    const valid = await trigger(GLOBAL_KEYS as (keyof SSOForm)[]);
    if (!valid) return;
    const payload = getPayload(GLOBAL_KEYS);
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
    const payload = getPayload(keys);
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

  const testConnection = async (provider: SSOSetupProviderId) => {
    setTestingProvider(provider);
    try {
      const response = await api.post(`/sso-settings/test/${provider}`);
      const message = response.data?.message ?? "Connection successful";
      toast.success(message);

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
        <SSOGlobalOptionsCard
          watch={watch}
          setValue={setValue}
          isDirty={isGlobalDirty()}
          isSaving={isSavingGlobal}
          onSave={saveGlobal}
        />

        {providers.map((provider) => (
          <SSOProviderCard
            key={provider.id}
            {...provider}
            appUrl={appUrl}
            register={register}
            watch={watch}
            setValue={setValue}
            errors={errors}
            isDirty={isProviderDirty(provider.id)}
            isSaving={savingProvider === provider.id}
            testingProvider={testingProvider}
            onSave={() => saveProvider(provider.id)}
            onTest={() => testConnection(provider.id)}
            onSetupInstructions={() => setSetupModalProvider(provider.id)}
          />
        ))}

        <SSOOidcCard
          appUrl={appUrl}
          register={register}
          watch={watch}
          setValue={setValue}
          errors={errors}
          isDirty={isProviderDirty("oidc")}
          isSaving={savingProvider === "oidc"}
          testingProvider={testingProvider}
          onSave={() => saveProvider("oidc")}
          onTest={() => testConnection("oidc")}
          onSetupInstructions={() => setSetupModalProvider("oidc")}
        />
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
