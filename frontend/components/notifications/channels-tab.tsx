"use client";

import { useState } from "react";
import { api } from "@/lib/api";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
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
} from "lucide-react";

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

function extractErrorMessage(err: unknown): string | null {
  if (err && typeof err === "object" && "response" in err) {
    return (err as { response?: { data?: { message?: string } } }).response?.data?.message ?? null;
  }
  return null;
}

interface ChannelsTabProps {
  channels: AdminChannel[];
  setChannels: React.Dispatch<React.SetStateAction<AdminChannel[]>>;
  smsProvider: string | null;
  setSmsProvider: React.Dispatch<React.SetStateAction<string | null>>;
  smsProvidersConfigured: string[];
}

export function ChannelsTab({
  channels,
  setChannels,
  smsProvider,
  setSmsProvider,
  smsProvidersConfigured,
}: ChannelsTabProps) {
  const [savingChannels, setSavingChannels] = useState<Set<string>>(new Set());
  const [verifyResults, setVerifyResults] = useState<Record<string, { status: string; error?: string; reason?: string }> | null>(null);
  const [isTestingAll, setIsTestingAll] = useState(false);

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

  return (
    <div className="space-y-6">
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
    </div>
  );
}
