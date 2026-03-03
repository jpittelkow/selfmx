"use client";

import { useState, useEffect, useCallback } from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { useQueryClient } from "@tanstack/react-query";
import { AlertTriangle } from "lucide-react";
import { api } from "@/lib/api";
import { useAppConfig } from "@/lib/app-config";
import { getErrorMessage } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";
import { Switch } from "@/components/ui/switch";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { SaveButton } from "@/components/ui/save-button";
import { HelpTooltip } from "@/components/ui/help-tooltip";
import { HelpLink } from "@/components/help/help-link";
import { TOOLTIP_CONTENT } from "@/lib/tooltip-content";
import { TIMEZONES } from "@/lib/timezones";

const LOCALES = [
  { value: "en", label: "English" },
  { value: "en-US", label: "English (US)" },
  { value: "en-GB", label: "English (UK)" },
  { value: "en-CA", label: "English (Canada)" },
  { value: "en-AU", label: "English (Australia)" },
  { value: "es", label: "Spanish" },
  { value: "es-ES", label: "Spanish (Spain)" },
  { value: "es-MX", label: "Spanish (Mexico)" },
  { value: "fr", label: "French" },
  { value: "fr-FR", label: "French (France)" },
  { value: "fr-CA", label: "French (Canada)" },
  { value: "de", label: "German" },
  { value: "de-DE", label: "German (Germany)" },
  { value: "it", label: "Italian" },
  { value: "it-IT", label: "Italian (Italy)" },
  { value: "pt", label: "Portuguese" },
  { value: "pt-BR", label: "Portuguese (Brazil)" },
  { value: "pt-PT", label: "Portuguese (Portugal)" },
  { value: "ja", label: "Japanese" },
  { value: "ja-JP", label: "Japanese (Japan)" },
  { value: "zh", label: "Chinese" },
  { value: "zh-CN", label: "Chinese (Simplified)" },
  { value: "zh-TW", label: "Chinese (Traditional)" },
  { value: "ko", label: "Korean" },
  { value: "ko-KR", label: "Korean (Korea)" },
  { value: "ru", label: "Russian" },
  { value: "ru-RU", label: "Russian (Russia)" },
  { value: "ar", label: "Arabic" },
  { value: "nl", label: "Dutch" },
  { value: "nl-NL", label: "Dutch (Netherlands)" },
  { value: "pl", label: "Polish" },
  { value: "pl-PL", label: "Polish (Poland)" },
];

const systemSchema = z.object({
  general: z.object({
    app_name: z.string().min(1, "App name is required"),
    default_timezone: z.string().min(1, "Timezone is required"),
    default_locale: z.string().min(1, "Locale is required"),
  }),
  registration: z.object({
    enabled: z.boolean(),
    email_verification_required: z.boolean(),
    allowed_domains: z.string().optional(),
  }),
  security: z.object({
    session_timeout: z.number().min(5).max(1440),
    password_min_length: z.number().min(6).max(128),
    password_require_special: z.boolean(),
    max_login_attempts: z.number().min(3).max(10),
  }),
  defaults: z.object({
    default_llm_mode: z.string().optional(),
    default_theme: z.string().optional(),
  }),
});

type SystemForm = z.infer<typeof systemSchema>;

export default function SystemSettingsPage() {
  const { features } = useAppConfig();
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [settings, setSettings] = useState<SystemForm | null>(null);
  const [appUrl, setAppUrl] = useState("");
  const queryClient = useQueryClient();
  const emailConfigured = features?.emailConfigured ?? false;

  const { register, handleSubmit, formState: { errors, isDirty }, setValue, watch, reset } = useForm<SystemForm>({
    resolver: zodResolver(systemSchema),
    defaultValues: {
      general: {
        app_name: "",
        default_timezone: "UTC",
        default_locale: "en",
      },
      registration: {
        enabled: true,
        email_verification_required: true,
        allowed_domains: "",
      },
      security: {
        session_timeout: 120,
        password_min_length: 8,
        password_require_special: true,
        max_login_attempts: 5,
      },
      defaults: {
        default_llm_mode: "",
        default_theme: "light",
      },
    },
  });

  const fetchSettings = useCallback(async () => {
    setIsLoading(true);
    try {
      const response = await api.get("/system-settings");
      const data = response.data.settings;

      // app_url is env-controlled (read-only), store separately from form
      setAppUrl(data.general?.app_url || "");

      const formData: SystemForm = {
        general: {
          app_name: data.general?.app_name || "",
          default_timezone: data.general?.default_timezone || "UTC",
          default_locale: data.general?.default_locale || "en",
        },
        registration: {
          enabled: data.registration?.enabled ?? true,
          email_verification_required: data.registration?.email_verification_required ?? true,
          allowed_domains: data.registration?.allowed_domains || "",
        },
        security: {
          session_timeout: data.security?.session_timeout || 120,
          password_min_length: data.security?.password_min_length || 8,
          password_require_special: data.security?.password_require_special ?? true,
          max_login_attempts: data.security?.max_login_attempts || 5,
        },
        defaults: {
          default_llm_mode: data.defaults?.default_llm_mode || "",
          default_theme: data.defaults?.default_theme || "light",
        },
      };

      setSettings(formData);
      reset(formData);
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to load system settings"));
    } finally {
      setIsLoading(false);
    }
  }, [reset]);

  useEffect(() => {
    fetchSettings();
  }, [fetchSettings]);

  const onSubmit = async (data: SystemForm) => {
    setIsSaving(true);
    try {
      const settingsArray: Array<{ group: string; key: string; value: unknown; is_public: boolean }> = [];
      
      // General settings (app_url is env-controlled, not submitted)
      Object.entries(data.general).forEach(([key, value]) => {
        settingsArray.push({
          group: "general",
          key,
          value,
          is_public: key === "app_name",
        });
      });

      // Registration settings
      Object.entries(data.registration).forEach(([key, value]) => {
        settingsArray.push({
          group: "registration",
          key,
          value,
          is_public: false,
        });
      });

      // Security settings
      Object.entries(data.security).forEach(([key, value]) => {
        settingsArray.push({
          group: "security",
          key,
          value,
          is_public: false,
        });
      });

      // Defaults settings
      Object.entries(data.defaults).forEach(([key, value]) => {
        settingsArray.push({
          group: "defaults",
          key,
          value,
          is_public: key === "default_theme",
        });
      });

      await api.put("/system-settings", { settings: settingsArray });
      toast.success("System settings updated successfully");
      // Invalidate app-config cache so app name updates immediately
      queryClient.invalidateQueries({ queryKey: ["app-config"] });
      await fetchSettings();
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to update system settings"));
    } finally {
      setIsSaving(false);
    }
  };

  if (isLoading) {
    return <SettingsPageSkeleton />;
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">System Settings</h1>
        <p className="text-muted-foreground mt-1">
          Configure application-wide settings and defaults.{" "}
          <HelpLink articleId="admin-overview" />
        </p>
      </div>

      {!emailConfigured && (
        <Alert variant="warning">
          <AlertTriangle className="h-4 w-4" />
          <AlertTitle>Email Not Configured</AlertTitle>
          <AlertDescription>
            Email features are disabled. Password reset and email verification
            will not work until you{" "}
            <Link href="/configuration/email" className="underline hover:no-underline">
              configure email settings
            </Link>
            .
          </AlertDescription>
        </Alert>
      )}

      <form onSubmit={handleSubmit(onSubmit)}>
        <Tabs defaultValue="general" className="space-y-6">
          <TabsList>
            <TabsTrigger value="general">General</TabsTrigger>
            <TabsTrigger value="registration">Registration</TabsTrigger>
            <TabsTrigger value="security">Security</TabsTrigger>
            <TabsTrigger value="defaults">Defaults</TabsTrigger>
          </TabsList>

          <TabsContent value="general" forceMount className="data-[state=inactive]:hidden">
            <Card>
              <CardHeader>
                <CardTitle>General Settings</CardTitle>
                <CardDescription>
                  Basic application configuration
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="app_name" className="flex items-center gap-1.5">
                    Application Name
                    <HelpTooltip content={TOOLTIP_CONTENT.system.app_name} />
                  </Label>
                  <Input
                    id="app_name"
                    {...register("general.app_name")}
                    placeholder="selfmx"
                  />
                  {errors.general?.app_name && (
                    <p className="text-sm text-destructive">
                      {errors.general.app_name.message}
                    </p>
                  )}
                </div>

                <div className="space-y-2">
                  <Label className="flex items-center gap-1.5">
                    Application URL
                    <HelpTooltip content={TOOLTIP_CONTENT.system.app_url} />
                  </Label>
                  <div className="rounded-md border bg-muted/50 px-3 py-2 font-mono text-sm">
                    {appUrl || "Not set"}
                  </div>
                  <p className="text-xs text-muted-foreground">
                    Controlled by the <code className="rounded bg-muted px-1 py-0.5">APP_URL</code> environment variable.
                    Change it in your <code className="rounded bg-muted px-1 py-0.5">.env</code> file or Docker configuration.
                  </p>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="default_timezone">Default Timezone</Label>
                  <Select
                    value={watch("general.default_timezone")}
                    onValueChange={(value) => setValue("general.default_timezone", value, { shouldDirty: true })}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select timezone" />
                    </SelectTrigger>
                    <SelectContent>
                      {/* Show current value if not in curated list (e.g. from APP_TIMEZONE env) */}
                      {watch("general.default_timezone") && !TIMEZONES.some((tz) => tz.value === watch("general.default_timezone")) && (
                        <SelectItem value={watch("general.default_timezone")}>
                          {watch("general.default_timezone")}
                        </SelectItem>
                      )}
                      {TIMEZONES.map((tz) => (
                        <SelectItem key={tz.value} value={tz.value}>
                          {tz.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {errors.general?.default_timezone && (
                    <p className="text-sm text-destructive">
                      {errors.general.default_timezone.message}
                    </p>
                  )}
                </div>

                <div className="space-y-2">
                  <Label htmlFor="default_locale">Default Locale</Label>
                  <Select
                    value={watch("general.default_locale")}
                    onValueChange={(value) => setValue("general.default_locale", value, { shouldDirty: true })}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select locale" />
                    </SelectTrigger>
                    <SelectContent>
                      {LOCALES.map((locale) => (
                        <SelectItem key={locale.value} value={locale.value}>
                          {locale.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {errors.general?.default_locale && (
                    <p className="text-sm text-destructive">
                      {errors.general.default_locale.message}
                    </p>
                  )}
                </div>
              </CardContent>
              <CardFooter className="flex justify-end">
                <SaveButton isDirty={isDirty} isSaving={isSaving} />
              </CardFooter>
            </Card>
          </TabsContent>

          <TabsContent value="registration" forceMount className="data-[state=inactive]:hidden">
            <Card>
              <CardHeader>
                <CardTitle>Registration Settings</CardTitle>
                <CardDescription>
                  Control user registration and verification
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="flex items-center justify-between">
                  <div className="space-y-0.5">
                    <Label>Registration Enabled</Label>
                    <p className="text-sm text-muted-foreground">
                      Allow new users to register
                    </p>
                  </div>
                  <Switch
                    checked={watch("registration.enabled")}
                    onCheckedChange={(checked) =>
                      setValue("registration.enabled", checked, { shouldDirty: true })
                    }
                  />
                </div>

                <Separator />

                <div className="flex items-center justify-between">
                  <div className="space-y-0.5">
                    <Label>Email Verification Required</Label>
                    <p className="text-sm text-muted-foreground">
                      {emailConfigured
                        ? "Require users to verify their email address"
                        : "Requires email to be configured (see warning above)"}
                    </p>
                  </div>
                  <Switch
                    checked={watch("registration.email_verification_required")}
                    onCheckedChange={(checked) =>
                      setValue("registration.email_verification_required", checked, { shouldDirty: true })
                    }
                    disabled={!emailConfigured}
                  />
                </div>

                <Separator />

                <div className="space-y-2">
                  <Label htmlFor="allowed_domains">Allowed Email Domains</Label>
                  <Input
                    id="allowed_domains"
                    {...register("registration.allowed_domains")}
                    placeholder="example.com, company.com (comma-separated, leave empty for all)"
                  />
                  <p className="text-sm text-muted-foreground">
                    Restrict registration to specific email domains
                  </p>
                </div>
              </CardContent>
              <CardFooter className="flex justify-end">
                <SaveButton isDirty={isDirty} isSaving={isSaving} />
              </CardFooter>
            </Card>
          </TabsContent>

          <TabsContent value="security" forceMount className="data-[state=inactive]:hidden">
            <Card>
              <CardHeader>
                <CardTitle>Security Settings</CardTitle>
                <CardDescription>
                  Configure security policies and session management
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="session_timeout" className="flex items-center gap-1.5">
                    Session Timeout (minutes)
                    <HelpTooltip content={TOOLTIP_CONTENT.security.session_timeout} />
                  </Label>
                  <Input
                    id="session_timeout"
                    type="number"
                    {...register("security.session_timeout", { valueAsNumber: true })}
                    min={5}
                    max={1440}
                  />
                  {errors.security?.session_timeout && (
                    <p className="text-sm text-destructive">
                      {errors.security.session_timeout.message}
                    </p>
                  )}
                </div>

                <div className="space-y-2">
                  <Label htmlFor="password_min_length" className="flex items-center gap-1.5">
                    Minimum Password Length
                    <HelpTooltip content={TOOLTIP_CONTENT.security.password_min_length} />
                  </Label>
                  <Input
                    id="password_min_length"
                    type="number"
                    {...register("security.password_min_length", { valueAsNumber: true })}
                    min={6}
                    max={128}
                  />
                  {errors.security?.password_min_length && (
                    <p className="text-sm text-destructive">
                      {errors.security.password_min_length.message}
                    </p>
                  )}
                </div>

                <div className="flex items-center justify-between">
                  <div className="space-y-0.5">
                    <Label>Require Special Characters</Label>
                    <p className="text-sm text-muted-foreground">
                      Passwords must contain special characters
                    </p>
                  </div>
                  <Switch
                    checked={watch("security.password_require_special")}
                    onCheckedChange={(checked) =>
                      setValue("security.password_require_special", checked, { shouldDirty: true })
                    }
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="max_login_attempts" className="flex items-center gap-1.5">
                    Max Login Attempts
                    <HelpTooltip content={TOOLTIP_CONTENT.security.max_login_attempts} />
                  </Label>
                  <Input
                    id="max_login_attempts"
                    type="number"
                    {...register("security.max_login_attempts", { valueAsNumber: true })}
                    min={3}
                    max={10}
                  />
                  {errors.security?.max_login_attempts && (
                    <p className="text-sm text-destructive">
                      {errors.security.max_login_attempts.message}
                    </p>
                  )}
                </div>
              </CardContent>
              <CardFooter className="flex justify-end">
                <SaveButton isDirty={isDirty} isSaving={isSaving} />
              </CardFooter>
            </Card>
          </TabsContent>

          <TabsContent value="defaults" forceMount className="data-[state=inactive]:hidden">
            <Card>
              <CardHeader>
                <CardTitle>Default Settings</CardTitle>
                <CardDescription>
                  Set default values for new users
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="default_llm_mode">Default LLM Mode</Label>
                  <Select
                    value={watch("defaults.default_llm_mode") || ""}
                    onValueChange={(value) => setValue("defaults.default_llm_mode", value, { shouldDirty: true })}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select default LLM mode" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="single">Single</SelectItem>
                      <SelectItem value="council">Council</SelectItem>
                      <SelectItem value="auto">Auto</SelectItem>
                    </SelectContent>
                  </Select>
                  <p className="text-sm text-muted-foreground">
                    Default LLM mode for new users
                  </p>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="default_theme">Default Theme</Label>
                  <Select
                    value={watch("defaults.default_theme")}
                    onValueChange={(value) => setValue("defaults.default_theme", value, { shouldDirty: true })}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select theme" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="light">Light</SelectItem>
                      <SelectItem value="dark">Dark</SelectItem>
                      <SelectItem value="system">System</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </CardContent>
              <CardFooter className="flex justify-end">
                <SaveButton isDirty={isDirty} isSaving={isSaving} />
              </CardFooter>
            </Card>
          </TabsContent>
        </Tabs>
      </form>
    </div>
  );
}
