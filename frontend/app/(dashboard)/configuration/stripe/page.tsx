"use client";

import { useState, useEffect, useCallback, Suspense } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { useRouter, useSearchParams } from "next/navigation";
import { api } from "@/lib/api";
import { getErrorMessage } from "@/lib/utils";
import {
  useStripeSettings,
  resetStripe,
  type StripeSettings,
  type ConnectStatus,
} from "@/lib/stripe";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import { CollapsibleCard } from "@/components/ui/collapsible-card";
import { FormField } from "@/components/ui/form-field";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { SaveButton } from "@/components/ui/save-button";
import { HelpLink } from "@/components/help/help-link";
import {
  Link2,
  Settings2,
  Loader2,
  CheckCircle2,
  XCircle,
  ExternalLink,
  Unplug,
} from "lucide-react";

// ---------------------------------------------------------------------------
// Schema (platform view only)
// ---------------------------------------------------------------------------

const stripeSchema = z.object({
  enabled: z.boolean(),
  mode: z.enum(["test", "live"]),
  deployment_role: z.enum(["platform", "fork"]),
  secret_key: z.string().optional(),
  publishable_key: z.string().optional(),
  webhook_secret: z.string().optional(),
  platform_account_id: z.string().optional(),
  platform_client_id: z.string().optional(),
  application_fee_percent: z.coerce.number().min(0).max(100).optional(),
  currency: z
    .string()
    .optional()
    .refine((v) => !v || v.length === 3, {
      message: "Currency must be a 3-letter code (e.g. usd)",
    }),
});

type StripeForm = z.infer<typeof stripeSchema>;

// ---------------------------------------------------------------------------
// OAuth callback handler (reads search params)
// ---------------------------------------------------------------------------

function OAuthCallbackHandler({ onComplete }: { onComplete: () => void }) {
  const searchParams = useSearchParams();
  const router = useRouter();

  useEffect(() => {
    const onboarding = searchParams.get("onboarding");
    const accountId = searchParams.get("account_id");
    const error = searchParams.get("error");

    if (onboarding === "complete" && accountId) {
      const safeAccountId = /^acct_[a-zA-Z0-9]+$/.test(accountId)
        ? accountId
        : "connected";
      toast.success(`Stripe account ${safeAccountId} connected successfully`);
      onComplete();
      router.replace("/configuration/stripe");
    } else if (error) {
      const safeError = String(error).slice(0, 200);
      toast.error(`Connect onboarding failed: ${safeError}`);
      router.replace("/configuration/stripe");
    }
  }, [searchParams, router, onComplete]);

  return null;
}

// ---------------------------------------------------------------------------
// Connect Section (shared between both views)
// ---------------------------------------------------------------------------

function ConnectSection({
  connectStatus,
  refetch,
}: {
  connectStatus: ConnectStatus;
  refetch: () => void;
}) {
  const [isActing, setIsActing] = useState(false);

  const handleConnect = async () => {
    setIsActing(true);
    try {
      const res = await api.post("/stripe/connect/oauth-link");
      window.location.href = res.data.url;
    } catch (err: unknown) {
      toast.error(getErrorMessage(err, "Failed to create Connect link"));
      setIsActing(false);
    }
  };

  const handleCompleteSetup = async () => {
    setIsActing(true);
    try {
      const res = await api.post("/stripe/connect/account-link");
      window.location.href = res.data.url;
    } catch (err: unknown) {
      toast.error(getErrorMessage(err, "Failed to create account link"));
      setIsActing(false);
    }
  };

  const handleDisconnect = async () => {
    setIsActing(true);
    try {
      await api.delete("/stripe/connect/disconnect");
      toast.success("Stripe account disconnected");
      refetch();
    } catch (err: unknown) {
      toast.error(getErrorMessage(err, "Failed to disconnect account"));
    } finally {
      setIsActing(false);
    }
  };

  // State: Not connected
  if (!connectStatus.connected) {
    return (
      <div className="space-y-4">
        <p className="text-sm text-muted-foreground">
          Connect your Stripe account to start accepting payments. You&apos;ll
          be redirected to Stripe to authorize your account.
        </p>
        <Button onClick={handleConnect} disabled={isActing}>
          {isActing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
          Connect Stripe Account
        </Button>
      </div>
    );
  }

  // State: Connected but pending (charges not yet enabled)
  if (!connectStatus.charges_enabled) {
    return (
      <div className="space-y-4">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant="warning">Setup Incomplete</Badge>
          <span className="text-sm text-muted-foreground">
            Account: {connectStatus.account_id}
          </span>
        </div>
        <p className="text-sm text-muted-foreground">
          Your Stripe account is connected but onboarding is not complete.
          Please finish the setup to enable charges.
        </p>
        <div className="flex flex-wrap gap-2">
          <Button onClick={handleCompleteSetup} disabled={isActing}>
            {isActing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            Complete Setup
          </Button>
          <AlertDialog>
            <AlertDialogTrigger asChild>
              <Button variant="destructive" disabled={isActing}>
                <Unplug className="mr-2 h-4 w-4" />
                Disconnect
              </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>Disconnect Stripe Account?</AlertDialogTitle>
                <AlertDialogDescription>
                  This will remove the connected Stripe account. You will need
                  to reconnect to accept payments.
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction onClick={handleDisconnect}>
                  Disconnect
                </AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </div>
      </div>
    );
  }

  // State: Fully active
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <Badge variant="success">Active</Badge>
        {connectStatus.payouts_enabled && (
          <Badge variant="success">Payouts Enabled</Badge>
        )}
        <span className="text-sm text-muted-foreground">
          Account: {connectStatus.account_id}
        </span>
      </div>
      <div className="flex flex-wrap gap-2">
        <Button variant="outline" asChild>
          <a href="https://dashboard.stripe.com" target="_blank" rel="noopener noreferrer">
            <ExternalLink className="mr-2 h-4 w-4" />
            Open Stripe Dashboard
          </a>
        </Button>
        <AlertDialog>
          <AlertDialogTrigger asChild>
            <Button variant="destructive" disabled={isActing}>
              <Unplug className="mr-2 h-4 w-4" />
              Disconnect
            </Button>
          </AlertDialogTrigger>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Disconnect Stripe Account?</AlertDialogTitle>
              <AlertDialogDescription>
                This will remove the connected Stripe account. Existing payments
                will not be affected, but no new payments can be processed.
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>Cancel</AlertDialogCancel>
              <AlertDialogAction onClick={handleDisconnect}>
                Disconnect
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Settings View — full settings page
// ---------------------------------------------------------------------------

function PlatformView({
  settings,
  connectStatus,
  refetch,
}: {
  settings: StripeSettings;
  connectStatus: ConnectStatus;
  refetch: () => void;
}) {
  const [isSaving, setIsSaving] = useState(false);
  const [testStatus, setTestStatus] = useState<
    "idle" | "loading" | "success" | "error"
  >("idle");
  const [testError, setTestError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    formState: { errors, isDirty },
    setValue,
    watch,
    reset,
  } = useForm<StripeForm>({
    resolver: zodResolver(stripeSchema),
    mode: "onBlur",
    defaultValues: {
      enabled: false,
      mode: "test",
      deployment_role: "fork",
      secret_key: "",
      publishable_key: "",
      webhook_secret: "",
      platform_account_id: "",
      platform_client_id: "pk_live_51T3IOFLxjkep9LMmNOGaCUjcW2wJ94BADNXlgNPLS6zqpqsG0TKeg5WxDlboeWbKobd3I4sSsMGL7znxFLrG7gMF00hY5PRSme",
      application_fee_percent: 1.0,
      currency: "usd",
    },
  });

  // Populate form when settings load
  useEffect(() => {
    if (settings) {
      reset({
        enabled: !!settings.enabled,
        mode: (settings.mode as "test" | "live") || "test",
        deployment_role:
          (settings.deployment_role as "platform" | "fork") || "fork",
        secret_key: settings.secret_key || "",
        publishable_key: settings.publishable_key || "",
        webhook_secret: settings.webhook_secret || "",
        platform_account_id: settings.platform_account_id || "",
        platform_client_id: settings.platform_client_id || "pk_live_51T3IOFLxjkep9LMmNOGaCUjcW2wJ94BADNXlgNPLS6zqpqsG0TKeg5WxDlboeWbKobd3I4sSsMGL7znxFLrG7gMF00hY5PRSme",
        application_fee_percent: settings.application_fee_percent ?? 1.0,
        currency: settings.currency || "usd",
      });
    }
  }, [settings, reset]);

  // Save settings
  const onSave = useCallback(
    async (data: StripeForm) => {
      setIsSaving(true);
      try {
        await api.put("/stripe/settings", data);
        resetStripe();
        toast.success("Stripe settings saved");
        refetch();
      } catch (err: unknown) {
        toast.error(getErrorMessage(err, "Failed to save Stripe settings"));
      } finally {
        setIsSaving(false);
      }
    },
    [refetch]
  );

  // Test connection
  const onTestConnection = async () => {
    setTestStatus("loading");
    setTestError(null);
    try {
      const res = await api.post("/stripe/settings/test");
      if (res.data?.account_id) {
        setTestStatus("success");
        toast.success("Connection successful");
      } else {
        setTestStatus("error");
        setTestError("Unexpected response from Stripe");
      }
    } catch (err: unknown) {
      setTestStatus("error");
      const msg = getErrorMessage(err, "Connection test failed");
      setTestError(msg);
      toast.error(msg);
    }
  };

  return (
    <div className="space-y-6">
      <Suspense fallback={null}>
        <OAuthCallbackHandler onComplete={refetch} />
      </Suspense>

      <div>
        <h1 className="text-2xl font-bold tracking-tight">
          Stripe
        </h1>
        <p className="text-muted-foreground mt-1">
          Configure Stripe payment processing and Connect onboarding.{" "}
          <HelpLink articleId="stripe-configuration" />
        </p>
      </div>

      <form onSubmit={handleSubmit(onSave)} className="space-y-6">
        {/* Section A — Stripe Connect */}
        <CollapsibleCard
          title="Stripe Connect"
          description="Connect your Stripe account and configure payment settings"
          icon={<Link2 className="h-4 w-4" />}
          status={{
            label: connectStatus.connected
              ? connectStatus.charges_enabled
                ? "Active"
                : "Pending"
              : "Not Connected",
            variant: connectStatus.connected
              ? connectStatus.charges_enabled
                ? "success"
                : "warning"
              : "default",
          }}
          defaultOpen
        >
          <div className="space-y-6">
            <ConnectSection connectStatus={connectStatus} refetch={refetch} />

            <hr className="border-border" />

            <div className="grid gap-4 md:grid-cols-2">
              <FormField
                id="currency"
                label="Currency"
                description="3-letter ISO currency code"
                error={errors.currency?.message}
              >
                <Input
                  id="currency"
                  placeholder="usd"
                  maxLength={3}
                  {...register("currency")}
                  className="min-h-[44px]"
                />
              </FormField>
              <FormField
                id="application_fee_percent"
                label="Application Fee %"
                description="Platform fee collected on each payment"
                error={errors.application_fee_percent?.message}
              >
                <Input
                  id="application_fee_percent"
                  type="number"
                  step="0.1"
                  min="0"
                  max="100"
                  {...register("application_fee_percent")}
                  className="min-h-[44px]"
                />
              </FormField>
            </div>
            <div className="grid gap-4 md:grid-cols-2">
              <FormField
                id="platform_account_id"
                label="Platform Account ID"
                error={errors.platform_account_id?.message}
              >
                <Input
                  id="platform_account_id"
                  placeholder="acct_..."
                  {...register("platform_account_id")}
                  className="min-h-[44px]"
                />
              </FormField>
              <FormField
                id="platform_client_id"
                label="Platform Client ID"
                description="Used for OAuth Connect flows"
                error={errors.platform_client_id?.message}
              >
                <Input
                  id="platform_client_id"
                  placeholder="ca_..."
                  {...register("platform_client_id")}
                  className="min-h-[44px]"
                />
              </FormField>
            </div>
            <div className="flex justify-end">
              <SaveButton isDirty={isDirty} isSaving={isSaving} />
            </div>
          </div>
        </CollapsibleCard>

        {/* Section B — API Keys */}
        <CollapsibleCard
          title="API Keys"
          description="Stripe API keys and webhook configuration"
          icon={<Settings2 className="h-4 w-4" />}
        >
          <div className="space-y-6">
            {/* Mode */}
            <FormField
              id="mode"
              label="Mode"
              description="Use test mode for development, live mode for production"
              error={errors.mode?.message}
            >
              <Select
                value={watch("mode")}
                onValueChange={(v) =>
                  setValue("mode", v as "test" | "live", { shouldDirty: true })
                }
              >
                <SelectTrigger className="min-h-[44px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="test">Test</SelectItem>
                  <SelectItem value="live">Live</SelectItem>
                </SelectContent>
              </Select>
            </FormField>

            <div className="grid gap-4 md:grid-cols-2">
              <FormField
                id="secret_key"
                label="Secret Key"
                error={errors.secret_key?.message}
              >
                <Input
                  id="secret_key"
                  type="password"
                  placeholder="sk_test_..."
                  {...register("secret_key")}
                  className="min-h-[44px]"
                />
              </FormField>
              <FormField
                id="publishable_key"
                label="Publishable Key"
                error={errors.publishable_key?.message}
              >
                <Input
                  id="publishable_key"
                  placeholder="pk_test_..."
                  {...register("publishable_key")}
                  className="min-h-[44px]"
                />
              </FormField>
            </div>

            <FormField
              id="webhook_secret"
              label="Webhook Secret"
              description="Used to verify incoming Stripe webhook events"
              error={errors.webhook_secret?.message}
            >
              <Input
                id="webhook_secret"
                type="password"
                placeholder="whsec_..."
                {...register("webhook_secret")}
                className="min-h-[44px]"
              />
            </FormField>

            {/* Test Connection result */}
            {testStatus === "error" && testError && (
              <div className="flex items-center gap-2 text-sm text-destructive">
                <XCircle className="h-4 w-4 shrink-0" />
                <span>{testError}</span>
              </div>
            )}
            {testStatus === "success" && (
              <div className="flex items-center gap-2 text-sm text-green-600 dark:text-green-500">
                <CheckCircle2 className="h-4 w-4 shrink-0" />
                <span>Connection successful</span>
              </div>
            )}

            {/* Actions */}
            <div className="flex flex-wrap items-center justify-end gap-2">
              <Button
                type="button"
                variant="outline"
                onClick={onTestConnection}
                disabled={testStatus === "loading"}
              >
                {testStatus === "loading" && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                Test Connection
              </Button>
              <SaveButton isDirty={isDirty} isSaving={isSaving} />
            </div>
          </div>
        </CollapsibleCard>
      </form>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Main Page
// ---------------------------------------------------------------------------

export default function StripeSettingsPage() {
  const { settings, connectStatus, isLoading, refetch } = useStripeSettings();

  if (isLoading) {
    return <SettingsPageSkeleton />;
  }

  return (
    <PlatformView
      settings={settings}
      connectStatus={connectStatus}
      refetch={refetch}
    />
  );
}
