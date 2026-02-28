"use client";

import React, { useState, useEffect, useCallback } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { getErrorMessage } from "@/lib/utils";
import { errorLogger } from "@/lib/error-logger";
import { useOnline } from "@/lib/use-online";
import { OfflineBadge } from "@/components/offline-badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Card,
  CardContent,
  CardDescription,
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
import { Switch } from "@/components/ui/switch";
import { Loader2, Palette, Bell, Brain, Send, Smartphone, Download, Globe, ChevronDown, SlidersHorizontal, Trash2 } from "lucide-react";
import { SaveButton } from "@/components/ui/save-button";

import Link from "next/link";
import {
  isWebPushSupported,
  getPermissionStatus,
  getSubscription,
  subscribe,
} from "@/lib/web-push";
import { useAppConfig } from "@/lib/app-config";
import { HelpLink } from "@/components/help/help-link";
import { useInstallPrompt } from "@/lib/use-install-prompt";
import { TIMEZONES } from "@/lib/timezones";
import { setUserTimezone } from "@/lib/utils";
import { ThemePicker } from "@/components/theme-picker";
import { Checkbox } from "@/components/ui/checkbox";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { getTypesByCategory } from "@/lib/notification-types";

interface UserPreferences {
  theme?: "light" | "dark" | "system";
  default_llm_mode?: "single" | "aggregation" | "council";
  notification_channels?: string[];
  timezone?: string | null;
}

interface NotificationSetting {
  key: string;
  label: string;
  type: string;
  value: string;
  placeholder?: string;
}

interface NotificationChannelPref {
  id: string;
  name: string;
  description: string;
  enabled: boolean;
  configured: boolean;
  usage_accepted: boolean;
  settings: NotificationSetting[];
}

function isIOSDevice(): boolean {
  if (typeof navigator === "undefined") return false;
  return /iPad|iPhone|iPod/.test(navigator.userAgent) ||
    (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
}

function isStandaloneMode(): boolean {
  if (typeof window === "undefined") return false;
  return window.matchMedia("(display-mode: standalone)").matches ||
    (window.navigator as unknown as { standalone?: boolean }).standalone === true;
}

function isAndroidDevice(): boolean {
  if (typeof navigator === "undefined") return false;
  return /Android/i.test(navigator.userAgent);
}

function WebPushHelperText({ webpushEnabled, isSubscribed }: { webpushEnabled: boolean; isSubscribed: boolean }) {
  const isIOS = isIOSDevice();
  const isAndroid = isAndroidDevice();
  const standalone = isStandaloneMode();

  if (isIOS && !standalone) {
    return (
      <p className="text-sm text-muted-foreground">
        <strong>iOS:</strong> Push notifications require this app to be installed to your home screen. Tap the share button in Safari, then &quot;Add to Home Screen.&quot;
      </p>
    );
  }
  if (isIOS && standalone && !isWebPushSupported()) {
    return (
      <p className="text-sm text-muted-foreground">
        Push notifications require iOS 16.4 or later.
      </p>
    );
  }
  if (!isWebPushSupported() && !webpushEnabled) {
    return (
      <p className="text-sm text-muted-foreground">
        Push notifications are not available. Your administrator may need to configure VAPID keys.
      </p>
    );
  }
  if (isAndroid && isSubscribed) {
    return (
      <p className="text-sm text-muted-foreground">
        <strong>Android tip:</strong> If you&apos;re not receiving notifications, check that notifications are enabled for this app in your device&apos;s Settings &gt; Apps &gt; Sourdough &gt; Notifications.
      </p>
    );
  }
  return null;
}

function InstallInstructions() {
  const isIOS = isIOSDevice();
  const isAndroid = isAndroidDevice();

  if (isIOS) {
    return (
      <div className="space-y-2">
        <p className="text-sm font-medium">Install on iOS</p>
        <ol className="text-sm text-muted-foreground list-decimal list-inside space-y-1">
          <li>Tap the <strong>Share</strong> button <span className="inline-block align-text-bottom" aria-label="share icon">(&#xFEFF;↑&#xFEFF;)</span> in Safari&apos;s toolbar</li>
          <li>Scroll down and tap <strong>&quot;Add to Home Screen&quot;</strong></li>
          <li>Tap <strong>Add</strong> to confirm</li>
        </ol>
        <p className="text-xs text-muted-foreground">
          Note: This must be done in Safari. Other iOS browsers do not support installing web apps.
        </p>
      </div>
    );
  }

  if (isAndroid) {
    return (
      <div className="space-y-2">
        <p className="text-sm font-medium">Install on Android</p>
        <ol className="text-sm text-muted-foreground list-decimal list-inside space-y-1">
          <li>Tap the <strong>menu</strong> button <span className="inline-block align-text-bottom" aria-label="menu icon">(⋮)</span> in Chrome</li>
          <li>Tap <strong>&quot;Add to Home screen&quot;</strong> or <strong>&quot;Install app&quot;</strong></li>
          <li>Tap <strong>Install</strong> to confirm</li>
        </ol>
      </div>
    );
  }

  return (
    <div className="space-y-2">
      <p className="text-sm font-medium">Install from your browser</p>
      <ol className="text-sm text-muted-foreground list-decimal list-inside space-y-1">
        <li>In <strong>Chrome</strong> or <strong>Edge</strong>, click the install icon <span className="inline-block align-text-bottom" aria-label="install icon">(⊕)</span> in the address bar</li>
        <li>Or open the browser menu and select <strong>&quot;Install app&quot;</strong></li>
      </ol>
      <p className="text-xs text-muted-foreground">
        If you don&apos;t see an install option, try visiting this page in Chrome or Edge.
      </p>
    </div>
  );
}

export default function PreferencesPage() {
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [preferences, setPreferences] = useState<UserPreferences>({
    default_llm_mode: "single",
    notification_channels: [],
    timezone: null,
  });
  const [effectiveTimezone, setEffectiveTimezone] = useState<string>("UTC");
  const [channels, setChannels] = useState<NotificationChannelPref[]>([]);
  const [channelsLoading, setChannelsLoading] = useState(true);
  const [channelSettings, setChannelSettings] = useState<Record<string, Record<string, string>>>({});
  const [savedChannelSettings, setSavedChannelSettings] = useState<Record<string, Record<string, string>>>({});
  const [savingChannel, setSavingChannel] = useState<string | null>(null);
  const [testingChannel, setTestingChannel] = useState<string | null>(null);
  const [webpushLoading, setWebpushLoading] = useState(false);
  const [currentDeviceSubscribed, setCurrentDeviceSubscribed] = useState(false);
  const [installPrompting, setInstallPrompting] = useState(false);
  const [pushDevices, setPushDevices] = useState<Array<{ id: number; device_name: string; endpoint: string; created_at: string | null; last_used_at: string | null }>>([]);
  const [removingDeviceId, setRemovingDeviceId] = useState<number | null>(null);
  const [typePreferences, setTypePreferences] = useState<Record<string, Record<string, boolean>>>({});
  const [typePrefsOpen, setTypePrefsOpen] = useState(false);
  const { features, novu } = useAppConfig();
  const { isOffline } = useOnline();
  const { canPrompt, isInstalled, promptInstall } = useInstallPrompt();

  const fetchChannels = useCallback(async () => {
    try {
      const response = await api.get("/user/notification-settings");
      const raw = response.data?.channels ?? [];
      const list = raw.map((c: NotificationChannelPref) => ({
        id: c.id,
        name: c.name,
        description: c.description,
        enabled: Boolean(c.enabled),
        configured: Boolean(c.configured),
        usage_accepted: Boolean(c.usage_accepted),
        settings: Array.isArray(c.settings) ? c.settings : [],
      }));
      setChannels(list);
      const initial: Record<string, Record<string, string>> = {};
      list.forEach((ch: NotificationChannelPref) => {
        initial[ch.id] = {};
        ch.settings?.forEach((s) => {
          initial[ch.id][s.key] = s.value ?? "";
        });
      });
      setChannelSettings(initial);
      setSavedChannelSettings(initial);
    } catch (e) {
      errorLogger.captureMessage(
        "Failed to fetch notification channels",
        "warning",
        { error: e instanceof Error ? e.message : String(e) }
      );
      setChannels([]);
    } finally {
      setChannelsLoading(false);
    }
  }, []);

  const fetchPushDevices = useCallback(async () => {
    try {
      const response = await api.get("/user/webpush-subscriptions");
      const devices = response.data?.subscriptions ?? [];
      setPushDevices(devices);
      // Derive whether the current browser is registered by matching
      // the browser's push subscription endpoint against server devices.
      const localSub = await getSubscription();
      if (localSub) {
        const match = devices.some((d: { endpoint: string }) => d.endpoint === localSub.endpoint);
        setCurrentDeviceSubscribed(match);
      } else {
        setCurrentDeviceSubscribed(false);
      }
    } catch {
      setPushDevices([]);
      setCurrentDeviceSubscribed(false);
    }
  }, []);

  const removeDevice = async (deviceId: number) => {
    setRemovingDeviceId(deviceId);
    try {
      await api.delete(`/user/webpush-subscription/${deviceId}`);
      await fetchPushDevices();
      await fetchChannels();
      toast.success("Device removed");
    } catch {
      toast.error("Failed to remove device");
    } finally {
      setRemovingDeviceId(null);
    }
  };

  const fetchPreferences = useCallback(async () => {
    try {
      const response = await api.get("/user/settings");
      const data = response.data;
      
      // Validate and normalize LLM mode value
      const validModes = ["single", "aggregation", "council"] as const;
      const llmModeValue = validModes.includes(data.default_llm_mode)
        ? data.default_llm_mode
        : "single";
      
      // Theme is NOT loaded from the API — localStorage (via ThemeProvider) is
      // the single source of truth. This prevents the server from overriding the
      // user's local choice (race condition / stale-server-value bug).
      setPreferences((prev) => ({
        ...prev,
        default_llm_mode: llmModeValue,
        notification_channels: Array.isArray(data.notification_channels) 
          ? data.notification_channels 
          : [],
        timezone: data.timezone ?? null,
      }));
      if (data.effective_timezone) {
        setEffectiveTimezone(data.effective_timezone);
      }
    } catch (error: unknown) {
      // If endpoint doesn't exist yet, use defaults
      errorLogger.captureMessage("Failed to fetch preferences", "warning", {
        error: error instanceof Error ? error.message : String(error),
      });
    } finally {
      setIsLoading(false);
    }
  }, []);

  const fetchTypePreferences = useCallback(async () => {
    try {
      const response = await api.get("/user/notification-settings/type-preferences");
      setTypePreferences(response.data?.preferences ?? {});
    } catch {
      // Silently fall back to empty (all enabled)
    }
  }, []);


  const toggleTypePreference = async (type: string, channel: string, enabled: boolean) => {
    const snapshot = JSON.parse(JSON.stringify(typePreferences));
    // Optimistic update
    setTypePreferences((prev) => {
      const next = { ...prev };
      if (enabled) {
        if (next[type]) {
          const { [channel]: _, ...rest } = next[type];
          if (Object.keys(rest).length === 0) {
            delete next[type];
          } else {
            next[type] = rest;
          }
        }
      } else {
        next[type] = { ...(next[type] ?? {}), [channel]: false };
      }
      return next;
    });
    try {
      await api.put("/user/notification-settings/type-preferences", { type, channel, enabled });
    } catch {
      setTypePreferences(snapshot);
      toast.error("Failed to update preference");
    }
  };

  useEffect(() => {
    fetchPreferences();
  }, [fetchPreferences]);

  useEffect(() => {
    fetchChannels();
    fetchPushDevices();
  }, [fetchChannels, fetchPushDevices]);

  useEffect(() => {
    fetchTypePreferences();
  }, [fetchTypePreferences]);

  // currentDeviceSubscribed is derived in fetchPushDevices() by matching
  // the browser's push subscription endpoint against server-registered devices.

  const toggleChannel = async (channelId: string, enabled: boolean) => {
    const name = channels.find((c) => c.id === channelId)?.name ?? channelId;
    setChannels((prev) =>
      prev.map((ch) => (ch.id === channelId ? { ...ch, enabled } : ch))
    );
    try {
      const payload: { channel: string; enabled: boolean; usage_accepted?: boolean } = {
        channel: channelId,
        enabled,
      };
      if (enabled) payload.usage_accepted = true;
      await api.put("/user/notification-settings", payload);
      toast.success(`Notifications ${enabled ? "enabled" : "disabled"} for ${name}`);
    } catch (err: unknown) {
      setChannels((prev) =>
        prev.map((ch) => (ch.id === channelId ? { ...ch, enabled: !enabled } : ch))
      );
      const msg = err && typeof err === "object" && "response" in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : null;
      toast.error(msg ?? "Failed to update channel");
    }
  };

  const updateChannelSetting = (channelId: string, key: string, value: string) => {
    setChannelSettings((prev) => ({
      ...prev,
      [channelId]: { ...(prev[channelId] ?? {}), [key]: value },
    }));
  };

  const saveChannelSettings = async (channelId: string) => {
    setSavingChannel(channelId);
    try {
      const settings = channelSettings[channelId] ?? {};
      await api.put("/user/notification-settings", { channel: channelId, settings });
      toast.success("Settings saved");
      setSavedChannelSettings((prev) => ({
        ...prev,
        [channelId]: { ...(channelSettings[channelId] ?? {}) },
      }));
      setChannels((prev) =>
        prev.map((ch) =>
          ch.id === channelId ? { ...ch, configured: true } : ch
        )
      );
    } catch (err: unknown) {
      const msg = err && typeof err === "object" && "response" in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : null;
      toast.error(msg ?? "Failed to save settings");
    } finally {
      setSavingChannel(null);
    }
  };

  const testChannel = async (channelId: string) => {
    setTestingChannel(channelId);
    try {
      await api.post(`/notifications/test/${channelId}`);
      toast.success("Test notification sent");
    } catch (err: unknown) {
      const msg = err && typeof err === "object" && "response" in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : null;
      toast.error(msg ?? "Failed to send test");
    } finally {
      setTestingChannel(null);
    }
  };

  const enableWebPush = async () => {
    const vapidKey = features?.webpushVapidPublicKey;
    if (!vapidKey) {
      toast.error("Browser notifications are not configured. Contact your administrator.");
      return;
    }
    if (!isWebPushSupported()) {
      toast.error("Your browser does not support push notifications.");
      return;
    }
    setWebpushLoading(true);
    try {
      const payload = await subscribe(vapidKey);
      if (!payload) {
        if (getPermissionStatus() === "denied") {
          toast.error("Notification permission was denied.");
        } else {
          toast.error("Failed to subscribe to push notifications.");
        }
        return;
      }
      await api.post("/user/webpush-subscription", payload);
      await api.put("/user/notification-settings", {
        channel: "webpush",
        enabled: true,
        usage_accepted: true,
      });
      setCurrentDeviceSubscribed(true);
      await fetchChannels();
      await fetchPushDevices();
      toast.success("Browser notifications enabled");
    } catch (err: unknown) {
      const msg = err && typeof err === "object" && "response" in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : null;
      toast.error(msg ?? "Failed to enable browser notifications");
      errorLogger.report(
        err instanceof Error ? err : new Error("Web push subscribe failed"),
        { source: "preferences-webpush" }
      );
    } finally {
      setWebpushLoading(false);
    }
  };

  const savePreferences = async (updates: Partial<UserPreferences>) => {
    setIsSaving(true);
    try {
      // Only send the fields that are being updated, filtering out undefined
      const payload: Record<string, unknown> = {};
      if (updates.theme !== undefined && updates.theme !== null) {
        payload.theme = updates.theme;
      }
      if (updates.default_llm_mode !== undefined && updates.default_llm_mode !== null) {
        payload.default_llm_mode = updates.default_llm_mode;
      }
      if (updates.notification_channels !== undefined && updates.notification_channels !== null) {
        payload.notification_channels = updates.notification_channels;
      }
      if (updates.timezone !== undefined) {
        payload.timezone = updates.timezone;
      }
      
      // Ensure we have at least one field to update
      if (Object.keys(payload).length === 0) {
        errorLogger.captureMessage("No fields to update", "warning");
        setIsSaving(false);
        return;
      }
      
      const response = await api.put("/user/settings", payload);
      
      // Update local state with the merged preferences
      const newPreferences = { ...preferences, ...updates };
      setPreferences(newPreferences);

      // Update effective timezone from response if available
      const prefs = response.data?.preferences;
      if (prefs?.effective_timezone) {
        setEffectiveTimezone(prefs.effective_timezone);
        setUserTimezone(prefs.effective_timezone);
      }

      toast.success("Preferences saved");
    } catch (error: unknown) {
      const data = error && typeof error === "object" && "response" in error
        ? (error as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }).response?.data
        : null;
      if (data?.errors) {
        const errorMessages = Object.values(data.errors).flat().join(", ");
        toast.error(`Validation error: ${errorMessages}`);
      } else {
        toast.error(getErrorMessage(error, "Failed to save preferences"));
      }
      errorLogger.report(
        error instanceof Error ? error : new Error("Failed to save preferences"),
        { response: data, source: "preferences-page" }
      );
    } finally {
      setIsSaving(false);
    }
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-2 flex-wrap">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Preferences</h1>
          <p className="text-muted-foreground">
            {isOffline
              ? "You're offline. Settings are read-only; changes will sync when you're back online."
              : "Customize your personal settings and preferences."}
            {!isOffline && " "}
            {!isOffline && <HelpLink articleId="notification-settings" />}
          </p>
        </div>
        <OfflineBadge />
      </div>

      {/* Appearance */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Palette className="h-5 w-5" />
            Appearance
          </CardTitle>
          <CardDescription>
            Choose your preferred mode and color theme.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <ThemePicker />
        </CardContent>
      </Card>

      {/* Defaults */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Brain className="h-5 w-5" />
            Defaults
          </CardTitle>
          <CardDescription>
            Set your default preferences for AI interactions.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="default_llm_mode">Default LLM Mode</Label>
            <Select
              value={preferences.default_llm_mode ?? "single"}
              onValueChange={(value) => {
                if (isOffline) return;
                const validMode = ["single", "aggregation", "council"].includes(value)
                  ? (value as "single" | "aggregation" | "council")
                  : "single";
                savePreferences({
                  default_llm_mode: validMode,
                });
              }}
              disabled={isOffline}
            >
              <SelectTrigger id="default_llm_mode">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="single">Single Provider</SelectItem>
                <SelectItem value="aggregation">Aggregation</SelectItem>
                <SelectItem value="council">Council</SelectItem>
              </SelectContent>
            </Select>
            <p className="text-sm text-muted-foreground">
              <strong>Single:</strong> Uses one provider (fastest, cheapest).{" "}
              <strong>Aggregation:</strong> Queries all providers, primary synthesizes responses.{" "}
              <strong>Council:</strong> Providers vote for consensus (best for accuracy).
            </p>
          </div>
        </CardContent>
      </Card>

      {/* Regional */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Globe className="h-5 w-5" />
            Regional
          </CardTitle>
          <CardDescription>
            Set your preferred timezone for dates and times throughout the app.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="timezone">Timezone</Label>
            <Select
              value={preferences.timezone ?? ""}
              onValueChange={(value) => {
                if (isOffline) return;
                savePreferences({ timezone: value === "__system_default__" ? null : value });
              }}
              disabled={isOffline}
            >
              <SelectTrigger id="timezone">
                <SelectValue placeholder="Use system default" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__system_default__">Use system default</SelectItem>
                {/* Show current value if not in curated list (e.g. auto-detected unusual timezone) */}
                {preferences.timezone && !TIMEZONES.some((tz) => tz.value === preferences.timezone) && (
                  <SelectItem key={preferences.timezone} value={preferences.timezone}>
                    {preferences.timezone} (detected)
                  </SelectItem>
                )}
                {TIMEZONES.map((tz) => (
                  <SelectItem key={tz.value} value={tz.value}>
                    {tz.label} ({tz.value})
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-sm text-muted-foreground">
              {preferences.timezone
                ? `Manually set to ${preferences.timezone}.`
                : `Auto-detected from your browser. Currently using: ${effectiveTimezone}.`}
              {" "}Select &quot;Use system default&quot; to revert to automatic detection.
            </p>
          </div>
        </CardContent>
      </Card>

      {/* Notification Preferences */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Bell className="h-5 w-5" />
            Notification Preferences
          </CardTitle>
          <CardDescription>
            {novu?.enabled
              ? "Notifications are delivered via Novu. Use the notification bell in the header to view and manage preferences."
              : "Enable channels, add your webhook or phone number, test, and accept usage. Only channels enabled by an administrator are shown."}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {novu?.enabled ? (
            <p className="text-sm text-muted-foreground">
              Click the notification bell in the header to open your inbox and manage notification preferences.
            </p>
          ) : channelsLoading ? (
            <div className="flex items-center justify-center py-8">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : channels.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              No notification channels available. An administrator must enable channels in{" "}
              <Link href="/configuration/notifications" className="text-primary hover:underline">
                Configuration
              </Link>
              .
            </p>
          ) : (
            <div className="space-y-6">
              {channels.map((channel) => (
                <div key={channel.id} className="space-y-3">
                  <div className="flex items-center justify-between gap-4">
                    <div className="min-w-0 flex-1">
                      <Label className="text-base font-medium">{channel.name}</Label>
                      <p className="text-sm text-muted-foreground">{channel.description}</p>
                    </div>
                    {channel.id === "webpush" ? (
                      <div className="flex items-center gap-2">
                        {channel.configured || currentDeviceSubscribed ? (
                          <Switch
                            checked={channel.enabled}
                            onCheckedChange={(enabled) => toggleChannel("webpush", enabled)}
                            disabled={webpushLoading || isOffline}
                          />
                        ) : (
                          <Button
                            size="sm"
                            onClick={enableWebPush}
                            disabled={
                              webpushLoading ||
                              isOffline ||
                              !features?.webpushEnabled ||
                              !isWebPushSupported()
                            }
                          >
                            {webpushLoading ? (
                              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                              <Smartphone className="mr-2 h-4 w-4" />
                            )}
                            Enable Browser Notifications
                          </Button>
                        )}
                      </div>
                    ) : (
                      <div className="flex items-center gap-2">
                        {channel.settings.length > 0 && !channel.configured && (
                          <span className="text-xs text-muted-foreground">
                            Enter settings below to enable
                          </span>
                        )}
                        <Switch
                          checked={channel.enabled}
                          onCheckedChange={(enabled) => toggleChannel(channel.id, enabled)}
                          disabled={(channel.settings.length > 0 && !channel.configured) || isOffline}
                        />
                      </div>
                    )}
                  </div>
                  {channel.id === "webpush" && (
                    <div className="space-y-3">
                      {currentDeviceSubscribed && (
                        <div className="flex items-center gap-2">
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => testChannel("webpush")}
                            disabled={testingChannel === "webpush" || isOffline}
                          >
                            {testingChannel === "webpush" ? (
                              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                              <Send className="mr-2 h-4 w-4" />
                            )}
                            Test
                          </Button>
                        </div>
                      )}
                      {pushDevices.length > 0 && (
                        <div className="space-y-2">
                          <p className="text-sm font-medium text-muted-foreground">Registered Devices</p>
                          <div className="space-y-1">
                            {pushDevices.map((device) => (
                              <div key={device.id} className="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
                                <div className="min-w-0 flex-1">
                                  <span className="font-medium">{device.device_name || "Unknown Device"}</span>
                                  {device.last_used_at && (
                                    <span className="ml-2 text-xs text-muted-foreground">
                                      Last used {new Date(device.last_used_at).toLocaleDateString()}
                                    </span>
                                  )}
                                </div>
                                <Button
                                  size="icon"
                                  variant="ghost"
                                  className="h-7 w-7 shrink-0"
                                  onClick={() => removeDevice(device.id)}
                                  disabled={removingDeviceId === device.id || isOffline}
                                >
                                  {removingDeviceId === device.id ? (
                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                  ) : (
                                    <Trash2 className="h-3.5 w-3.5" />
                                  )}
                                </Button>
                              </div>
                            ))}
                          </div>
                        </div>
                      )}
                      {!currentDeviceSubscribed && isWebPushSupported() && features?.webpushEnabled && (
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={enableWebPush}
                          disabled={webpushLoading || isOffline}
                        >
                          {webpushLoading ? (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                          ) : (
                            <Smartphone className="mr-2 h-4 w-4" />
                          )}
                          Add This Device
                        </Button>
                      )}
                    </div>
                  )}
                  {channel.id === "webpush" && (
                    <WebPushHelperText webpushEnabled={!!features?.webpushEnabled} isSubscribed={currentDeviceSubscribed} />
                  )}
                  {channel.settings.length > 0 && channel.id !== "webpush" && (
                    <>
                      <Separator />
                      <div className="space-y-3 pl-0">
                        {channel.settings.map((s) => (
                          <div key={s.key} className="space-y-1">
                            <Label htmlFor={`${channel.id}-${s.key}`}>{s.label}</Label>
                            <Input
                              id={`${channel.id}-${s.key}`}
                              type={s.type === "password" ? "password" : "text"}
                              value={channelSettings[channel.id]?.[s.key] ?? ""}
                              onChange={(e) => updateChannelSetting(channel.id, s.key, e.target.value)}
                              placeholder={s.placeholder}
                            />
                          </div>
                        ))}
                        <div className="flex gap-2">
                          <SaveButton
                            type="button"
                            size="sm"
                            isDirty={JSON.stringify(channelSettings[channel.id] ?? {}) !== JSON.stringify(savedChannelSettings[channel.id] ?? {})}
                            isSaving={savingChannel === channel.id}
                            disabled={isOffline}
                            onClick={() => saveChannelSettings(channel.id)}
                          />
                          {channel.configured && (
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => testChannel(channel.id)}
                              disabled={testingChannel === channel.id || isOffline}
                            >
                              {testingChannel === channel.id ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                              ) : (
                                <Send className="mr-2 h-4 w-4" />
                              )}
                              Test
                            </Button>
                          )}
                        </div>
                      </div>
                    </>
                  )}
                </div>
              ))}
            </div>
          )}

          {/* Per-type preference matrix */}
          {!novu?.enabled && (() => {
            const enabledChannels = channels.filter((c) => c.enabled && c.id !== "database");
            if (enabledChannels.length < 1) return null;
            const categories = getTypesByCategory();
            return (
              <Collapsible open={typePrefsOpen} onOpenChange={setTypePrefsOpen} className="mt-6">
                <CollapsibleTrigger asChild>
                  <Button variant="ghost" className="flex w-full items-center justify-between p-0 h-auto hover:bg-transparent">
                    <span className="flex items-center gap-2 text-sm font-medium">
                      <SlidersHorizontal className="h-4 w-4" />
                      Fine-tune by notification type
                    </span>
                    <ChevronDown className={`h-4 w-4 transition-transform ${typePrefsOpen ? "rotate-180" : ""}`} />
                  </Button>
                </CollapsibleTrigger>
                <CollapsibleContent className="mt-4">
                  <p className="text-sm text-muted-foreground mb-4">
                    Control which notification types are sent to each channel. Unchecked types will be silenced on that channel.
                  </p>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="border-b">
                          <th className="text-left py-2 pr-4 font-medium">Type</th>
                          {enabledChannels.map((ch) => (
                            <th key={ch.id} className="text-center py-2 px-2 font-medium whitespace-nowrap">
                              {ch.name}
                            </th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {categories.map((cat) => (
                          <React.Fragment key={cat.category}>
                            <tr>
                              <td colSpan={enabledChannels.length + 1} className="pt-4 pb-1 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                {cat.categoryLabel}
                              </td>
                            </tr>
                            {cat.types.map(({ type, label, icon: Icon }) => (
                              <tr key={type} className="border-b border-border/50">
                                <td className="py-2 pr-4">
                                  <span className="flex items-center gap-2">
                                    <Icon className="h-3.5 w-3.5 text-muted-foreground" />
                                    {label}
                                  </span>
                                </td>
                                {enabledChannels.map((ch) => {
                                  const checked = typePreferences[type]?.[ch.id] !== false;
                                  return (
                                    <td key={ch.id} className="text-center py-2 px-2">
                                      <Checkbox
                                        checked={checked}
                                        onCheckedChange={(val) => toggleTypePreference(type, ch.id, !!val)}
                                        disabled={isOffline}
                                        aria-label={`${label} via ${ch.name}`}
                                      />
                                    </td>
                                  );
                                })}
                              </tr>
                            ))}
                          </React.Fragment>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </CollapsibleContent>
              </Collapsible>
            );
          })()}
        </CardContent>
      </Card>

      {/* Install App (PWA) */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Download className="h-5 w-5" />
            Install App
          </CardTitle>
          <CardDescription>
            Install this app on your device for quick access and offline use.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {isInstalled ? (
            <p className="text-sm text-muted-foreground">
              The app is installed. Open it from your home screen or app drawer.
            </p>
          ) : canPrompt ? (
            <Button
              size="sm"
              onClick={async () => {
                setInstallPrompting(true);
                try {
                  const result = await promptInstall();
                  if (result?.outcome === "accepted") {
                    toast.success("App installed");
                  }
                } finally {
                  setInstallPrompting(false);
                }
              }}
              disabled={installPrompting || isOffline}
            >
              {installPrompting ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Download className="mr-2 h-4 w-4" />
              )}
              Install App
            </Button>
          ) : (
            <InstallInstructions />
          )}
        </CardContent>
      </Card>

    </div>
  );
}
