"use client";

import { useState, useEffect } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { errorLogger } from "@/lib/error-logger";
import { useAppConfig } from "@/lib/app-config";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { SettingsSwitchRow } from "@/components/ui/settings-switch-row";
import { SaveButton } from "@/components/ui/save-button";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { HelpTooltip } from "@/components/ui/help-tooltip";
import { HelpLink } from "@/components/help/help-link";
import { AlertTriangle, ShieldCheck } from "lucide-react";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { TOOLTIP_CONTENT } from "@/lib/tooltip-content";

const AUTH_MODES = ["disabled", "optional", "required"] as const;

interface AuthSettings {
  email_verification_mode: string;
  password_reset_enabled: boolean;
  two_factor_mode: string;
  passkey_mode: string;
}

const defaultAuthSettings: AuthSettings = {
  email_verification_mode: "optional",
  password_reset_enabled: true,
  two_factor_mode: "optional",
  passkey_mode: "disabled",
};

export default function SecurityPage() {
  const { features } = useAppConfig();
  const emailConfigured = features?.emailConfigured ?? false;

  const [authSettings, setAuthSettings] = useState<AuthSettings>(defaultAuthSettings);
  const [authLoading, setAuthLoading] = useState(true);
  const [authSaving, setAuthSaving] = useState(false);
  const [authDirty, setAuthDirty] = useState(false);

  useEffect(() => {
    api
      .get<{ settings: AuthSettings }>("/auth-settings")
      .then((res) => {
        const s = res.data.settings ?? {};
        setAuthSettings({
          email_verification_mode: s.email_verification_mode ?? "optional",
          password_reset_enabled: s.password_reset_enabled ?? true,
          two_factor_mode: s.two_factor_mode ?? "optional",
          passkey_mode: s.passkey_mode ?? "disabled",
        });
      })
      .catch(() => {
        errorLogger.report(
          new Error("Failed to load auth settings"),
          { source: "configuration-security-page" }
        );
      })
      .finally(() => setAuthLoading(false));
  }, []);

  const updateAuthSetting = <K extends keyof AuthSettings>(key: K, value: AuthSettings[K]) => {
    setAuthSettings((prev) => ({ ...prev, [key]: value }));
    setAuthDirty(true);
  };

  const handleSaveAuth = async () => {
    setAuthSaving(true);
    try {
      await api.put("/auth-settings", authSettings);
      toast.success("Auth settings saved.");
      setAuthDirty(false);
    } catch (error: unknown) {
      toast.error(
        error instanceof Error ? error.message : "Failed to save auth settings"
      );
    } finally {
      setAuthSaving(false);
    }
  };

  const authDependsOnEmail =
    !emailConfigured &&
    (authSettings.email_verification_mode !== "disabled" || authSettings.password_reset_enabled);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Security</h1>
        <p className="text-muted-foreground">
          Configure authentication features for all users. For password, 2FA, passkeys, and
          connected accounts, use Security in the user menu.{" "}
          <HelpLink articleId="security-settings" />
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <ShieldCheck className="h-5 w-5" />
            Authentication (system-wide)
          </CardTitle>
          <CardDescription>
            Configure authentication features for all users. Email verification and password reset
            require email to be configured under Email.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          {authDependsOnEmail && (
            <Alert variant="warning">
              <AlertTriangle className="h-4 w-4" />
              <AlertTitle>Email not configured</AlertTitle>
              <AlertDescription>
                Email verification and password reset require email to be configured under
                Configuration → Email. Until then, verification will be skipped and password reset
                will be unavailable.
              </AlertDescription>
            </Alert>
          )}
          {authLoading ? (
            <SettingsPageSkeleton minHeight="120px" />
          ) : (
            <>
              <div className="space-y-3">
                <Label className="flex items-center gap-1.5">
                  Email verification
                  <HelpTooltip content={TOOLTIP_CONTENT.security.email_verification} />
                </Label>
                <RadioGroup
                  value={authSettings.email_verification_mode}
                  onValueChange={(v) => updateAuthSetting("email_verification_mode", v)}
                  className="flex flex-wrap gap-4"
                >
                  {AUTH_MODES.map((mode) => (
                    <div key={mode} className="flex items-center space-x-2">
                      <RadioGroupItem value={mode} id={`ev-${mode}`} />
                      <Label htmlFor={`ev-${mode}`} className="font-normal capitalize">
                        {mode}
                      </Label>
                    </div>
                  ))}
                </RadioGroup>
                <p className="text-sm text-muted-foreground">
                  Disabled: no verification. Optional: prompt users. Required: must verify before
                  accessing the app.
                </p>
              </div>
              <SettingsSwitchRow
                label="Self-service password reset"
                description="Allow users to reset their password via email (Forgot password link on login)."
                checked={authSettings.password_reset_enabled}
                onCheckedChange={(v) => updateAuthSetting("password_reset_enabled", v)}
              />
              <div className="space-y-3">
                <Label className="flex items-center gap-1.5">
                  Two-factor authentication
                  <HelpTooltip content={TOOLTIP_CONTENT.security.two_factor_mode} />
                </Label>
                <RadioGroup
                  value={authSettings.two_factor_mode}
                  onValueChange={(v) => updateAuthSetting("two_factor_mode", v)}
                  className="flex flex-wrap gap-4"
                >
                  {AUTH_MODES.map((mode) => (
                    <div key={mode} className="flex items-center space-x-2">
                      <RadioGroupItem value={mode} id={`2fa-${mode}`} />
                      <Label htmlFor={`2fa-${mode}`} className="font-normal capitalize">
                        {mode}
                      </Label>
                    </div>
                  ))}
                </RadioGroup>
                <p className="text-sm text-muted-foreground">
                  Disabled: 2FA not available. Optional: users can enable in security settings.
                  Required: all users must set up 2FA.
                </p>
              </div>
              <div className="space-y-3">
                <Label className="flex items-center gap-1.5">
                  Passkey mode
                  <HelpTooltip content={TOOLTIP_CONTENT.security.passkey_mode} />
                </Label>
                <RadioGroup
                  value={authSettings.passkey_mode}
                  onValueChange={(v) => updateAuthSetting("passkey_mode", v)}
                  className="flex flex-wrap gap-4"
                >
                  {AUTH_MODES.map((mode) => (
                    <div key={mode} className="flex items-center space-x-2">
                      <RadioGroupItem value={mode} id={`passkey-${mode}`} />
                      <Label htmlFor={`passkey-${mode}`} className="font-normal capitalize">
                        {mode}
                      </Label>
                    </div>
                  ))}
                </RadioGroup>
                <p className="text-sm text-muted-foreground">
                  Passkeys provide phishing-resistant sign-in (fingerprint, face, or security key).
                  Disabled: not available. Optional: users can add passkeys. Required: users must
                  register a passkey.
                </p>
                <p className="text-xs text-muted-foreground">
                  Passkeys require HTTPS in production. Works on localhost for development.
                </p>
              </div>
            </>
          )}
        </CardContent>
        {!authLoading && (
          <CardFooter className="flex justify-end">
            <SaveButton
              type="button"
              onClick={handleSaveAuth}
              isSaving={authSaving}
              isDirty={authDirty}
            />
          </CardFooter>
        )}
      </Card>
    </div>
  );
}
