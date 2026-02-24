"use client";

import { useState, useEffect, useCallback } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { useQueryClient } from "@tanstack/react-query";
import { api } from "@/lib/api";
import { errorLogger } from "@/lib/error-logger";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { CollapsibleCard } from "@/components/ui/collapsible-card";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { FormField } from "@/components/ui/form-field";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { SaveButton } from "@/components/ui/save-button";
import {
  Loader2,
  Mail,
  MessageSquare,
  Phone,
  Bell,
  CheckCircle,
  XCircle,
  PlayCircle,
  MinusCircle,
  Key,
} from "lucide-react";
import { HelpLink } from "@/components/help/help-link";

interface AdminChannel {
  id: string;
  name: string;
  description: string;
  provider_configured: boolean;
  available: boolean;
  admin_toggle: boolean;
  sms_provider: boolean | null;
}

const channelIcons: Record<string, React.ReactNode> = {
  database: <Bell className="h-5 w-5" />,
  email: <Mail className="h-5 w-5" />,
  telegram: <MessageSquare className="h-5 w-5" />,
  discord: <MessageSquare className="h-5 w-5" />,
  slack: <MessageSquare className="h-5 w-5" />,
  signal: <Phone className="h-5 w-5" />,
  matrix: <MessageSquare className="h-5 w-5" />,
  twilio: <Phone className="h-5 w-5" />,
  vonage: <Phone className="h-5 w-5" />,
  sns: <Phone className="h-5 w-5" />,
  webpush: <Bell className="h-5 w-5" />,
  fcm: <Bell className="h-5 w-5" />,
  ntfy: <Bell className="h-5 w-5" />,
};

const SMS_LABELS: Record<string, string> = {
  twilio: "Twilio",
  vonage: "Vonage",
  sns: "AWS SNS",
};

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

/* eslint-disable @typescript-eslint/no-explicit-any */
function renderChannelFields(
  channelId: string,
  register: any,
  errors: any,
  watch: any,
  setValue: any,
  extra: {
    isGeneratingVapid: boolean;
    onGenerateVapid: () => void;
  },
) {
  switch (channelId) {
    case "telegram":
      return (
        <div className="space-y-4">
          <FormField id="telegram_bot_token" label="Bot token" error={errors.telegram_bot_token?.message}>
            <Input id="telegram_bot_token" type="password" placeholder="Optional" {...register("telegram_bot_token")} className="min-h-[44px]" />
          </FormField>
        </div>
      );

    case "discord":
      return (
        <div className="grid gap-4 md:grid-cols-2">
          <FormField id="discord_webhook_url" label="Webhook URL" error={errors.discord_webhook_url?.message}>
            <Input id="discord_webhook_url" type="password" placeholder="Optional" {...register("discord_webhook_url")} className="min-h-[44px]" />
          </FormField>
          <FormField id="discord_bot_name" label="Bot name" error={errors.discord_bot_name?.message}>
            <Input id="discord_bot_name" placeholder="Sourdough" {...register("discord_bot_name")} className="min-h-[44px]" />
          </FormField>
          <FormField id="discord_avatar_url" label="Avatar URL" error={errors.discord_avatar_url?.message}>
            <Input id="discord_avatar_url" placeholder="Optional" {...register("discord_avatar_url")} className="min-h-[44px]" />
          </FormField>
        </div>
      );

    case "slack":
      return (
        <div className="grid gap-4 md:grid-cols-2">
          <FormField id="slack_webhook_url" label="Webhook URL" error={errors.slack_webhook_url?.message}>
            <Input id="slack_webhook_url" type="password" placeholder="Optional" {...register("slack_webhook_url")} className="min-h-[44px]" />
          </FormField>
          <FormField id="slack_bot_name" label="Bot name" error={errors.slack_bot_name?.message}>
            <Input id="slack_bot_name" placeholder="Sourdough" {...register("slack_bot_name")} className="min-h-[44px]" />
          </FormField>
          <FormField id="slack_icon" label="Icon (e.g. :robot_face:)" error={errors.slack_icon?.message}>
            <Input id="slack_icon" placeholder=":robot_face:" {...register("slack_icon")} className="min-h-[44px]" />
          </FormField>
        </div>
      );

    case "signal":
      return (
        <div className="grid gap-4 md:grid-cols-2">
          <FormField id="signal_cli_path" label="CLI path" error={errors.signal_cli_path?.message}>
            <Input id="signal_cli_path" placeholder="/usr/local/bin/signal-cli" {...register("signal_cli_path")} className="min-h-[44px]" />
          </FormField>
          <FormField id="signal_phone_number" label="Phone number" error={errors.signal_phone_number?.message}>
            <Input id="signal_phone_number" type="password" placeholder="+1234567890" {...register("signal_phone_number")} className="min-h-[44px]" />
          </FormField>
          <FormField id="signal_config_dir" label="Config directory" error={errors.signal_config_dir?.message}>
            <Input id="signal_config_dir" placeholder="Optional" {...register("signal_config_dir")} className="min-h-[44px]" />
          </FormField>
        </div>
      );

    case "twilio":
      return (
        <div className="grid gap-4 md:grid-cols-2">
          <FormField id="twilio_sid" label="Account SID" error={errors.twilio_sid?.message}>
            <Input id="twilio_sid" placeholder="Optional" {...register("twilio_sid")} className="min-h-[44px]" />
          </FormField>
          <FormField id="twilio_token" label="Auth token" error={errors.twilio_token?.message}>
            <Input id="twilio_token" type="password" placeholder="Optional" {...register("twilio_token")} className="min-h-[44px]" />
          </FormField>
          <FormField id="twilio_from" label="From number" error={errors.twilio_from?.message}>
            <Input id="twilio_from" placeholder="+1234567890" {...register("twilio_from")} className="min-h-[44px]" />
          </FormField>
        </div>
      );

    case "vonage":
      return (
        <div className="grid gap-4 md:grid-cols-2">
          <FormField id="vonage_api_key" label="API key" error={errors.vonage_api_key?.message}>
            <Input id="vonage_api_key" placeholder="Optional" {...register("vonage_api_key")} className="min-h-[44px]" />
          </FormField>
          <FormField id="vonage_api_secret" label="API secret" error={errors.vonage_api_secret?.message}>
            <Input id="vonage_api_secret" type="password" placeholder="Optional" {...register("vonage_api_secret")} className="min-h-[44px]" />
          </FormField>
          <FormField id="vonage_from" label="From number" error={errors.vonage_from?.message}>
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
            <FormField id="vapid_public_key" label="Public key" error={errors.vapid_public_key?.message}>
              <Input id="vapid_public_key" placeholder="Optional" {...register("vapid_public_key")} className="min-h-[44px]" />
            </FormField>
            <FormField id="vapid_private_key" label="Private key" error={errors.vapid_private_key?.message}>
              <Input id="vapid_private_key" type="password" placeholder="Optional" {...register("vapid_private_key")} className="min-h-[44px]" />
            </FormField>
            <FormField id="vapid_subject" label="Subject (mailto or URL)" error={errors.vapid_subject?.message}>
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
          <FormField id="fcm_project_id" label="Project ID" error={errors.fcm_project_id?.message}>
            <Input id="fcm_project_id" placeholder="my-project-id" {...register("fcm_project_id")} className="min-h-[44px]" />
          </FormField>
          <FormField id="fcm_service_account" label="Service Account JSON" error={errors.fcm_service_account?.message} description="Paste the full service account JSON key from Firebase Console.">
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
          <FormField id="ntfy_server" label="Server URL" error={errors.ntfy_server?.message}>
            <Input id="ntfy_server" placeholder="https://ntfy.sh" {...register("ntfy_server")} className="min-h-[44px]" />
          </FormField>
        </div>
      );

    case "matrix":
      return (
        <div className="grid gap-4 md:grid-cols-2">
          <FormField id="matrix_homeserver" label="Homeserver URL" error={errors.matrix_homeserver?.message}>
            <Input id="matrix_homeserver" placeholder="https://matrix.example.com" {...register("matrix_homeserver")} className="min-h-[44px]" />
          </FormField>
          <FormField id="matrix_access_token" label="Access token" error={errors.matrix_access_token?.message}>
            <Input id="matrix_access_token" type="password" placeholder="Optional" {...register("matrix_access_token")} className="min-h-[44px]" />
          </FormField>
          <FormField id="matrix_default_room" label="Default room ID" error={errors.matrix_default_room?.message}>
            <Input id="matrix_default_room" placeholder="Optional" {...register("matrix_default_room")} className="min-h-[44px]" />
          </FormField>
        </div>
      );

    default:
      return null;
  }
}
/* eslint-enable @typescript-eslint/no-explicit-any */

// ── Main page ────────────────────────────────────────────────────────────

export default function NotificationsPage() {
  const queryClient = useQueryClient();
  const [channels, setChannels] = useState<AdminChannel[]>([]);
  const [smsProvider, setSmsProvider] = useState<string | null>(null);
  const [smsProvidersConfigured, setSmsProvidersConfigured] = useState<string[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [savingChannels, setSavingChannels] = useState<Set<string>>(new Set());
  const [settings, setSettings] = useState<Record<string, unknown>>({});
  const [verifyResults, setVerifyResults] = useState<Record<string, { status: string; error?: string; reason?: string }> | null>(null);
  const [isTestingAll, setIsTestingAll] = useState(false);

  const fetchConfig = useCallback(async () => {
    try {
      const [channelsRes, settingsRes] = await Promise.all([
        api.get("/admin/notification-channels"),
        api.get("/notification-settings"),
      ]);
      const channelData = channelsRes.data;
      setChannels(channelData.channels ?? []);
      setSmsProvider(channelData.sms_provider ?? null);
      setSmsProvidersConfigured(channelData.sms_providers_configured ?? []);
      setSettings(settingsRes.data?.settings ?? {});
      // Invalidate app-config cache so feature flags (webpushEnabled etc.) update immediately
      queryClient.invalidateQueries({ queryKey: ["app-config"] });
    } catch (e) {
      errorLogger.report(
        e instanceof Error ? e : new Error("Failed to fetch notification config"),
        { source: "notifications-page" }
      );
      toast.error("Failed to load notification configuration");
      setChannels([]);
      setSmsProvidersConfigured([]);
    }
  }, [queryClient]);

  useEffect(() => {
    const load = async () => {
      setIsLoading(true);
      await fetchConfig();
      setIsLoading(false);
    };
    load();
  }, [fetchConfig]);

  const handleToggleAvailable = async (channelId: string, available: boolean) => {
    setSavingChannels((prev) => new Set(prev).add(channelId));
    setChannels((prev) =>
      prev.map((ch) => (ch.id === channelId ? { ...ch, available } : ch))
    );
    try {
      const current = channels.find((c) => c.id === channelId);
      await api.put("/admin/notification-channels", {
        channels: [{ id: channelId, available }],
      });
      toast.success(`${current?.name ?? channelId} ${available ? "available" : "unavailable"} to users`);
    } catch (err: unknown) {
      setChannels((prev) =>
        prev.map((ch) => (ch.id === channelId ? { ...ch, available: !available } : ch))
      );
      const msg = extractErrorMessage(err);
      toast.error(msg ?? "Failed to update channel");
    } finally {
      setSavingChannels((prev) => {
        const next = new Set(prev);
        next.delete(channelId);
        return next;
      });
    }
  };

  const handleSmsProviderChange = async (value: string) => {
    const next = value === "__none__" ? null : value;
    const previous = smsProvider;
    setSmsProvider(next);
    try {
      await api.put("/admin/notification-channels", { sms_provider: next });
      toast.success("SMS provider updated");
    } catch (err: unknown) {
      setSmsProvider(previous);
      const msg = extractErrorMessage(err);
      toast.error(msg ?? "Failed to update SMS provider");
    }
  };

  const handleTestAllChannels = async () => {
    setIsTestingAll(true);
    setVerifyResults(null);
    try {
      const res = await api.post("/admin/notification-channels/test-all");
      const results = res.data?.results ?? {};
      setVerifyResults(results);
      const values = Object.values(results) as { status: string }[];
      const successes = values.filter((r) => r.status === "success").length;
      const failures = values.filter((r) => r.status === "error").length;
      if (failures > 0) {
        toast.warning(`${successes} passed, ${failures} failed`);
      } else {
        toast.success(`All ${successes} enabled channels passed`);
      }
    } catch (err: unknown) {
      const msg = extractErrorMessage(err);
      toast.error(msg ?? "Failed to test channels");
    } finally {
      setIsTestingAll(false);
    }
  };

  if (isLoading) {
    return <SettingsPageSkeleton />;
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight md:text-3xl">Notifications</h1>
        <p className="text-muted-foreground mt-1">
          Enable which notification channels are available to users. Configure channel credentials below. Users set their own webhooks and phone numbers in Preferences.{" "}
          <HelpLink articleId="notification-channels" />
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Verify Configuration</CardTitle>
          <CardDescription>
            Test all enabled channels to verify notifications are working correctly.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {verifyResults ? (
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              {Object.entries(verifyResults).map(([id, result]) => (
                <div
                  key={id}
                  className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                >
                  {result.status === "success" ? (
                    <CheckCircle className="h-4 w-4 shrink-0 text-green-500" />
                  ) : result.status === "error" ? (
                    <XCircle className="h-4 w-4 shrink-0 text-destructive" />
                  ) : (
                    <MinusCircle className="h-4 w-4 shrink-0 text-muted-foreground" />
                  )}
                  <span className="font-medium">
                    {channels.find((c) => c.id === id)?.name ?? id}
                  </span>
                  {result.status === "skipped" && (
                    <span className="text-xs text-muted-foreground">
                      ({result.reason === "not_configured" ? "not configured" : "not available"})
                    </span>
                  )}
                  {result.status === "error" && result.error && (
                    <span className="truncate text-xs text-destructive" title={result.error}>
                      {result.error.length > 40 ? result.error.substring(0, 40) + "..." : result.error}
                    </span>
                  )}
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-muted-foreground">
              Click the button below to send a test notification on every enabled channel.
            </p>
          )}
        </CardContent>
        <CardFooter>
          <Button
            onClick={handleTestAllChannels}
            disabled={isTestingAll}
          >
            {isTestingAll ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <PlayCircle className="mr-2 h-4 w-4" />
            )}
            Test all enabled channels
          </Button>
        </CardFooter>
      </Card>

      {smsProvidersConfigured.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>SMS provider</CardTitle>
            <CardDescription>
              Choose the preferred SMS provider. Users enter their phone number and test in Preferences.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-2 max-w-xs">
              <Label>Preferred SMS provider</Label>
              <Select
                value={smsProvider ?? "__none__"}
                onValueChange={handleSmsProviderChange}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select provider" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="__none__">None</SelectItem>
                  {smsProvidersConfigured.map((id) => (
                    <SelectItem key={id} value={id}>
                      {SMS_LABELS[id] ?? id}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </CardContent>
        </Card>
      )}

      <div className="space-y-4">
        <h2 className="text-lg font-semibold">Channels</h2>
        {channels.map((ch) => (
          <Card key={ch.id}>
            <CardHeader>
              <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div className="flex items-center gap-3">
                  <div
                    className={`p-2 rounded-full min-h-[44px] min-w-[44px] flex items-center justify-center ${
                      ch.available ? "bg-primary/10 text-primary" : "bg-muted text-muted-foreground"
                    }`}
                  >
                    {channelIcons[ch.id] ?? <Bell className="h-5 w-5" />}
                  </div>
                  <div>
                    <CardTitle className="flex flex-wrap items-center gap-2 text-base md:text-lg">
                      {ch.name}
                      {ch.provider_configured ? (
                        <span className="inline-flex items-center rounded-md bg-green-100 dark:bg-green-900/30 px-2 py-0.5 text-xs font-medium text-green-700 dark:text-green-400">
                          <CheckCircle className="mr-1 h-3 w-3" />
                          Configured
                        </span>
                      ) : (
                        <span className="inline-flex items-center rounded-md bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                          <XCircle className="mr-1 h-3 w-3" />
                          Not configured
                        </span>
                      )}
                      {!ch.admin_toggle && (
                        <span className="text-xs font-normal text-muted-foreground">(always available)</span>
                      )}
                    </CardTitle>
                    <CardDescription>{ch.description}</CardDescription>
                  </div>
                </div>
                {ch.admin_toggle ? (
                  <div className="flex items-center gap-2">
                    {savingChannels.has(ch.id) && (
                      <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                    )}
                    <Switch
                      checked={ch.available}
                      onCheckedChange={(checked) => handleToggleAvailable(ch.id, checked)}
                      disabled={!ch.provider_configured || savingChannels.has(ch.id)}
                    />
                    <Label className="text-sm text-muted-foreground">Available to users</Label>
                  </div>
                ) : (
                  <span className="text-sm text-muted-foreground">Always available</span>
                )}
              </div>
            </CardHeader>
          </Card>
        ))}
      </div>

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
            onSaved={fetchConfig}
          />
        ))}
      </div>

      <RateLimitingCard settings={settings} onSaved={fetchConfig} />
    </div>
  );
}

// ── Rate Limiting & Queue Card ──────────────────────────────────────────

const rateLimitSchema = z.object({
  rate_limit_enabled: z.boolean().default(false),
  rate_limit_max: z.coerce.number().int().min(1).max(1000).default(10),
  rate_limit_window_minutes: z.coerce.number().int().min(1).max(1440).default(60),
  queue_enabled: z.boolean().default(true),
});

function RateLimitingCard({
  settings,
  onSaved,
}: {
  settings: Record<string, unknown>;
  onSaved: () => Promise<void>;
}) {
  const {
    register,
    handleSubmit,
    formState: { errors, isDirty },
    watch,
    setValue,
    reset,
  } = useForm({
    resolver: zodResolver(rateLimitSchema),
    defaultValues: {
      rate_limit_enabled: settings.rate_limit_enabled === true,
      rate_limit_max: Number(settings.rate_limit_max) || 10,
      rate_limit_window_minutes: Number(settings.rate_limit_window_minutes) || 60,
      queue_enabled: settings.queue_enabled !== false,
    },
  });

  const serverKey = JSON.stringify([
    settings.rate_limit_enabled,
    settings.rate_limit_max,
    settings.rate_limit_window_minutes,
    settings.queue_enabled,
  ]);
  useEffect(() => {
    if (isDirty) return;
    reset({
      rate_limit_enabled: settings.rate_limit_enabled === true,
      rate_limit_max: Number(settings.rate_limit_max) || 10,
      rate_limit_window_minutes: Number(settings.rate_limit_window_minutes) || 60,
      queue_enabled: settings.queue_enabled !== false,
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [serverKey]);

  const [isSaving, setIsSaving] = useState(false);
  const rateLimitEnabled = watch("rate_limit_enabled");

  const onSubmit = async (data: z.infer<typeof rateLimitSchema>) => {
    setIsSaving(true);
    try {
      await api.put("/notification-settings", data);
      toast.success("Rate limiting settings saved");
      reset(data);
      await onSaved();
    } catch (err: unknown) {
      const msg = extractErrorMessage(err);
      toast.error(msg ?? "Failed to save rate limiting settings");
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Rate Limiting & Queue</CardTitle>
        <CardDescription>
          Control notification delivery rate per user per channel, and toggle async queue dispatch.
        </CardDescription>
      </CardHeader>
      <form onSubmit={handleSubmit(onSubmit)}>
        <CardContent className="space-y-6">
          <div className="flex items-center justify-between gap-4">
            <div>
              <Label>Async queue dispatch</Label>
              <p className="text-sm text-muted-foreground">
                Dispatch notifications via background queue instead of inline. Enables retry with exponential backoff for webhook channels.
              </p>
            </div>
            <Switch
              checked={watch("queue_enabled")}
              onCheckedChange={(checked: boolean) => setValue("queue_enabled", checked, { shouldDirty: true })}
            />
          </div>

          <div className="flex items-center justify-between gap-4">
            <div>
              <Label>Enable rate limiting</Label>
              <p className="text-sm text-muted-foreground">
                Limit how many notifications a user receives per channel within a time window.
              </p>
            </div>
            <Switch
              checked={rateLimitEnabled}
              onCheckedChange={(checked: boolean) => setValue("rate_limit_enabled", checked, { shouldDirty: true })}
            />
          </div>

          {rateLimitEnabled && (
            <div className="grid gap-4 sm:grid-cols-2 pl-4 border-l-2 border-muted">
              <FormField id="rate_limit_max" label="Max notifications per window" error={errors.rate_limit_max?.message as string | undefined}>
                <Input
                  id="rate_limit_max"
                  type="number"
                  min={1}
                  max={1000}
                  {...register("rate_limit_max")}
                  className="min-h-[44px]"
                />
              </FormField>
              <FormField id="rate_limit_window_minutes" label="Window (minutes)" error={errors.rate_limit_window_minutes?.message as string | undefined}>
                <Input
                  id="rate_limit_window_minutes"
                  type="number"
                  min={1}
                  max={1440}
                  {...register("rate_limit_window_minutes")}
                  className="min-h-[44px]"
                />
              </FormField>
            </div>
          )}
        </CardContent>
        <CardFooter>
          <SaveButton isDirty={isDirty} isSaving={isSaving}>
            Save
          </SaveButton>
        </CardFooter>
      </form>
    </Card>
  );
}
