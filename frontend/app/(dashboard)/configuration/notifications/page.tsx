"use client";

import { useState, useEffect, useCallback } from "react";
import { toast } from "sonner";
import { useQueryClient } from "@tanstack/react-query";
import { api } from "@/lib/api";
import { errorLogger } from "@/lib/error-logger";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { HelpLink } from "@/components/help/help-link";
import { ChannelsTab } from "@/components/notifications/channels-tab";
import { CredentialsTab } from "@/components/notifications/credentials-tab";
import { RateLimitingTab } from "@/components/notifications/rate-limiting-tab";
import { EmailTab } from "@/components/notifications/email-tab";
import { NovuTab } from "@/components/notifications/novu-tab";
import { TemplatesTab } from "@/components/notifications/templates-tab";

interface AdminChannel {
  id: string;
  name: string;
  description: string;
  provider_configured: boolean;
  available: boolean;
  admin_toggle: boolean;
  sms_provider: boolean | null;
}

export default function NotificationsPage() {
  const queryClient = useQueryClient();
  const [channels, setChannels] = useState<AdminChannel[]>([]);
  const [smsProvider, setSmsProvider] = useState<string | null>(null);
  const [smsProvidersConfigured, setSmsProvidersConfigured] = useState<string[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [settings, setSettings] = useState<Record<string, unknown>>({});

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

  if (isLoading) {
    return <SettingsPageSkeleton />;
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Notifications</h1>
        <p className="text-muted-foreground mt-1">
          Enable which notification channels are available to users. Configure channel credentials below. Users set their own webhooks and phone numbers in Preferences.{" "}
          <HelpLink articleId="notification-channels" />
        </p>
      </div>

      <Tabs defaultValue="channels" className="space-y-6">
        <TabsList>
          <TabsTrigger value="channels">Channels</TabsTrigger>
          <TabsTrigger value="credentials">Credentials</TabsTrigger>
          <TabsTrigger value="rate-limiting">Rate Limiting</TabsTrigger>
          <TabsTrigger value="email">Email</TabsTrigger>
          <TabsTrigger value="novu">Novu</TabsTrigger>
          <TabsTrigger value="templates">Templates</TabsTrigger>
        </TabsList>

        <TabsContent value="channels" forceMount className="data-[state=inactive]:hidden">
          <ChannelsTab
            channels={channels}
            setChannels={setChannels}
            smsProvider={smsProvider}
            setSmsProvider={setSmsProvider}
            smsProvidersConfigured={smsProvidersConfigured}
          />
        </TabsContent>

        <TabsContent value="credentials" forceMount className="data-[state=inactive]:hidden">
          <CredentialsTab
            settings={settings}
            onSaved={fetchConfig}
          />
        </TabsContent>

        <TabsContent value="rate-limiting" forceMount className="data-[state=inactive]:hidden">
          <RateLimitingTab
            settings={settings}
            onSaved={fetchConfig}
          />
        </TabsContent>

        <TabsContent value="email" forceMount className="data-[state=inactive]:hidden">
          <EmailTab />
        </TabsContent>

        <TabsContent value="novu" forceMount className="data-[state=inactive]:hidden">
          <NovuTab />
        </TabsContent>

        <TabsContent value="templates" forceMount className="data-[state=inactive]:hidden">
          <TemplatesTab />
        </TabsContent>
      </Tabs>
    </div>
  );
}
