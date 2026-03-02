"use client";

import { useState, useEffect, useCallback } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { errorLogger } from "@/lib/error-logger";
import { getErrorMessage } from "@/lib/utils";
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
import { FormField } from "@/components/ui/form-field";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { SaveButton } from "@/components/ui/save-button";
import { PasswordInput } from "@/components/ui/password-input";
import { Badge } from "@/components/ui/badge";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Loader2, ExternalLink, AlertTriangle } from "lucide-react";
import { HelpLink } from "@/components/help/help-link";
import {
  getNotificationTypeLabel,
  getNotificationCategory,
  getCategoryLabel,
  type NotificationCategory,
} from "@/lib/notification-types";

const novuSchema = z.object({
  enabled: z.boolean().default(false),
  api_key: z.string().optional(),
  app_identifier: z.string().optional(),
  api_url: z
    .string()
    .refine((val) => !val || val === "" || (() => { try { new URL(val); return true; } catch { return false; } })(), {
      message: "Must be a valid URL",
    })
    .optional(),
  socket_url: z
    .string()
    .refine((val) => !val || val === "" || (() => { try { new URL(val); return true; } catch { return false; } })(), {
      message: "Must be a valid URL",
    })
    .optional(),
});

type NovuForm = z.infer<typeof novuSchema>;

const defaultValues: NovuForm = {
  enabled: false,
  api_key: "",
  app_identifier: "",
  api_url: "https://api.novu.co",
  socket_url: "https://ws.novu.co",
};

interface TestWarning {
  type: "unmapped" | "missing";
  notification_type: string;
  workflow_id?: string;
  message: string;
}

interface TestResult {
  success: boolean;
  workflowsFound?: string[];
  warnings?: TestWarning[];
}

export default function NovuConfigurationPage() {
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [isTesting, setIsTesting] = useState(false);
  const [testResult, setTestResult] = useState<TestResult | null>(null);

  // Workflow map state
  const [workflowMap, setWorkflowMap] = useState<Record<string, string>>({});
  const [notificationTypes, setNotificationTypes] = useState<string[]>([]);
  const [isSavingMap, setIsSavingMap] = useState(false);
  const [mapDirty, setMapDirty] = useState(false);

  const {
    register,
    handleSubmit,
    formState: { errors, isDirty },
    setValue,
    watch,
    reset,
  } = useForm<NovuForm>({
    resolver: zodResolver(novuSchema),
    mode: "onBlur",
    defaultValues,
  });

  const isEnabled = watch("enabled");

  const fetchSettings = useCallback(async () => {
    try {
      const res = await api.get<{ settings: Record<string, unknown> }>("/novu-settings");
      const s = res.data?.settings ?? {};
      const settings = typeof s === "object" && s !== null ? s as Record<string, unknown> : {};
      reset({
        enabled: Boolean(settings.enabled),
        api_key: (settings.api_key as string) ?? "",
        app_identifier: (settings.app_identifier as string) ?? "",
        api_url: (settings.api_url as string) ?? defaultValues.api_url,
        socket_url: (settings.socket_url as string) ?? defaultValues.socket_url,
      });
      setTestResult(null);
    } catch (err) {
      toast.error("Failed to load Novu settings");
      if (err instanceof Error) {
        errorLogger.report(err, { context: "NovuConfigurationPage.fetchSettings" });
      }
    } finally {
      setIsLoading(false);
    }
  }, [reset]);

  const fetchWorkflowMap = useCallback(async () => {
    try {
      const res = await api.get<{
        workflow_map: Record<string, string>;
        notification_types: string[];
      }>("/novu-settings/workflow-map");
      setWorkflowMap(res.data?.workflow_map ?? {});
      setNotificationTypes(res.data?.notification_types ?? []);
      setMapDirty(false);
    } catch {
      // Silently fail — map section just won't show data
    }
  }, []);

  useEffect(() => {
    fetchSettings();
    fetchWorkflowMap();
  }, [fetchSettings, fetchWorkflowMap]);

  const onSubmit = async (data: NovuForm) => {
    setIsSaving(true);
    try {
      await api.put("/novu-settings", {
        enabled: data.enabled,
        api_key: data.api_key || null,
        app_identifier: data.app_identifier || null,
        api_url: data.api_url || null,
        socket_url: data.socket_url || null,
      });
      toast.success("Novu settings saved");
      await fetchSettings();
    } catch (err: unknown) {
      toast.error(getErrorMessage(err, "Failed to save Novu settings"));
      if (err instanceof Error) {
        errorLogger.report(err, { context: "NovuConfigurationPage.onSubmit" });
      }
    } finally {
      setIsSaving(false);
    }
  };

  const onTest = async () => {
    setIsTesting(true);
    setTestResult(null);
    try {
      const res = await api.post<{
        message: string;
        workflows_found: string[];
        warnings: TestWarning[];
      }>("/novu-settings/test");
      setTestResult({
        success: true,
        workflowsFound: res.data?.workflows_found ?? [],
        warnings: res.data?.warnings ?? [],
      });
      toast.success("Connection successful");
    } catch (err: unknown) {
      setTestResult({ success: false });
      const message =
        err && typeof err === "object" && "response" in err
          ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
          : undefined;
      toast.error(message || "Connection failed. Check API key and URL.");
    } finally {
      setIsTesting(false);
    }
  };

  const onSaveWorkflowMap = async () => {
    setIsSavingMap(true);
    try {
      await api.put("/novu-settings/workflow-map", { workflow_map: workflowMap });
      toast.success("Workflow map saved");
      setMapDirty(false);
      await fetchWorkflowMap();
    } catch (err: unknown) {
      toast.error(getErrorMessage(err, "Failed to save workflow map"));
    } finally {
      setIsSavingMap(false);
    }
  };

  const updateWorkflowId = (type: string, value: string) => {
    setWorkflowMap((prev) => ({ ...prev, [type]: value }));
    setMapDirty(true);
  };

  // Group notification types by category
  const groupedTypes = notificationTypes.reduce<
    { category: NotificationCategory; label: string; types: string[] }[]
  >((acc, type) => {
    const cat = getNotificationCategory(type);
    const existing = acc.find((g) => g.category === cat);
    if (existing) {
      existing.types.push(type);
    } else {
      acc.push({ category: cat, label: getCategoryLabel(cat), types: [type] });
    }
    return acc;
  }, []);

  if (isLoading) {
    return <SettingsPageSkeleton />;
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Novu</h1>
        <p className="text-muted-foreground mt-1">
          Optional notification infrastructure. When enabled, notifications are sent via Novu (Cloud or self-hosted) and the in-app notification center uses Novu&apos;s inbox.{" "}
          <HelpLink articleId="novu-configuration" />
        </p>
      </div>

      <form onSubmit={handleSubmit(onSubmit)}>
        <Card>
          <CardHeader>
            <CardTitle>Configuration</CardTitle>
            <CardDescription>
              Use Novu Cloud (default URLs) or your self-hosted Novu instance. Leave disabled to use the built-in notification system.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            <div className="flex items-center justify-between">
              <div>
                <Label>Enable Novu</Label>
                <p className="text-sm text-muted-foreground">Route notifications through Novu when configured.</p>
              </div>
              <Switch
                checked={watch("enabled")}
                onCheckedChange={(checked) => setValue("enabled", checked, { shouldDirty: true })}
              />
            </div>

            <FormField
              id="api_key"
              label="API Key"
              description="From your Novu dashboard (Settings → API Keys)."
              error={errors.api_key?.message}
            >
              <PasswordInput
                {...register("api_key")}
                placeholder="Leave blank to keep existing"
                autoComplete="off"
              />
            </FormField>

            <FormField
              id="app_identifier"
              label="Application Identifier"
              description="Used by the frontend notification center. Find it in Novu dashboard → Application."
              error={errors.app_identifier?.message}
            >
              <Input {...register("app_identifier")} placeholder="e.g. my-app" />
            </FormField>

            <FormField
              id="api_url"
              label="API URL"
              description="Default: Novu Cloud. Change for self-hosted."
              error={errors.api_url?.message}
            >
              <Input {...register("api_url")} placeholder="https://api.novu.co" />
            </FormField>

            <FormField
              id="socket_url"
              label="WebSocket URL"
              description="For real-time notifications. Default: Novu Cloud."
              error={errors.socket_url?.message}
            >
              <Input {...register("socket_url")} placeholder="https://ws.novu.co" />
            </FormField>

            <div className="flex flex-wrap items-center gap-3">
              <Button
                type="button"
                variant="outline"
                onClick={onTest}
                disabled={isTesting || !watch("enabled")}
              >
                {isTesting ? (
                  <>
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    Testing…
                  </>
                ) : (
                  "Test connection"
                )}
              </Button>
              {testResult?.success && (!testResult.warnings || testResult.warnings.length === 0) && (
                <Badge variant="success">Connected</Badge>
              )}
              {testResult?.success && testResult.warnings && testResult.warnings.length > 0 && (
                <Badge variant="warning">Connected with warnings</Badge>
              )}
              {testResult !== null && !testResult.success && (
                <Badge variant="destructive">Connection failed</Badge>
              )}
            </div>

            {testResult?.success && testResult.warnings && testResult.warnings.length > 0 && (
              <Alert>
                <AlertTriangle className="h-4 w-4" />
                <AlertDescription>
                  <p className="font-medium mb-2">
                    {testResult.warnings.length} workflow warning{testResult.warnings.length > 1 ? "s" : ""}:
                  </p>
                  <ul className="list-disc pl-4 space-y-1 text-sm">
                    {testResult.warnings.map((w, i) => (
                      <li key={i}>
                        {w.type === "unmapped" ? (
                          <span>
                            <Badge variant="outline" className="mr-1 text-xs">Unmapped</Badge>
                            {getNotificationTypeLabel(w.notification_type)} has no workflow mapped
                          </span>
                        ) : (
                          <span>
                            <Badge variant="destructive" className="mr-1 text-xs">Missing</Badge>
                            Workflow <code className="text-xs">{w.workflow_id}</code> for {getNotificationTypeLabel(w.notification_type)} not found in Novu
                          </span>
                        )}
                      </li>
                    ))}
                  </ul>
                </AlertDescription>
              </Alert>
            )}
          </CardContent>
          <CardFooter className="flex justify-end">
            <SaveButton isDirty={isDirty} isSaving={isSaving} />
          </CardFooter>
        </Card>
      </form>

      {isEnabled && notificationTypes.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Workflow Mapping</CardTitle>
            <CardDescription>
              Map each notification type to a Novu workflow identifier. Create matching workflows in your{" "}
              <a
                href="https://docs.novu.co"
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1 text-primary hover:underline"
              >
                Novu dashboard <ExternalLink className="h-3 w-3" />
              </a>
              . Unmapped types will use local channels as fallback.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-6">
              {groupedTypes.map((group) => (
                <div key={group.category}>
                  <h4 className="text-sm font-medium text-muted-foreground mb-3">{group.label}</h4>
                  <div className="space-y-2">
                    {group.types.map((type) => (
                      <div key={type} className="flex items-center gap-3">
                        <div className="w-44 shrink-0">
                          <span className="text-sm">{getNotificationTypeLabel(type)}</span>
                        </div>
                        <Input
                          value={workflowMap[type] ?? ""}
                          onChange={(e) => updateWorkflowId(type, e.target.value)}
                          placeholder="e.g. backup-completed"
                          className="max-w-xs font-mono text-sm"
                        />
                        {workflowMap[type] ? (
                          <Badge variant="success" className="text-xs shrink-0">Mapped</Badge>
                        ) : (
                          <Badge variant="outline" className="text-xs shrink-0">Unmapped</Badge>
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </CardContent>
          <CardFooter className="flex justify-end">
            <SaveButton isDirty={mapDirty} isSaving={isSavingMap} onClick={onSaveWorkflowMap} />
          </CardFooter>
        </Card>
      )}
    </div>
  );
}
