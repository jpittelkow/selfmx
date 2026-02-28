"use client";

import { useState, useEffect, useCallback, useRef } from "react";
import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api } from "@/lib/api";
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
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Textarea } from "@/components/ui/textarea";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { SaveButton } from "@/components/ui/save-button";
import { ArrowLeft, RotateCcw, Loader2, Copy, Send } from "lucide-react";
import { CollapsibleCard } from "@/components/ui/collapsible-card";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { EmailTemplateEditor } from "@/components/email-template-editor";
import DOMPurify from "dompurify";
import { CHANNEL_GROUP_LABELS } from "@/lib/notification-types";

const templateSchema = z.object({
  title: z.string().min(1, "Title is required").max(500),
  body: z.string().min(1, "Body is required").refine(
    (v) => v.replace(/<[^>]*>/g, "").trim().length > 0,
    { message: "Body cannot be empty" }
  ),
  is_active: z.boolean(),
});

type TemplateForm = z.infer<typeof templateSchema>;

interface SiblingTemplate {
  id: number;
  channel_group: string;
}

interface NotificationTemplateFull {
  id: number;
  type: string;
  channel_group: string;
  title: string;
  body: string;
  variables: string[];
  variable_descriptions?: Record<string, string>;
  is_system: boolean;
  is_active: boolean;
  updated_at: string;
  siblings?: SiblingTemplate[];
}

const channelGroupLabel = CHANNEL_GROUP_LABELS;

const channelGroupOrder = ["push", "inapp", "chat", "email"];

const PREVIEW_DEBOUNCE_MS = 500;

export default function NotificationTemplateEditorPage() {
  const params = useParams();
  const router = useRouter();
  const id = typeof params.id === "string" ? params.id : "";

  const [template, setTemplate] = useState<NotificationTemplateFull | null>(
    null
  );
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [isResetting, setIsResetting] = useState(false);
  const [isTesting, setIsTesting] = useState(false);
  const [previewTitle, setPreviewTitle] = useState("");
  const [previewBody, setPreviewBody] = useState("");
  const [isPreviewLoading, setIsPreviewLoading] = useState(false);
  const isInitialLoad = useRef(true);
  const [pendingTabId, setPendingTabId] = useState<number | null>(null);

  const {
    register,
    handleSubmit,
    formState: { errors, isDirty },
    setValue,
    watch,
    reset: resetForm,
  } = useForm<TemplateForm>({
    resolver: zodResolver(templateSchema),
    defaultValues: {
      title: "",
      body: "",
      is_active: true,
    },
  });

  const titleValue = watch("title");
  const bodyValue = watch("body");

  const isEmail = template?.channel_group === "email";

  const fetchTemplate = useCallback(async () => {
    if (!id) return;
    setIsLoading(true);
    try {
      const response = await api.get(`/notification-templates/${id}`);
      const data = response.data?.data ?? response.data;
      if (data) {
        setTemplate(data);
        resetForm({
          title: data.title ?? "",
          body: data.body ?? "",
          is_active: data.is_active ?? true,
        });
        setPreviewTitle(data.title ?? "");
        setPreviewBody(data.body ?? "");
      }
    } catch (error: unknown) {
      const message =
        error && typeof error === "object" && "response" in error
          ? (error as {
              response?: { data?: { message?: string }; status?: number };
            }).response?.data?.message
          : "Failed to load template";
      const status = (error as { response?: { status?: number } })?.response
        ?.status;
      if (status === 404) {
        toast.error("Template not found");
        router.push("/configuration/notification-templates");
        return;
      }
      toast.error(message || "Failed to load template");
    } finally {
      setIsLoading(false);
    }
  }, [id, resetForm, router]);

  useEffect(() => {
    isInitialLoad.current = true;
    fetchTemplate();
  }, [fetchTemplate]);

  useEffect(() => {
    if (!id || !template) return;
    if (isInitialLoad.current) {
      isInitialLoad.current = false;
      return;
    }
    const timer = setTimeout(() => {
      setIsPreviewLoading(true);
      api
        .post(`/notification-templates/${id}/preview`, {
          title: titleValue,
          body: bodyValue,
        })
        .then((response) => {
          const data = response.data ?? response;
          if (data) {
            setPreviewTitle(data.title ?? "");
            setPreviewBody(data.body ?? "");
          }
        })
        .catch(() => {
          // Keep last good preview on transient errors
        })
        .finally(() => {
          setIsPreviewLoading(false);
        });
    }, PREVIEW_DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [id, template, titleValue, bodyValue]);

  const onSubmit = async (data: TemplateForm) => {
    if (!id) return;
    setIsSaving(true);
    try {
      await api.put(`/notification-templates/${id}`, {
        title: data.title,
        body: data.body,
        is_active: data.is_active,
      });
      toast.success("Template updated");
      await fetchTemplate();
    } catch (error: unknown) {
      const message =
        error && typeof error === "object" && "response" in error
          ? (error as { response?: { data?: { message?: string } } }).response
              ?.data?.message
          : "Failed to update template";
      toast.error(message || "Failed to update template");
    } finally {
      setIsSaving(false);
    }
  };

  const handleReset = async () => {
    if (!id) return;
    setIsResetting(true);
    try {
      await api.post(`/notification-templates/${id}/reset`);
      toast.success("Template reset to default");
      await fetchTemplate();
    } catch (error: unknown) {
      const res =
        error && typeof error === "object" && "response" in error
          ? (error as {
              response?: { data?: { message?: string }; status?: number };
            }).response
          : undefined;
      const message = res?.data?.message ?? "Failed to reset template";
      if (res?.status === 403) {
        toast.error("Only system templates can be reset");
      } else {
        toast.error(message);
      }
    } finally {
      setIsResetting(false);
    }
  };

  const channelGroupToChannel: Record<string, string> = {
    inapp: "database",
    push: "webpush",
    chat: "slack",
    email: "email",
  };

  const handleSendTest = async () => {
    if (!template) return;
    const channel = channelGroupToChannel[template.channel_group] ?? "database";
    setIsTesting(true);
    try {
      await api.post(`/notification-settings/test/${channel}`);
      toast.success(`Test notification sent via ${channel}`);
    } catch (error: unknown) {
      const message =
        error && typeof error === "object" && "response" in error
          ? (error as { response?: { data?: { message?: string } } }).response
              ?.data?.message
          : "Failed to send test notification";
      toast.error(message || "Failed to send test notification");
    } finally {
      setIsTesting(false);
    }
  };

  if (isLoading || !template) {
    return <SettingsPageSkeleton />;
  }

  const variables: string[] = Array.isArray(template.variables)
    ? template.variables
    : [];
  const variableDescriptions: Record<string, string> =
    template.variable_descriptions ?? {};
  const channelLabel =
    channelGroupLabel[template.channel_group] ?? template.channel_group;

  // Build tab list from current template + siblings
  const allTabs = [
    { id: template.id, channel_group: template.channel_group },
    ...(template.siblings ?? []),
  ].sort(
    (a, b) =>
      channelGroupOrder.indexOf(a.channel_group) -
      channelGroupOrder.indexOf(b.channel_group)
  );

  const copyPlaceholder = (placeholder: string) => {
    navigator.clipboard
      .writeText(placeholder)
      .then(() => {
        toast.success("Copied to clipboard");
      })
      .catch(() => {
        toast.error("Failed to copy to clipboard");
      });
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" asChild>
          <Link
            href="/configuration/notification-templates"
            aria-label="Back to templates"
          >
            <ArrowLeft className="h-4 w-4" />
          </Link>
        </Button>
        <div>
          <h1 className="text-2xl font-bold tracking-tight">
            {template.type} — {channelLabel}
          </h1>
          <p className="text-muted-foreground mt-1">
            Edit {isEmail ? "subject" : "title"} and body. Use variables like{" "}
            {`{{user.name}}`}, {`{{app_name}}`} for dynamic content.
          </p>
        </div>
      </div>

      {/* Channel group tabs */}
      {allTabs.length > 1 && (
        <Tabs
          value={template.channel_group}
          onValueChange={(value) => {
            const target = allTabs.find((t) => t.channel_group === value);
            if (target && target.id !== template.id) {
              if (isDirty) {
                setPendingTabId(target.id);
                return;
              }
              router.push(
                `/configuration/notification-templates/${target.id}`
              );
            }
          }}
        >
          <TabsList>
            {allTabs.map((tab) => (
              <TabsTrigger key={tab.channel_group} value={tab.channel_group}>
                {channelGroupLabel[tab.channel_group] ?? tab.channel_group}
              </TabsTrigger>
            ))}
          </TabsList>
        </Tabs>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Content</CardTitle>
              <CardDescription>
                {isEmail ? "Subject" : "Title"} and body support placeholders
                such as{" "}
                {variables
                  .slice(0, 3)
                  .map((v) => `{{${v}}}`)
                  .join(", ")}
                {variables.length > 3 ? "…" : ""}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="title">
                  {isEmail ? "Subject" : "Title"}
                </Label>
                <Input
                  id="title"
                  {...register("title")}
                  placeholder="e.g. {{app_name}}: Backup complete"
                  className={errors.title ? "border-destructive" : ""}
                />
                {errors.title && (
                  <p className="text-sm text-destructive">
                    {errors.title.message}
                  </p>
                )}
              </div>
              <div className="space-y-2">
                <Label htmlFor={isEmail ? undefined : "body"}>Body</Label>
                {isEmail ? (
                  <EmailTemplateEditor
                    content={bodyValue}
                    onChange={(html) =>
                      setValue("body", html, { shouldDirty: true })
                    }
                    variables={variables}
                    placeholder="Write email notification content…"
                  />
                ) : (
                  <Textarea
                    id="body"
                    {...register("body")}
                    placeholder={
                      'e.g. Backup "{{backup_name}}" finished successfully.'
                    }
                    rows={6}
                    className={errors.body ? "border-destructive" : ""}
                  />
                )}
                {errors.body && (
                  <p className="text-sm text-destructive">
                    {errors.body.message}
                  </p>
                )}
              </div>
              <div className="flex items-center justify-between rounded-lg border p-4">
                <div className="space-y-0.5">
                  <Label htmlFor="is_active">Active</Label>
                  <p className="text-sm text-muted-foreground">
                    Inactive templates are not used when sending notifications
                  </p>
                </div>
                <Switch
                  id="is_active"
                  checked={watch("is_active")}
                  onCheckedChange={(checked) =>
                    setValue("is_active", checked, { shouldDirty: true })
                  }
                />
              </div>
            </CardContent>
            <CardFooter className="flex flex-wrap justify-end gap-2">
              <Button
                type="button"
                variant="outline"
                onClick={handleSendTest}
                disabled={isTesting}
              >
                {isTesting ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <Send className="mr-2 h-4 w-4" />
                )}
                Send test
              </Button>
              <SaveButton isDirty={isDirty} isSaving={isSaving} />
              {template.is_system && (
                <Button
                  type="button"
                  variant="outline"
                  onClick={handleReset}
                  disabled={isResetting}
                >
                  {isResetting ? (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  ) : (
                    <RotateCcw className="mr-2 h-4 w-4" />
                  )}
                  Reset to default
                </Button>
              )}
            </CardFooter>
          </Card>
        </form>

        <Card>
          <CardHeader>
            <CardTitle>Preview</CardTitle>
            <CardDescription>
              Rendered with sample variables (updates as you type)
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {isPreviewLoading ? (
              <div className="flex items-center gap-2 text-muted-foreground">
                <Loader2 className="h-4 w-4 animate-spin" />
                <span>Updating preview…</span>
              </div>
            ) : (
              <>
                <div className="space-y-1">
                  <p className="text-sm font-medium text-muted-foreground">
                    {isEmail ? "Subject" : "Title"}
                  </p>
                  <p className="rounded-md border bg-muted/50 p-3 text-sm">
                    {previewTitle || "—"}
                  </p>
                </div>
                <div className="space-y-1">
                  <p className="text-sm font-medium text-muted-foreground">
                    Body
                  </p>
                  {isEmail ? (
                    <div
                      className="rounded-md border bg-muted/50 p-3 text-sm prose prose-sm max-w-none dark:prose-invert"
                      dangerouslySetInnerHTML={{
                        __html: DOMPurify.sanitize(previewBody || "<p>—</p>"),
                      }}
                    />
                  ) : (
                    <p className="whitespace-pre-wrap rounded-md border bg-muted/50 p-3 text-sm">
                      {previewBody || "—"}
                    </p>
                  )}
                </div>
              </>
            )}
          </CardContent>
        </Card>
      </div>

      <CollapsibleCard
        title="Available Variables"
        description="Placeholders you can use in this template. Click Copy to paste into title or body."
        defaultOpen={false}
      >
        {variables.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            No variables defined for this template type.
          </p>
        ) : (
          <div className="space-y-2">
            {variables.map((v) => {
              const placeholder = `{{${v}}}`;
              const description =
                variableDescriptions[v] ?? "Available when sending.";
              return (
                <div
                  key={v}
                  className="flex items-center justify-between gap-4 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                >
                  <code className="shrink-0 font-mono text-xs">
                    {placeholder}
                  </code>
                  <span className="min-w-0 flex-1 text-muted-foreground">
                    {description}
                  </span>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8 shrink-0"
                    onClick={() => copyPlaceholder(placeholder)}
                    aria-label={`Copy ${placeholder}`}
                  >
                    <Copy className="h-4 w-4" aria-hidden />
                  </Button>
                </div>
              );
            })}
          </div>
        )}
      </CollapsibleCard>

      <AlertDialog open={pendingTabId !== null} onOpenChange={(open) => { if (!open) setPendingTabId(null); }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Unsaved changes</AlertDialogTitle>
            <AlertDialogDescription>
              You have unsaved changes. Discard and switch tabs?
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={() => {
              if (pendingTabId !== null) {
                router.push(`/configuration/notification-templates/${pendingTabId}`);
              }
              setPendingTabId(null);
            }}>
              Discard
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
