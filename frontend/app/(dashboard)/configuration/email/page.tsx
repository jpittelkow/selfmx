"use client";

import { useState, useEffect, useCallback } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { getErrorMessage } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Separator } from "@/components/ui/separator";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { SaveButton } from "@/components/ui/save-button";
import { HelpLink } from "@/components/help/help-link";
import { Loader2, Mail } from "lucide-react";

const mailSchema = z.object({
  provider: z.enum(["smtp", "mailgun", "sendgrid", "ses", "postmark"]),
  host: z.string().optional(),
  port: z.number().optional(),
  encryption: z.enum(["tls", "ssl"]).optional(),
  username: z.string().optional(),
  password: z.string().optional(),
  from_address: z.string().email("Invalid email address"),
  from_name: z.string().min(1, "From name is required"),
  mailgun_domain: z.string().optional(),
  mailgun_secret: z.string().optional(),
  sendgrid_api_key: z.string().optional(),
  ses_key: z.string().optional(),
  ses_secret: z.string().optional(),
  ses_region: z.string().optional(),
  postmark_token: z.string().optional(),
});

type MailForm = z.infer<typeof mailSchema>;

export default function EmailSettingsPage() {
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [isTesting, setIsTesting] = useState(false);
  const [testEmail, setTestEmail] = useState("");

  const { register, handleSubmit, formState: { errors, isDirty }, setValue, reset, watch } = useForm<MailForm>({
    resolver: zodResolver(mailSchema),
    mode: "onBlur",
    defaultValues: {
      provider: "smtp",
      from_address: "",
      from_name: "",
    },
  });

  const provider = watch("provider");

  const fetchSettings = useCallback(async () => {
    setIsLoading(true);
    try {
      const response = await api.get("/mail-settings");
      const settings = response.data.settings || {};
      reset({
        provider: (settings.provider as MailForm["provider"]) || "smtp",
        from_address: settings.from_address || "",
        from_name: settings.from_name || "",
        host: settings.host || "",
        port: settings.port ? parseInt(settings.port) : undefined,
        encryption: settings.encryption as MailForm["encryption"] | undefined,
        username: settings.username || "",
        password: settings.password || "",
        mailgun_domain: settings.mailgun_domain || "",
        mailgun_secret: settings.mailgun_secret || "",
        sendgrid_api_key: settings.sendgrid_api_key || "",
        ses_key: settings.ses_key || "",
        ses_secret: settings.ses_secret || "",
        ses_region: settings.ses_region || "",
        postmark_token: settings.postmark_token || "",
      });
    } catch (error: unknown) {
      const msg = getErrorMessage(error, "Failed to load mail settings");
      setTimeout(() => toast.error(msg), 0);
    } finally {
      setIsLoading(false);
    }
  }, [reset]);

  useEffect(() => {
    fetchSettings();
  }, [fetchSettings]);

  const onSubmit = async (data: MailForm) => {
    setIsSaving(true);
    try {
      await api.put("/mail-settings", data);
      await fetchSettings();
      setTimeout(() => toast.success("Mail settings updated successfully"), 0);
    } catch (error: unknown) {
      const data = error && typeof error === "object" && "response" in error
        ? (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response?.data
        : null;
      let message = data?.message ?? getErrorMessage(error, "Failed to update mail settings");
      if (data?.errors && typeof data.errors === "object") {
        const first = Object.values(data.errors).flat();
        if (first.length) message = (first[0] as string) || message;
      }
      setTimeout(() => toast.error(message), 0);
    } finally {
      setIsSaving(false);
    }
  };

  const handleTestEmail = async () => {
    if (!testEmail) {
      toast.error("Please enter an email address");
      return;
    }

    setIsTesting(true);
    try {
      await api.post("/mail-settings/test", { to: testEmail });
      setTestEmail("");
      setTimeout(() => toast.success("Test email sent successfully"), 0);
    } catch (error: unknown) {
      const message = getErrorMessage(error, "Failed to send test email");
      setTimeout(() => toast.error(message), 0);
    } finally {
      setIsTesting(false);
    }
  };

  if (isLoading) {
    return <SettingsPageSkeleton />;
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Email Configuration</h1>
        <p className="text-muted-foreground mt-2">
          Configure email delivery settings.{" "}
          <HelpLink articleId="email-configuration" />
        </p>
      </div>

      <form onSubmit={handleSubmit(onSubmit)}>
        <Card>
          <CardHeader>
            <CardTitle>Email Provider</CardTitle>
            <CardDescription>
              Select your email service provider
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="provider">Provider</Label>
              <Select
                value={provider}
                onValueChange={(value) => setValue("provider", value as MailForm["provider"], { shouldDirty: true })}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select provider" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="smtp">SMTP</SelectItem>
                  <SelectItem value="mailgun">Mailgun</SelectItem>
                  <SelectItem value="sendgrid">SendGrid</SelectItem>
                  <SelectItem value="ses">Amazon SES</SelectItem>
                  <SelectItem value="postmark">Postmark</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {provider === "smtp" && (
              <>
                <Separator />
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="host">SMTP Host</Label>
                    <Input
                      id="host"
                      {...register("host")}
                      placeholder="smtp.example.com"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="port">Port</Label>
                    <Input
                      id="port"
                      type="number"
                      {...register("port", { valueAsNumber: true })}
                      placeholder="587"
                    />
                  </div>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="encryption">Encryption</Label>
                  <Select
                    value={(watch("encryption") as string) || undefined}
                    onValueChange={(value) => setValue("encryption", value as MailForm["encryption"], { shouldDirty: true })}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select encryption" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="tls">TLS</SelectItem>
                      <SelectItem value="ssl">SSL</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="username">Username</Label>
                    <Input
                      id="username"
                      {...register("username")}
                      placeholder="your-email@example.com"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="password">Password</Label>
                    <Input
                      id="password"
                      type="password"
                      {...register("password")}
                      placeholder="Your password"
                    />
                  </div>
                </div>
              </>
            )}

            {provider === "mailgun" && (
              <>
                <Separator />
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="mailgun_domain">Domain</Label>
                    <Input
                      id="mailgun_domain"
                      {...register("mailgun_domain")}
                      placeholder="mg.example.com"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="mailgun_secret">Secret Key</Label>
                    <Input
                      id="mailgun_secret"
                      type="password"
                      {...register("mailgun_secret")}
                      placeholder="Your Mailgun secret"
                    />
                  </div>
                </div>
              </>
            )}

            {provider === "sendgrid" && (
              <>
                <Separator />
                <div className="space-y-2">
                  <Label htmlFor="sendgrid_api_key">API Key</Label>
                  <Input
                    id="sendgrid_api_key"
                    type="password"
                    {...register("sendgrid_api_key")}
                    placeholder="Your SendGrid API key"
                  />
                </div>
              </>
            )}

            {provider === "ses" && (
              <>
                <Separator />
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="ses_key">Access Key ID</Label>
                    <Input
                      id="ses_key"
                      {...register("ses_key")}
                      placeholder="Your AWS access key"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="ses_secret">Secret Access Key</Label>
                    <Input
                      id="ses_secret"
                      type="password"
                      {...register("ses_secret")}
                      placeholder="Your AWS secret key"
                    />
                  </div>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="ses_region">Region</Label>
                  <Input
                    id="ses_region"
                    {...register("ses_region")}
                    placeholder="us-east-1"
                  />
                </div>
              </>
            )}

            {provider === "postmark" && (
              <>
                <Separator />
                <div className="space-y-2">
                  <Label htmlFor="postmark_token">Server API Token</Label>
                  <Input
                    id="postmark_token"
                    type="password"
                    {...register("postmark_token")}
                    placeholder="Your Postmark token"
                  />
                </div>
              </>
            )}

            <Separator />

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="from_address">From Email Address</Label>
                <Input
                  id="from_address"
                  type="email"
                  {...register("from_address")}
                  placeholder="noreply@example.com"
                />
                {errors.from_address && (
                  <p className="text-sm text-destructive">
                    {errors.from_address.message}
                  </p>
                )}
              </div>
              <div className="space-y-2">
                <Label htmlFor="from_name">From Name</Label>
                <Input
                  id="from_name"
                  {...register("from_name")}
                  placeholder="My Application"
                />
                {errors.from_name && (
                  <p className="text-sm text-destructive">
                    {errors.from_name.message}
                  </p>
                )}
              </div>
            </div>
          </CardContent>
          <CardFooter className="flex justify-end">
            <SaveButton isDirty={isDirty} isSaving={isSaving} />
          </CardFooter>
        </Card>
      </form>

      <Card>
        <CardHeader>
          <CardTitle>Test Email</CardTitle>
          <CardDescription>
            Send a test email to verify your configuration
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex gap-2">
            <Input
              type="email"
              placeholder="recipient@example.com"
              value={testEmail}
              onChange={(e) => setTestEmail(e.target.value)}
            />
            <Button
              type="button"
              onClick={handleTestEmail}
              disabled={isTesting || !testEmail}
            >
              {isTesting ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Mail className="mr-2 h-4 w-4" />
              )}
              Send Test
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
