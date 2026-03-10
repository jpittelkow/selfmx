"use client";

import { useState, useEffect } from "react";
import { useForm, UseFormRegister, UseFormWatch, UseFormSetValue, FieldErrors, FieldValues } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { CollapsibleCard } from "@/components/ui/collapsible-card";
import { FormField } from "@/components/ui/form-field";
import { SaveButton } from "@/components/ui/save-button";
import {
  Loader2,
  CheckCircle,
  XCircle,
  Key,
  Bell,
  MessageSquare,
  Phone,
} from "lucide-react";

// ── Props ────────────────────────────────────────────────────────────────

interface CredentialsTabProps {
  settings: Record<string, unknown>;
  onSaved: () => Promise<void>;
}

// ── Helpers ──────────────────────────────────────────────────────────────

function extractErrorMessage(err: unknown): string | null {
  if (err && typeof err === "object" && "response" in err) {
    return (err as { response?: { data?: { message?: string } } }).response?.data?.message ?? null;
  }
  return null;
}

function getDefaultValue(field: string): string | boolean {
  if (field === "ntfy_enabled") return true;
  if (field === "sns_enabled") return false;
  return "";
}

// ── Zod validators ───────────────────────────────────────────────────────

/** Allow empty string or validate format when present */
const optionalUrl = z.string().optional().refine(
  (v) => !v || /^https?:\/\/.+/.test(v),
  { message: "Must be a valid URL (https://...)" }
);
const optionalPhone = z.string().optional().refine(
  (v) => !v || (/^\+?[\d\s()-]{7,}$/.test(v) && (v.match(/\d/g) || []).length >= 7),
  { message: "Must be a valid phone number with at least 7 digits" }
);

// ── Per-channel schemas ──────────────────────────────────────────────────

const telegramSchema = z.object({
  telegram_bot_token: z.string().optional().refine(
    (v) => !v || /^\d+:[A-Za-z0-9_-]+$/.test(v),
    { message: "Must be a valid Telegram bot token (e.g. 123456:ABC-DEF...)" }
  ),
});

const discordSchema = z.object({
  discord_webhook_url: optionalUrl,
  discord_bot_name: z.string().optional(),
  discord_avatar_url: optionalUrl,
});

const slackSchema = z.object({
  slack_webhook_url: optionalUrl,
  slack_bot_name: z.string().optional(),
  slack_icon: z.string().optional(),
});

const signalSchema = z.object({
  signal_cli_path: z.string().optional(),
  signal_phone_number: optionalPhone,
  signal_config_dir: z.string().optional(),
});

const twilioSchema = z.object({
  twilio_sid: z.string().optional().refine(
    (v) => !v || v.startsWith("AC"),
    { message: "Twilio SID must start with 'AC'" }
  ),
  twilio_token: z.string().optional(),
  twilio_from: optionalPhone,
});

const vonageSchema = z.object({
  vonage_api_key: z.string().optional(),
  vonage_api_secret: z.string().optional(),
  vonage_from: optionalPhone,
});

const snsSchema = z.object({
  sns_enabled: z.boolean().default(false),
});

const webpushSchema = z.object({
  vapid_public_key: z.string().optional(),
  vapid_private_key: z.string().optional(),
  vapid_subject: z.string().optional().refine(
    (v) => !v || /^mailto:.+@.+/.test(v) || /^https?:\/\/.+/.test(v),
    { message: "Must be a mailto: or https:// URL" }
  ),
});

const fcmSchema = z.object({
  fcm_project_id: z.string().optional(),
  fcm_service_account: z.string().optional().refine(
    (v) => {
      if (!v) return true;
      try { JSON.parse(v); return true; } catch { return false; }
    },
    { message: "Must be valid JSON" }
  ),
});

const ntfySchema = z.object({
  ntfy_enabled: z.boolean().default(true),
  ntfy_server: optionalUrl,
});

const matrixSchema = z.object({
  matrix_homeserver: optionalUrl,
  matrix_access_token: z.string().optional(),
  matrix_default_room: z.string().optional(),
});

// ── Channel configuration ────────────────────────────────────────────────

interface ChannelConfig {
  channelId: string;
  title: string;
  description: string;
  icon: React.ReactNode;
  schema: z.ZodObject<z.ZodRawShape>;
  fields: string[];
  statusFields: string[];
}

const CHANNEL_CONFIGS: ChannelConfig[] = [
  {
    channelId: "telegram",
    title: "Telegram",
    description: "Bot token from BotFather",
    icon: <MessageSquare className="h-4 w-4" />,
    schema: telegramSchema as z.ZodObject<z.ZodRawShape>,
    fields: ["telegram_bot_token"],
    statusFields: ["telegram_bot_token"],
  },
  {
    channelId: "discord",
    title: "Discord",
    description: "Webhook URL and optional display name",
    icon: <MessageSquare className="h-4 w-4" />,
    schema: discordSchema as z.ZodObject<z.ZodRawShape>,
    fields: ["discord_webhook_url", "discord_bot_name", "discord_avatar_url"],
    statusFields: ["discord_webhook_url"],
  },
  {
    channelId: "slack",
    title: "Slack",
    description: "Webhook URL and optional display",
    icon: <MessageSquare className="h-4 w-4" />,
    schema: slackSchema as z.ZodObject<z.ZodRawShape>,
    fields: ["slack_webhook_url", "slack_bot_name", "slack_icon"],
    statusFields: ["slack_webhook_url"],
  },
  {
    channelId: "signal",
    title: "Signal",
    description: "signal-cli path and phone number",
    icon: <Phone className="h-4 w-4" />,
    schema: signalSchema as z.ZodObject<z.ZodRawShape>,
    fields: ["signal_cli_path", "signal_phone_number", "signal_config_dir"],
    statusFields: ["signal_cli_path", "signal_phone_number"],
  },
  {
    channelId: "twilio",
    title: "Twilio (SMS)",
    description: "Account SID, token, and from number",
    icon: <Phone className="h-4 w-4" />,
    schema: twilioSchema as z.ZodObject<z.ZodRawShape>,
    fields: ["twilio_sid", "twilio_token", "twilio_from"],
    statusFields: ["twilio_sid", "twilio_token"],
  },
  {
    channelId: "vonage",
    title: "Vonage (SMS)",
    description: "API key, secret, and from number",
    icon: <Phone className="h-4 w-4" />,
    schema: vonageSchema as z.ZodObject<z.ZodRawShape>,
    fields: ["vonage_api_key", "vonage_api_secret", "vonage_from"],
    statusFields: ["vonage_api_key", "vonage_api_secret"],
  },
  {
    channelId: "sns",
    title: "AWS SNS (SMS)",
    description: "Uses mail SES AWS credentials. Enable SNS here.",
    icon: <Phone className="h-4 w-4" />,
    schema: snsSchema as z.ZodObject<z.ZodRawShape>,
    fields: ["sns_enabled"],
    statusFields: ["sns_enabled"],
  },
  {
    channelId: "webpush",
    title: "Web Push (VAPID)",
    description: "VAPID keys for web push notifications",
    icon: <Bell className="h-4 w-4" />,
    schema: webpushSchema as z.ZodObject<z.ZodRawShape>,
    fields: ["vapid_public_key", "vapid_private_key", "vapid_subject"],
    statusFields: ["vapid_public_key", "vapid_private_key"],
  },
  {
    channelId: "fcm",
    title: "Firebase Cloud Messaging",
    description: "FCM v1 API (service account JSON)",
    icon: <Bell className="h-4 w-4" />,
    schema: fcmSchema as z.ZodObject<z.ZodRawShape>,
    fields: ["fcm_project_id", "fcm_service_account"],
    statusFields: ["fcm_project_id", "fcm_service_account"],
  },
  {
    channelId: "ntfy",
    title: "ntfy",
    description: "Enable and optional server URL",
    icon: <Bell className="h-4 w-4" />,
    schema: ntfySchema as z.ZodObject<z.ZodRawShape>,
    fields: ["ntfy_enabled", "ntfy_server"],
    statusFields: ["ntfy_enabled"],
  },
  {
    channelId: "matrix",
    title: "Matrix",
    description: "Homeserver, access token, and default room",
    icon: <MessageSquare className="h-4 w-4" />,
    schema: matrixSchema as z.ZodObject<z.ZodRawShape>,
    fields: ["matrix_homeserver", "matrix_access_token", "matrix_default_room"],
    statusFields: ["matrix_homeserver", "matrix_access_token"],
  },
];

// ── ChannelCredentialCard ────────────────────────────────────────────────

interface TestResult {
  status: "idle" | "testing" | "success" | "error";
  message?: string;
}

function ChannelCredentialCard({
  config,
  initialValues,
  onSaved,
}: {
  config: ChannelConfig;
  initialValues: Record<string, unknown>;
  onSaved: () => Promise<void>;
}) {
  const defaults = Object.fromEntries(
    config.fields.map((f) => [f, initialValues[f] ?? getDefaultValue(f)])
  );

  const {
    register,
    handleSubmit,
    formState: { errors, isDirty },
    setValue,
    watch,
    reset,
  } = useForm({
    resolver: zodResolver(config.schema),
    mode: "onBlur",
    defaultValues: defaults,
  });

  // Reset form when server values change, but only if the form is not dirty
  // (avoids wiping unsaved edits when another card saves and triggers refetch)
  const serverValuesKey = JSON.stringify(config.fields.map((f) => initialValues[f]));
  useEffect(() => {
    if (isDirty) return; // don't overwrite unsaved user edits
    const newDefaults = Object.fromEntries(
      config.fields.map((f) => [f, initialValues[f] ?? getDefaultValue(f)])
    );
    reset(newDefaults);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [serverValuesKey]);

  const [isSaving, setIsSaving] = useState(false);
  const [testResult, setTestResult] = useState<TestResult>({ status: "idle" });
  const [isGeneratingVapid, setIsGeneratingVapid] = useState(false);

  // Auto-dismiss test results after 10 seconds
  useEffect(() => {
    if (testResult.status === "success" || testResult.status === "error") {
      const timer = setTimeout(() => setTestResult({ status: "idle" }), 10000);
      return () => clearTimeout(timer);
    }
  }, [testResult.status]);

  const runTest = async () => {
    setTestResult({ status: "testing" });
    try {
      await api.post(`/notification-settings/test/${config.channelId}`);
      setTestResult({ status: "success", message: "Test notification sent successfully" });
    } catch (err: unknown) {
      const msg = extractErrorMessage(err);
      setTestResult({ status: "error", message: msg ?? `Test failed for ${config.title}` });
    }
  };

  const onSubmit = async (data: Record<string, unknown>) => {
    setIsSaving(true);
    try {
      const payload: Record<string, string | boolean | null> = {};
      for (const [k, v] of Object.entries(data)) {
        payload[k] = typeof v === "string" && v === "" ? null : (v as string | boolean | null);
      }
      await api.put("/notification-settings", payload);
      toast.success(`${config.title} credentials saved`);
      // Reset dirty state so the useEffect can sync server values on refetch
      reset(data as Record<string, string | boolean>);
      await onSaved();

      // Auto-test after successful save only if channel has credentials
      const hasCredentials = config.statusFields.every((f) => {
        const val = data[f];
        return typeof val === "boolean" ? val : !!val;
      });
      if (hasCredentials) await runTest();
    } catch (err: unknown) {
      const msg = extractErrorMessage(err);
      toast.error(msg ?? `Failed to save ${config.title} credentials`);
    } finally {
      setIsSaving(false);
    }
  };

  const handleGenerateVapid = async () => {
    setIsGeneratingVapid(true);
    try {
      const res = await api.post("/notification-settings/generate-vapid");
      const { public_key, private_key } = res.data?.data ?? res.data;
      setValue("vapid_public_key", public_key, { shouldDirty: true });
      setValue("vapid_private_key", private_key, { shouldDirty: true });
      toast.success("VAPID keys generated. Click Save to apply.");
    } catch (err: unknown) {
      const msg = extractErrorMessage(err);
      toast.error(msg ?? "Failed to generate VAPID keys");
    } finally {
      setIsGeneratingVapid(false);
    }
  };

  // Derive "Configured" status from saved server values (not live form values)
  const isConfigured = config.statusFields.every((f) => {
    const val = initialValues[f];
    return typeof val === "boolean" ? val : !!val;
  });

  const statusLabel = config.channelId === "sns" || config.channelId === "ntfy"
    ? (isConfigured ? "Enabled" : "Disabled")
    : (isConfigured ? "Configured" : "Not configured");

  return (
    <CollapsibleCard
      title={config.title}
      description={config.description}
      icon={config.icon}
      status={{
        label: statusLabel,
        variant: isConfigured ? "success" : "default",
      }}
      defaultOpen={false}
    >
      <form onSubmit={handleSubmit(onSubmit)}>
        {renderChannelFields(config.channelId, register, errors, watch, setValue, {
          isGeneratingVapid,
          onGenerateVapid: handleGenerateVapid,
        })}

        {testResult.status !== "idle" && (
          <div
            className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-sm mt-4 ${
              testResult.status === "success"
                ? "border-green-500/50 text-green-600 dark:text-green-400"
                : testResult.status === "error"
                  ? "border-destructive/50 text-destructive"
                  : "border-muted text-muted-foreground"
            }`}
          >
            {testResult.status === "testing" && <Loader2 className="h-4 w-4 animate-spin shrink-0" />}
            {testResult.status === "success" && <CheckCircle className="h-4 w-4 shrink-0" />}
            {testResult.status === "error" && <XCircle className="h-4 w-4 shrink-0" />}
            <span>{testResult.status === "testing" ? "Testing..." : testResult.message}</span>
          </div>
        )}

        <div className="mt-4 flex items-center gap-2 flex-wrap">
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={runTest}
            disabled={isSaving || testResult.status === "testing"}
            className="min-h-[44px]"
          >
            {testResult.status === "testing" ? <Loader2 className="h-4 w-4 animate-spin" /> : "Test"}
          </Button>
          <SaveButton
            isDirty={isDirty}
            isSaving={isSaving}
          >
            Save {config.title}
          </SaveButton>
        </div>
      </form>
    </CollapsibleCard>
  );
}

// ── Field renderers ──────────────────────────────────────────────────────

function renderChannelFields(
  channelId: string,
  register: UseFormRegister<FieldValues>,
  errors: FieldErrors<FieldValues>,
  watch: UseFormWatch<FieldValues>,
  setValue: UseFormSetValue<FieldValues>,
  extra: {
    isGeneratingVapid: boolean;
    onGenerateVapid: () => void;
  },
) {
  switch (channelId) {
    case "telegram":
      return (
        <div className="space-y-4">
          <FormField id="telegram_bot_token" label="Bot token" error={errors.telegram_bot_token?.message as string | undefined}>
            <Input id="telegram_bot_token" type="password" placeholder="Optional" {...register("telegram_bot_token")} className="min-h-[44px]" />
          </FormField>
        </div>
      );

    case "discord":
      return (
        <div className="grid gap-4 md:grid-cols-2">
          <FormField id="discord_webhook_url" label="Webhook URL" error={errors.discord_webhook_url?.message as string | undefined}>
            <Input id="discord_webhook_url" type="password" placeholder="Optional" {...register("discord_webhook_url")} className="min-h-[44px]" />
          </FormField>
          <FormField id="discord_bot_name" label="Bot name" error={errors.discord_bot_name?.message as string | undefined}>
            <Input id="discord_bot_name" placeholder="selfmx" {...register("discord_bot_name")} className="min-h-[44px]" />
          </FormField>
          <FormField id="discord_avatar_url" label="Avatar URL" error={errors.discord_avatar_url?.message as string | undefined}>
            <Input id="discord_avatar_url" placeholder="Optional" {...register("discord_avatar_url")} className="min-h-[44px]" />
          </FormField>
        </div>
      );

    case "slack":
      return (
        <div className="grid gap-4 md:grid-cols-2">
          <FormField id="slack_webhook_url" label="Webhook URL" error={errors.slack_webhook_url?.message as string | undefined}>
            <Input id="slack_webhook_url" type="password" placeholder="Optional" {...register("slack_webhook_url")} className="min-h-[44px]" />
          </FormField>
          <FormField id="slack_bot_name" label="Bot name" error={errors.slack_bot_name?.message as string | undefined}>
            <Input id="slack_bot_name" placeholder="selfmx" {...register("slack_bot_name")} className="min-h-[44px]" />
          </FormField>
          <FormField id="slack_icon" label="Icon (e.g. :robot_face:)" error={errors.slack_icon?.message as string | undefined}>
            <Input id="slack_icon" placeholder=":robot_face:" {...register("slack_icon")} className="min-h-[44px]" />
          </FormField>
        </div>
      );

    case "signal":
      return (
        <div className="grid gap-4 md:grid-cols-2">
          <FormField id="signal_cli_path" label="CLI path" error={errors.signal_cli_path?.message as string | undefined}>
            <Input id="signal_cli_path" placeholder="/usr/local/bin/signal-cli" {...register("signal_cli_path")} className="min-h-[44px]" />
          </FormField>
          <FormField id="signal_phone_number" label="Phone number" error={errors.signal_phone_number?.message as string | undefined}>
            <Input id="signal_phone_number" type="password" placeholder="+1234567890" {...register("signal_phone_number")} className="min-h-[44px]" />
          </FormField>
          <FormField id="signal_config_dir" label="Config directory" error={errors.signal_config_dir?.message as string | undefined}>
            <Input id="signal_config_dir" placeholder="Optional" {...register("signal_config_dir")} className="min-h-[44px]" />
          </FormField>
        </div>
      );

    case "twilio":
      return (
        <div className="grid gap-4 md:grid-cols-2">
          <FormField id="twilio_sid" label="Account SID" error={errors.twilio_sid?.message as string | undefined}>
            <Input id="twilio_sid" placeholder="Optional" {...register("twilio_sid")} className="min-h-[44px]" />
          </FormField>
          <FormField id="twilio_token" label="Auth token" error={errors.twilio_token?.message as string | undefined}>
            <Input id="twilio_token" type="password" placeholder="Optional" {...register("twilio_token")} className="min-h-[44px]" />
          </FormField>
          <FormField id="twilio_from" label="From number" error={errors.twilio_from?.message as string | undefined}>
            <Input id="twilio_from" placeholder="+1234567890" {...register("twilio_from")} className="min-h-[44px]" />
          </FormField>
        </div>
      );

    case "vonage":
      return (
        <div className="grid gap-4 md:grid-cols-2">
          <FormField id="vonage_api_key" label="API key" error={errors.vonage_api_key?.message as string | undefined}>
            <Input id="vonage_api_key" placeholder="Optional" {...register("vonage_api_key")} className="min-h-[44px]" />
          </FormField>
          <FormField id="vonage_api_secret" label="API secret" error={errors.vonage_api_secret?.message as string | undefined}>
            <Input id="vonage_api_secret" type="password" placeholder="Optional" {...register("vonage_api_secret")} className="min-h-[44px]" />
          </FormField>
          <FormField id="vonage_from" label="From number" error={errors.vonage_from?.message as string | undefined}>
            <Input id="vonage_from" placeholder="Optional" {...register("vonage_from")} className="min-h-[44px]" />
          </FormField>
        </div>
      );

    case "sns":
      return (
        <div>
          <div className="flex items-center justify-between gap-4">
            <div>
              <Label>Enable SNS</Label>
              <p className="text-sm text-muted-foreground">Use AWS credentials from mail settings for SNS</p>
            </div>
            <Switch
              checked={watch("sns_enabled")}
              onCheckedChange={(checked: boolean) => setValue("sns_enabled", checked, { shouldDirty: true })}
            />
          </div>
        </div>
      );

    case "webpush":
      return (
        <div className="space-y-4">
          <div className="grid gap-4 md:grid-cols-2">
            <FormField id="vapid_public_key" label="Public key" error={errors.vapid_public_key?.message as string | undefined}>
              <Input id="vapid_public_key" placeholder="Optional" {...register("vapid_public_key")} className="min-h-[44px]" />
            </FormField>
            <FormField id="vapid_private_key" label="Private key" error={errors.vapid_private_key?.message as string | undefined}>
              <Input id="vapid_private_key" type="password" placeholder="Optional" {...register("vapid_private_key")} className="min-h-[44px]" />
            </FormField>
            <FormField id="vapid_subject" label="Subject (mailto or URL)" error={errors.vapid_subject?.message as string | undefined}>
              <Input id="vapid_subject" placeholder="Optional" {...register("vapid_subject")} className="min-h-[44px]" />
            </FormField>
            <div className="flex items-end">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={extra.onGenerateVapid}
                disabled={extra.isGeneratingVapid}
                className="min-h-[44px]"
              >
                {extra.isGeneratingVapid ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Key className="mr-2 h-4 w-4" />}
                Generate Keys
              </Button>
            </div>
          </div>
        </div>
      );

    case "fcm":
      return (
        <div className="space-y-4">
          <FormField id="fcm_project_id" label="Project ID" error={errors.fcm_project_id?.message as string | undefined}>
            <Input id="fcm_project_id" placeholder="my-project-id" {...register("fcm_project_id")} className="min-h-[44px]" />
          </FormField>
          <FormField id="fcm_service_account" label="Service Account JSON" error={errors.fcm_service_account?.message as string | undefined} description="Paste the full service account JSON key from Firebase Console.">
            <Input id="fcm_service_account" type="password" placeholder="Optional" {...register("fcm_service_account")} className="min-h-[44px]" />
          </FormField>
        </div>
      );

    case "ntfy":
      return (
        <div className="space-y-4">
          <div className="flex items-center justify-between gap-4">
            <Label>Enable ntfy</Label>
            <Switch
              checked={watch("ntfy_enabled")}
              onCheckedChange={(checked: boolean) => setValue("ntfy_enabled", checked, { shouldDirty: true })}
            />
          </div>
          <FormField id="ntfy_server" label="Server URL" error={errors.ntfy_server?.message as string | undefined}>
            <Input id="ntfy_server" placeholder="https://ntfy.sh" {...register("ntfy_server")} className="min-h-[44px]" />
          </FormField>
        </div>
      );

    case "matrix":
      return (
        <div className="grid gap-4 md:grid-cols-2">
          <FormField id="matrix_homeserver" label="Homeserver URL" error={errors.matrix_homeserver?.message as string | undefined}>
            <Input id="matrix_homeserver" placeholder="https://matrix.example.com" {...register("matrix_homeserver")} className="min-h-[44px]" />
          </FormField>
          <FormField id="matrix_access_token" label="Access token" error={errors.matrix_access_token?.message as string | undefined}>
            <Input id="matrix_access_token" type="password" placeholder="Optional" {...register("matrix_access_token")} className="min-h-[44px]" />
          </FormField>
          <FormField id="matrix_default_room" label="Default room ID" error={errors.matrix_default_room?.message as string | undefined}>
            <Input id="matrix_default_room" placeholder="Optional" {...register("matrix_default_room")} className="min-h-[44px]" />
          </FormField>
        </div>
      );

    default:
      return null;
  }
}

// ── Exported component ───────────────────────────────────────────────────

export function CredentialsTab({ settings, onSaved }: CredentialsTabProps) {
  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-lg font-semibold">Channel credentials</h2>
        <p className="text-sm text-muted-foreground mt-1">
          Configure API keys and webhooks for each channel. Each card saves independently. Empty fields fall back to environment variables.
        </p>
      </div>

      {CHANNEL_CONFIGS.map((config) => (
        <ChannelCredentialCard
          key={config.channelId}
          config={config}
          initialValues={settings}
          onSaved={onSaved}
        />
      ))}
    </div>
  );
}
