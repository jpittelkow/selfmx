"use client";

import { useState, useEffect, useCallback } from "react";
import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Textarea } from "@/components/ui/textarea";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { SaveButton } from "@/components/ui/save-button";
import { EmailTemplateEditor } from "@/components/email-template-editor";
import {
  ArrowLeft,
  RotateCcw,
  Mail,
  Loader2,
} from "lucide-react";

const templateSchema = z.object({
  subject: z.string().min(1, "Subject is required").max(500),
  body_html: z.string().min(1, "Email body is required"),
  body_text: z.string().optional(),
  is_active: z.boolean(),
});

type TemplateForm = z.infer<typeof templateSchema>;

interface EmailTemplateFull {
  key: string;
  name: string;
  description: string;
  subject: string;
  body_html: string;
  body_text: string | null;
  variables: string[];
  is_system: boolean;
  is_active: boolean;
  updated_at: string;
}

const PREVIEW_DEBOUNCE_MS = 500;

export default function EmailTemplateEditorPage() {
  const params = useParams();
  const router = useRouter();
  const key = typeof params.key === "string" ? params.key : "";
  const { user } = useAuth();

  const [template, setTemplate] = useState<EmailTemplateFull | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [isResetting, setIsResetting] = useState(false);
  const [previewSubject, setPreviewSubject] = useState("");
  const [previewHtml, setPreviewHtml] = useState("");
  const [isPreviewLoading, setIsPreviewLoading] = useState(false);
  const [testDialogOpen, setTestDialogOpen] = useState(false);
  const [testEmail, setTestEmail] = useState("");
  const [isSendingTest, setIsSendingTest] = useState(false);

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
      subject: "",
      body_html: "<p></p>",
      body_text: "",
      is_active: true,
    },
  });

  const subject = watch("subject");
  const bodyHtml = watch("body_html");
  const bodyText = watch("body_text");

  const fetchTemplate = useCallback(async () => {
    if (!key) return;
    setIsLoading(true);
    try {
      const response = await api.get(`/email-templates/${encodeURIComponent(key)}`);
      const data = response.data?.data ?? response.data;
      if (data) {
        setTemplate(data);
        resetForm({
          subject: data.subject ?? "",
          body_html: data.body_html ?? "<p></p>",
          body_text: data.body_text ?? "",
          is_active: data.is_active ?? true,
        });
        setPreviewSubject(data.subject ?? "");
        setPreviewHtml("");
      }
    } catch (error: unknown) {
      const message =
        error && typeof error === "object" && "response" in error
          ? (error as { response?: { data?: { message?: string }; status?: number } }).response
              ?.data?.message
          : "Failed to load template";
      const status = (error as { response?: { status?: number } })?.response?.status;
      if (status === 404) {
        toast.error("Template not found");
        router.push("/configuration/email-templates");
        return;
      }
      toast.error(message || "Failed to load template");
    } finally {
      setIsLoading(false);
    }
  }, [key, resetForm, router]);

  useEffect(() => {
    fetchTemplate();
  }, [fetchTemplate]);

  useEffect(() => {
    if (!key || !template) return;
    const timer = setTimeout(() => {
      setIsPreviewLoading(true);
      api
        .post(`/email-templates/${encodeURIComponent(key)}/preview`, {
          subject,
          body_html: bodyHtml,
          body_text: bodyText || undefined,
        })
        .then((response) => {
          const data = response.data?.data ?? response.data;
          if (data) {
            setPreviewSubject(data.subject ?? "");
            setPreviewHtml(data.html ?? "");
          }
        })
        .catch(() => {
          setPreviewHtml("");
        })
        .finally(() => {
          setIsPreviewLoading(false);
        });
    }, PREVIEW_DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [key, template, subject, bodyHtml, bodyText]);

  useEffect(() => {
    if (user?.email) setTestEmail(user.email);
  }, [user?.email]);

  const onSubmit = async (data: TemplateForm) => {
    if (!key) return;
    setIsSaving(true);
    try {
      await api.put(`/email-templates/${encodeURIComponent(key)}`, {
        subject: data.subject,
        body_html: data.body_html,
        body_text: data.body_text || null,
        is_active: data.is_active,
      });
      toast.success("Template updated");
      await fetchTemplate();
    } catch (error: unknown) {
      const message =
        error && typeof error === "object" && "response" in error
          ? (error as { response?: { data?: { message?: string } } }).response?.data?.message
          : "Failed to update template";
      toast.error(message || "Failed to update template");
    } finally {
      setIsSaving(false);
    }
  };

  const handleReset = async () => {
    if (!key) return;
    setIsResetting(true);
    try {
      await api.post(`/email-templates/${encodeURIComponent(key)}/reset`);
      toast.success("Template reset to default");
      await fetchTemplate();
    } catch (error: unknown) {
      const res = error && typeof error === "object" && "response" in error
        ? (error as { response?: { data?: { message?: string }; status?: number } }).response
        : undefined;
      const message = res?.data?.message ?? "Failed to reset template";
      if (res?.status === 403) toast.error("Only system templates can be reset");
      else toast.error(message);
    } finally {
      setIsResetting(false);
    }
  };

  const handleSendTest = async () => {
    if (!key) return;
    const email = testEmail.trim();
    if (!email) {
      toast.error("Enter an email address");
      return;
    }
    setIsSendingTest(true);
    try {
      await api.post(`/email-templates/${encodeURIComponent(key)}/test`, { to: email });
      toast.success("Test email sent");
      setTestDialogOpen(false);
    } catch (error: unknown) {
      const res = error && typeof error === "object" && "response" in error
        ? (error as { response?: { data?: { message?: string }; status?: number } }).response
        : undefined;
      const message = res?.data?.message ?? "Failed to send test email";
      if (res?.status === 503) toast.error("Email is not configured. Configure mail settings first.");
      else toast.error(message);
    } finally {
      setIsSendingTest(false);
    }
  };

  if (isLoading || !template) {
    return <SettingsPageSkeleton />;
  }

  const variables: string[] = Array.isArray(template.variables) ? template.variables : [];

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" asChild>
          <Link href="/configuration/email-templates" aria-label="Back to templates">
            <ArrowLeft className="h-4 w-4" />
          </Link>
        </Button>
        <div>
          <h1 className="text-2xl font-bold tracking-tight">{template.name}</h1>
          <p className="text-muted-foreground mt-1">{template.description || "—"}</p>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Content</CardTitle>
              <CardDescription>
                Edit the subject and body. Use variables like {`{{user.name}}`} for dynamic content.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="subject">Subject</Label>
                <Input
                  id="subject"
                  {...register("subject")}
                  placeholder="Email subject"
                  maxLength={500}
                />
                {errors.subject && (
                  <p className="text-sm text-destructive">{errors.subject.message}</p>
                )}
              </div>

              <div className="space-y-2">
                <Label>Body (HTML)</Label>
                <EmailTemplateEditor
                  key={key}
                  content={bodyHtml}
                  onChange={(html) => setValue("body_html", html, { shouldDirty: true })}
                  variables={variables}
                  placeholder="Write email content…"
                />
                {errors.body_html && (
                  <p className="text-sm text-destructive">{errors.body_html.message}</p>
                )}
              </div>

              <Tabs defaultValue="html">
                <TabsList>
                  <TabsTrigger value="html">HTML</TabsTrigger>
                  <TabsTrigger value="text">Plain text</TabsTrigger>
                </TabsList>
                <TabsContent value="text" className="mt-2">
                  <Textarea
                    {...register("body_text")}
                    placeholder="Plain text fallback (optional)"
                    rows={6}
                    className="font-mono text-sm"
                  />
                </TabsContent>
              </Tabs>

              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label>Active</Label>
                  <p className="text-sm text-muted-foreground">
                    Inactive templates are not used when sending emails
                  </p>
                </div>
                <Switch
                  checked={watch("is_active")}
                  onCheckedChange={(checked) => setValue("is_active", checked, { shouldDirty: true })}
                />
              </div>
            </CardContent>
            <CardFooter className="flex flex-wrap justify-end gap-2">
              <SaveButton isDirty={isDirty} isSaving={isSaving} />
              <Button
                type="button"
                variant="outline"
                onClick={() => setTestDialogOpen(true)}
                disabled={isSaving}
              >
                <Mail className="mr-2 h-4 w-4" />
                Send test email
              </Button>
              {template.is_system && (
                <Button
                  type="button"
                  variant="outline"
                  onClick={handleReset}
                  disabled={isResetting || isSaving}
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
              Rendered with sample variables. Updates as you type.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {isPreviewLoading && (
              <div className="flex items-center gap-2 text-sm text-muted-foreground mb-2">
                <Loader2 className="h-4 w-4 animate-spin" />
                Updating preview…
              </div>
            )}
            <div className="space-y-2">
              <p className="text-sm font-medium text-muted-foreground">Subject</p>
              <p className="text-sm font-medium">{previewSubject || "—"}</p>
            </div>
            <div className="mt-4 rounded-md border bg-white overflow-hidden">
              <iframe
                title="Email preview"
                srcDoc={`<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:1rem;font-family:system-ui,sans-serif;font-size:14px;background:#ffffff;color:#000000;">${previewHtml || "<p style=\"color:#6b7280;\">No content</p>"}</body></html>`}
                className="w-full min-h-[300px] border-0 bg-white"
                sandbox="allow-same-origin"
              />
            </div>
          </CardContent>
        </Card>
      </div>

      <Dialog open={testDialogOpen} onOpenChange={setTestDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Send test email</DialogTitle>
            <DialogDescription>
              A test email will be sent using the current template content and sample variables.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-2 py-4">
            <Label htmlFor="test-email">Email address</Label>
            <Input
              id="test-email"
              type="email"
              value={testEmail}
              onChange={(e) => setTestEmail(e.target.value)}
              placeholder="you@example.com"
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setTestDialogOpen(false)} disabled={isSendingTest}>
              Cancel
            </Button>
            <Button onClick={handleSendTest} disabled={isSendingTest}>
              {isSendingTest ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Sending…
                </>
              ) : (
                "Send test email"
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
