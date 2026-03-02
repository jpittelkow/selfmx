"use client";

import { useState, useEffect, useRef, useCallback } from "react";
import { toast } from "sonner";
import { Copy } from "lucide-react";
import { api } from "@/lib/api";
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { SaveButton } from "@/components/ui/save-button";
import { PasswordInput } from "@/components/ui/password-input";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";

interface ProviderSettings {
  email_hosting: {
    default_provider: string;
    spam_threshold: string;
    max_attachment_size: string;
  };
  mailgun: {
    api_key: string;
    region: string;
    webhook_signing_key: string;
  };
  ses: {
    access_key_id: string;
    secret_access_key: string;
    region: string;
    configuration_set: string;
  };
  sendgrid: {
    api_key: string;
    webhook_verification_key: string;
  };
  postmark: {
    server_token: string;
  };
}

const defaultSettings: ProviderSettings = {
  email_hosting: {
    default_provider: "mailgun",
    spam_threshold: "5.0",
    max_attachment_size: "25",
  },
  mailgun: {
    api_key: "",
    region: "us",
    webhook_signing_key: "",
  },
  ses: {
    access_key_id: "",
    secret_access_key: "",
    region: "us-east-1",
    configuration_set: "",
  },
  sendgrid: {
    api_key: "",
    webhook_verification_key: "",
  },
  postmark: {
    server_token: "",
  },
};

const providerLabels: Record<string, string> = {
  mailgun: "Mailgun",
  ses: "AWS SES",
  sendgrid: "SendGrid",
  postmark: "Postmark",
};

export default function EmailProviderPage() {
  const [settings, setSettings] = useState<ProviderSettings>(defaultSettings);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [appUrl, setAppUrl] = useState("");
  const [activeTab, setActiveTab] = useState("mailgun");
  const initialSettings = useRef<ProviderSettings>(defaultSettings);

  const isDirty = JSON.stringify(settings) !== JSON.stringify(initialSettings.current);

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

  const copyUrl = (url: string) => {
    void navigator.clipboard.writeText(url).then(() => toast.success("Webhook URL copied to clipboard"));
  };

  useEffect(() => {
    fetchAppUrl();
    api
      .get<ProviderSettings>("/email-provider-settings")
      .then((res) => {
        const loaded = {
          email_hosting: { ...defaultSettings.email_hosting, ...res.data.email_hosting },
          mailgun: { ...defaultSettings.mailgun, ...res.data.mailgun },
          ses: { ...defaultSettings.ses, ...res.data.ses },
          sendgrid: { ...defaultSettings.sendgrid, ...res.data.sendgrid },
          postmark: { ...defaultSettings.postmark, ...res.data.postmark },
        };
        setSettings(loaded);
        initialSettings.current = loaded;
        setActiveTab(loaded.email_hosting.default_provider || "mailgun");
      })
      .catch(() => toast.error("Failed to load email provider settings"))
      .finally(() => setIsLoading(false));
  }, [fetchAppUrl]);

  const handleSave = async () => {
    setIsSaving(true);
    try {
      await api.put("/email-provider-settings", settings);
      initialSettings.current = { ...settings };
      toast.success("Email provider settings saved.");
    } catch {
      toast.error("Failed to save email provider settings");
    } finally {
      setIsSaving(false);
    }
  };

  if (isLoading) return <SettingsPageSkeleton />;

  const WebhookUrls = ({ provider }: { provider: string }) => {
    if (!appUrl) return null;
    const inboundUrl = `${appUrl}/api/email/webhook/${provider}`;
    const eventsUrl = `${appUrl}/api/email/webhook/${provider}/events`;
    return (
      <div className="space-y-3 rounded-md border bg-muted/30 p-4">
        <div>
          <p className="mb-1 text-sm font-medium">Inbound Webhook URL</p>
          <p className="text-muted-foreground mb-2 text-xs">
            Configure this URL in your {providerLabels[provider]} dashboard for receiving inbound emails.
          </p>
          <div className="flex items-center gap-2 rounded-md border bg-muted/50 px-3 py-2 font-mono text-xs break-all">
            <span className="flex-1">{inboundUrl}</span>
            <Button type="button" variant="ghost" size="icon" className="h-8 w-8 shrink-0" onClick={() => copyUrl(inboundUrl)} aria-label="Copy inbound webhook URL">
              <Copy className="h-4 w-4" aria-hidden />
            </Button>
          </div>
        </div>
        <div>
          <p className="mb-1 text-sm font-medium">Event Webhook URL</p>
          <p className="text-muted-foreground mb-2 text-xs">
            Set this for delivery event tracking (delivered, bounced, failed).
          </p>
          <div className="flex items-center gap-2 rounded-md border bg-muted/50 px-3 py-2 font-mono text-xs break-all">
            <span className="flex-1">{eventsUrl}</span>
            <Button type="button" variant="ghost" size="icon" className="h-8 w-8 shrink-0" onClick={() => copyUrl(eventsUrl)} aria-label="Copy event webhook URL">
              <Copy className="h-4 w-4" aria-hidden />
            </Button>
          </div>
        </div>
      </div>
    );
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Email Provider</h1>
        <p className="text-muted-foreground mt-1">
          Configure the email provider used for sending and receiving mail.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>General</CardTitle>
          <CardDescription>Email hosting settings that apply across all providers.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <Label>Default Provider</Label>
            <Select
              value={settings.email_hosting.default_provider}
              onValueChange={(v) => {
                setSettings((s) => ({
                  ...s,
                  email_hosting: { ...s.email_hosting, default_provider: v },
                }));
                setActiveTab(v);
              }}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="mailgun">Mailgun</SelectItem>
                <SelectItem value="ses">AWS SES</SelectItem>
                <SelectItem value="sendgrid">SendGrid</SelectItem>
                <SelectItem value="postmark">Postmark</SelectItem>
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              Provider used when creating new domains. Per-domain overrides are configured on the Domains page.
            </p>
          </div>
          <div className="space-y-2">
            <Label>Spam Threshold</Label>
            <Input
              type="number"
              step="0.1"
              min="0"
              max="20"
              value={settings.email_hosting.spam_threshold}
              onChange={(e) =>
                setSettings((s) => ({
                  ...s,
                  email_hosting: { ...s.email_hosting, spam_threshold: e.target.value },
                }))
              }
            />
            <p className="text-xs text-muted-foreground">
              Emails with a spam score at or above this value are flagged as spam.
            </p>
          </div>
          <div className="space-y-2">
            <Label>Max Attachment Size (MB)</Label>
            <Input
              type="number"
              min="1"
              max="100"
              value={settings.email_hosting.max_attachment_size}
              onChange={(e) =>
                setSettings((s) => ({
                  ...s,
                  email_hosting: { ...s.email_hosting, max_attachment_size: e.target.value },
                }))
              }
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Provider Configuration</CardTitle>
          <CardDescription>
            Configure credentials for each email provider. You can configure multiple providers and assign them per-domain.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Tabs value={activeTab} onValueChange={setActiveTab}>
            <TabsList className="mb-4">
              <TabsTrigger value="mailgun">Mailgun</TabsTrigger>
              <TabsTrigger value="ses">AWS SES</TabsTrigger>
              <TabsTrigger value="sendgrid">SendGrid</TabsTrigger>
              <TabsTrigger value="postmark">Postmark</TabsTrigger>
            </TabsList>

            <TabsContent value="mailgun" className="space-y-4">
              <div className="space-y-2">
                <Label>API Key</Label>
                <PasswordInput
                  value={settings.mailgun.api_key}
                  onChange={(e) =>
                    setSettings((s) => ({ ...s, mailgun: { ...s.mailgun, api_key: e.target.value } }))
                  }
                  placeholder="key-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                />
              </div>
              <div className="space-y-2">
                <Label>Region</Label>
                <Select
                  value={settings.mailgun.region}
                  onValueChange={(v) =>
                    setSettings((s) => ({ ...s, mailgun: { ...s.mailgun, region: v } }))
                  }
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="us">US</SelectItem>
                    <SelectItem value="eu">EU</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Webhook Signing Key</Label>
                <PasswordInput
                  value={settings.mailgun.webhook_signing_key}
                  onChange={(e) =>
                    setSettings((s) => ({ ...s, mailgun: { ...s.mailgun, webhook_signing_key: e.target.value } }))
                  }
                  placeholder="Mailgun webhook signing key"
                />
                <p className="text-xs text-muted-foreground">
                  Found in Mailgun dashboard under Settings &gt; API Security &gt; Webhook signing key.
                </p>
              </div>
              <WebhookUrls provider="mailgun" />
            </TabsContent>

            <TabsContent value="ses" className="space-y-4">
              <div className="space-y-2">
                <Label>Access Key ID</Label>
                <PasswordInput
                  value={settings.ses.access_key_id}
                  onChange={(e) =>
                    setSettings((s) => ({ ...s, ses: { ...s.ses, access_key_id: e.target.value } }))
                  }
                  placeholder="AKIAIOSFODNN7EXAMPLE"
                />
              </div>
              <div className="space-y-2">
                <Label>Secret Access Key</Label>
                <PasswordInput
                  value={settings.ses.secret_access_key}
                  onChange={(e) =>
                    setSettings((s) => ({ ...s, ses: { ...s.ses, secret_access_key: e.target.value } }))
                  }
                  placeholder="wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"
                />
              </div>
              <div className="space-y-2">
                <Label>Region</Label>
                <Select
                  value={settings.ses.region}
                  onValueChange={(v) =>
                    setSettings((s) => ({ ...s, ses: { ...s.ses, region: v } }))
                  }
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="us-east-1">US East (N. Virginia)</SelectItem>
                    <SelectItem value="us-east-2">US East (Ohio)</SelectItem>
                    <SelectItem value="us-west-1">US West (N. California)</SelectItem>
                    <SelectItem value="us-west-2">US West (Oregon)</SelectItem>
                    <SelectItem value="eu-west-1">EU (Ireland)</SelectItem>
                    <SelectItem value="eu-west-2">EU (London)</SelectItem>
                    <SelectItem value="eu-central-1">EU (Frankfurt)</SelectItem>
                    <SelectItem value="ap-southeast-1">Asia Pacific (Singapore)</SelectItem>
                    <SelectItem value="ap-southeast-2">Asia Pacific (Sydney)</SelectItem>
                    <SelectItem value="ap-northeast-1">Asia Pacific (Tokyo)</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Configuration Set (Optional)</Label>
                <Input
                  value={settings.ses.configuration_set}
                  onChange={(e) =>
                    setSettings((s) => ({ ...s, ses: { ...s.ses, configuration_set: e.target.value } }))
                  }
                  placeholder="my-configuration-set"
                />
                <p className="text-xs text-muted-foreground">
                  SES configuration set for event tracking. Create one in the AWS console if needed.
                </p>
              </div>
              <WebhookUrls provider="ses" />
              <p className="text-xs text-muted-foreground">
                SES uses SNS topics for webhook delivery. Configure an SNS subscription pointing to the webhook URL above, then confirm the subscription.
              </p>
            </TabsContent>

            <TabsContent value="sendgrid" className="space-y-4">
              <div className="space-y-2">
                <Label>API Key</Label>
                <PasswordInput
                  value={settings.sendgrid.api_key}
                  onChange={(e) =>
                    setSettings((s) => ({ ...s, sendgrid: { ...s.sendgrid, api_key: e.target.value } }))
                  }
                  placeholder="SG.xxxxxxxxxxxxxxxxxxxx"
                />
                <p className="text-xs text-muted-foreground">
                  Create an API key in SendGrid under Settings &gt; API Keys with full access.
                </p>
              </div>
              <div className="space-y-2">
                <Label>Webhook Verification Key (Optional)</Label>
                <PasswordInput
                  value={settings.sendgrid.webhook_verification_key}
                  onChange={(e) =>
                    setSettings((s) => ({ ...s, sendgrid: { ...s.sendgrid, webhook_verification_key: e.target.value } }))
                  }
                  placeholder="Base64-encoded ECDSA public key"
                />
                <p className="text-xs text-muted-foreground">
                  Found in SendGrid under Settings &gt; Mail Settings &gt; Event Webhook &gt; Verification Key. Leave blank to skip signature verification.
                </p>
              </div>
              <WebhookUrls provider="sendgrid" />
              <p className="text-xs text-muted-foreground">
                For inbound email, configure Inbound Parse in SendGrid under Settings &gt; Inbound Parse, pointing to the inbound webhook URL above.
              </p>
            </TabsContent>

            <TabsContent value="postmark" className="space-y-4">
              <div className="space-y-2">
                <Label>Server Token</Label>
                <PasswordInput
                  value={settings.postmark.server_token}
                  onChange={(e) =>
                    setSettings((s) => ({ ...s, postmark: { ...s.postmark, server_token: e.target.value } }))
                  }
                  placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                />
                <p className="text-xs text-muted-foreground">
                  Found on the server overview page in your Postmark dashboard under API Tokens.
                </p>
              </div>
              <WebhookUrls provider="postmark" />
              <p className="text-xs text-muted-foreground">
                Configure the inbound webhook URL in Postmark under Settings &gt; Inbound. Postmark does not sign webhook payloads — security relies on the URL being secret.
              </p>
            </TabsContent>
          </Tabs>
        </CardContent>
        <CardFooter className="flex justify-end">
          <SaveButton type="button" isDirty={isDirty} isSaving={isSaving} onClick={handleSave} />
        </CardFooter>
      </Card>
    </div>
  );
}
