"use client";

import { useState, useEffect, useCallback, useRef } from "react";
import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import { toast } from "sonner";
import { api } from "@/lib/api";
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Badge } from "@/components/ui/badge";
import { Switch } from "@/components/ui/switch";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import {
  ArrowLeft,
  RefreshCw,
  Loader2,
  Copy,
  CheckCircle2,
  XCircle,
  RotateCw,
  Plus,
  Trash2,
  ChevronRight,
  ChevronLeft,
  AlertTriangle,
  Zap,
  Download,
  Upload,
} from "lucide-react";
import { cn } from "@/lib/utils";

// ─── Types ───────────────────────────────────────────────────────────────────

interface EmailDomain {
  id: number;
  name: string;
  provider: string;
  is_verified: boolean;
  is_active: boolean;
  verified_at: string | null;
  dkim_rotated_at: string | null;
}

interface DnsRecord {
  type: string;
  name: string;
  value: string;
  valid: string;
  purpose?: string;
}

interface DkimInfo {
  selector?: string;
  public_key?: string;
  created_at?: string;
}

interface Webhook {
  urls?: string[];
}

interface Route {
  id: string;
  expression: string;
  description: string;
  priority: number;
  actions: string[];
}

interface EventItem {
  timestamp: number;
  event: string;
  recipient?: string;
  message?: { headers?: { subject?: string; "message-id"?: string } };
  "delivery-status"?: { message?: string; code?: number };
  severity?: string;
}

interface SuppressionItem {
  address: string;
  error?: string;
  code?: number;
  created_at?: string;
  tag?: string;
}

interface TrackingSettings {
  click?: { active: boolean };
  open?: { active: boolean };
  unsubscribe?: { active: boolean; html_footer?: string; text_footer?: string };
}

interface StatsPoint {
  time: string;
  accepted?: { incoming?: number; outgoing?: number; total?: number };
  delivered?: { smtp?: number; http?: number; total?: number };
  failed?: { permanent?: { bounce?: number; total?: number }; temporary?: { espblock?: number; total?: number } };
  complained?: { total?: number };
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function copyToClipboard(text: string, label = "Copied") {
  void navigator.clipboard.writeText(text).then(() => toast.success(`${label} copied to clipboard`));
}

function formatTs(ts: number | string | null | undefined): string {
  if (!ts) return "—";
  const d = typeof ts === "number" ? new Date(ts * 1000) : new Date(ts);
  return d.toLocaleString();
}

function EventBadge({ event }: { event: string }) {
  const map: Record<string, string> = {
    delivered: "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400",
    opened: "bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400",
    clicked: "bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400",
    bounced: "bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400",
    failed: "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400",
    complained: "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400",
    accepted: "bg-muted text-muted-foreground",
    unsubscribed: "bg-muted text-muted-foreground",
  };
  return (
    <span className={cn("inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium", map[event] ?? "bg-muted text-muted-foreground")}>
      {event}
    </span>
  );
}

// ─── Tab: DNS Records ─────────────────────────────────────────────────────────

function DnsTab({ domain }: { domain: EmailDomain }) {
  const [records, setRecords] = useState<DnsRecord[]>([]);
  const [isVerifying, setIsVerifying] = useState(false);

  const verify = useCallback(async () => {
    setIsVerifying(true);
    try {
      const res = await api.post<{ is_verified: boolean; dns_records: DnsRecord[] }>(`/email/domains/${domain.id}/verify`);
      setRecords(res.data.dns_records ?? []);
      if (res.data.is_verified) {
        toast.success("Domain verified — all DNS records are correct.");
      } else {
        toast.info("Not yet verified. Check that all records below are configured.");
      }
    } catch {
      toast.error("Verification check failed");
    } finally {
      setIsVerifying(false);
    }
  }, [domain.id]);

  useEffect(() => { void verify(); }, [verify]);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-muted-foreground">
          Add these records in your domain registrar, then click Verify. DNS propagation can take up to 48 hours.
        </p>
        <Button variant="outline" size="sm" onClick={verify} disabled={isVerifying}>
          {isVerifying ? <Loader2 className="mr-2 h-3 w-3 animate-spin" /> : <RefreshCw className="mr-2 h-3 w-3" />}
          Verify Now
        </Button>
      </div>
      {records.length === 0 && !isVerifying && (
        <p className="text-sm text-muted-foreground">No records loaded yet. Click Verify Now to fetch them.</p>
      )}
      {records.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-16">Type</TableHead>
                <TableHead>Name</TableHead>
                <TableHead>Value</TableHead>
                <TableHead className="w-20">Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {records.map((r, i) => (
                <TableRow key={i}>
                  <TableCell className="font-mono text-xs">{r.type}</TableCell>
                  <TableCell>
                    <div className="flex items-center gap-2 min-w-0">
                      <span className="font-mono text-xs truncate max-w-[200px]">{r.name || "@"}</span>
                      <Button variant="ghost" size="icon" className="h-6 w-6 shrink-0" onClick={() => copyToClipboard(r.name, "Name")}>
                        <Copy className="h-3 w-3" />
                      </Button>
                    </div>
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center gap-2 min-w-0">
                      <span className="font-mono text-xs truncate max-w-[300px]">{r.value}</span>
                      <Button variant="ghost" size="icon" className="h-6 w-6 shrink-0" onClick={() => copyToClipboard(r.value, "Value")}>
                        <Copy className="h-3 w-3" />
                      </Button>
                    </div>
                  </TableCell>
                  <TableCell>
                    {r.valid === "valid" ? (
                      <CheckCircle2 className="h-4 w-4 text-green-600" />
                    ) : (
                      <XCircle className="h-4 w-4 text-destructive" />
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
    </div>
  );
}

// ─── Tab: DKIM ────────────────────────────────────────────────────────────────

function DkimTab({ domain }: { domain: EmailDomain }) {
  const [dkim, setDkim] = useState<DkimInfo | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isRotating, setIsRotating] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);
  const [intervalDays, setIntervalDays] = useState(0);
  const [isSavingInterval, setIsSavingInterval] = useState(false);
  const [rotationHistory, setRotationHistory] = useState<Array<{ id: number; created_at: string; new_values?: Record<string, unknown> }>>([]);

  const fetchDkim = useCallback(async () => {
    setIsLoading(true);
    try {
      const res = await api.get<DkimInfo>(`/email/domains/${domain.id}/mailgun/dkim`);
      setDkim(res.data);
    } catch {
      toast.error("Failed to load DKIM info");
    } finally {
      setIsLoading(false);
    }
  }, [domain.id]);

  useEffect(() => { void fetchDkim(); }, [fetchDkim]);

  useEffect(() => {
    api.get<{ interval_days: number }>("/email/dkim-rotation-settings")
      .then((res) => setIntervalDays(res.data.interval_days))
      .catch((err) => { if (err?.response?.status !== 403) toast.error("Failed to load DKIM rotation settings"); });
    api.get<{ history: Array<{ id: number; created_at: string; new_values?: Record<string, unknown> }> }>(
      `/email/domains/${domain.id}/mailgun/dkim/rotation-history`
    )
      .then((res) => setRotationHistory(res.data.history ?? []))
      .catch((err) => { if (err?.response?.status !== 403) toast.error("Failed to load rotation history"); });
  }, [domain.id]);

  const saveInterval = async () => {
    setIsSavingInterval(true);
    try {
      await api.put("/email/dkim-rotation-settings", { interval_days: intervalDays });
      toast.success(intervalDays > 0 ? `Auto-rotation set to every ${intervalDays} days` : "Auto-rotation disabled");
    } catch {
      toast.error("Failed to save rotation settings");
    } finally {
      setIsSavingInterval(false);
    }
  };

  const rotateDkim = async () => {
    setIsRotating(true);
    setShowConfirm(false);
    try {
      await api.post(`/email/domains/${domain.id}/mailgun/dkim/rotate`);
      toast.success("DKIM key rotated. Update your DNS DKIM record with the new selector.");
      void fetchDkim();
    } catch {
      toast.error("DKIM rotation failed");
    } finally {
      setIsRotating(false);
    }
  };

  if (isLoading) return <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-muted-foreground">
          DKIM signing key for this domain. Rotate periodically for best deliverability.
        </p>
        <Button variant="outline" size="sm" onClick={() => setShowConfirm(true)} disabled={isRotating}>
          {isRotating ? <Loader2 className="mr-2 h-3 w-3 animate-spin" /> : <RotateCw className="mr-2 h-3 w-3" />}
          Rotate Key
        </Button>
      </div>

      {domain.dkim_rotated_at && (
        <p className="text-xs text-muted-foreground">Last rotated: {formatTs(domain.dkim_rotated_at)}</p>
      )}

      {dkim && (
        <div className="rounded-md border divide-y text-sm">
          {dkim.selector && (
            <div className="flex items-center justify-between px-4 py-3">
              <span className="text-muted-foreground w-32 shrink-0">Selector</span>
              <div className="flex items-center gap-2 min-w-0">
                <span className="font-mono">{dkim.selector}</span>
                <Button variant="ghost" size="icon" className="h-6 w-6" onClick={() => copyToClipboard(dkim.selector!, "Selector")}>
                  <Copy className="h-3 w-3" />
                </Button>
              </div>
            </div>
          )}
          {dkim.public_key && (
            <div className="flex items-start justify-between gap-4 px-4 py-3">
              <span className="text-muted-foreground w-32 shrink-0 pt-0.5">Public Key</span>
              <div className="flex items-start gap-2 min-w-0 flex-1">
                <span className="font-mono text-xs break-all line-clamp-3">{dkim.public_key}</span>
                <Button variant="ghost" size="icon" className="h-6 w-6 shrink-0" onClick={() => copyToClipboard(dkim.public_key!, "Public key")}>
                  <Copy className="h-3 w-3" />
                </Button>
              </div>
            </div>
          )}
          {dkim.created_at && (
            <div className="flex items-center justify-between px-4 py-3">
              <span className="text-muted-foreground w-32 shrink-0">Created</span>
              <span>{formatTs(dkim.created_at)}</span>
            </div>
          )}
        </div>
      )}

      {!dkim && (
        <p className="text-sm text-muted-foreground">No DKIM info available. The Mailgun API may not support this for your plan.</p>
      )}

      <div className="space-y-3 pt-2 border-t">
        <h3 className="text-sm font-medium">Auto-Rotation Schedule</h3>
        <div className="flex items-center gap-3">
          <Input
            type="number"
            min={0}
            max={3650}
            value={intervalDays}
            onChange={(e) => setIntervalDays(parseInt(e.target.value) || 0)}
            className="w-24 h-8"
          />
          <span className="text-sm text-muted-foreground">days (0 = disabled)</span>
          <Button size="sm" onClick={saveInterval} disabled={isSavingInterval}>
            {isSavingInterval && <Loader2 className="mr-1 h-3 w-3 animate-spin" />}
            Save
          </Button>
        </div>
      </div>

      {rotationHistory.length > 0 && (
        <div className="space-y-2 pt-2 border-t">
          <h3 className="text-sm font-medium">Rotation History</h3>
          <div className="rounded-md border divide-y text-sm max-h-48 overflow-y-auto">
            {rotationHistory.map((log) => (
              <div key={log.id} className="px-4 py-2 flex items-center justify-between">
                <span className="text-xs text-muted-foreground">{formatTs(log.created_at)}</span>
                <Badge variant="outline" className="text-xs">
                  {log.new_values && "scheduled" in log.new_values ? "Scheduled" : "Manual"}
                </Badge>
              </div>
            ))}
          </div>
        </div>
      )}

      <AlertDialog open={showConfirm} onOpenChange={setShowConfirm}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Rotate DKIM Key?</AlertDialogTitle>
            <AlertDialogDescription>
              This will generate a new DKIM selector and key. You must update the DKIM TXT record in your DNS after rotation or outbound mail may fail DKIM checks.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={rotateDkim}>Rotate Key</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

// ─── Tab: Webhooks ────────────────────────────────────────────────────────────

const WEBHOOK_EVENTS = ["delivered", "opened", "clicked", "bounced", "complained", "unsubscribed", "stored"] as const;

function WebhooksTab({ domain }: { domain: EmailDomain }) {
  const [webhooks, setWebhooks] = useState<Record<string, Webhook>>({});
  const [isLoading, setIsLoading] = useState(true);
  const [isConfiguring, setIsConfiguring] = useState(false);
  const [editEvent, setEditEvent] = useState<string | null>(null);
  const [editUrl, setEditUrl] = useState("");
  const [isSaving, setIsSaving] = useState(false);
  const [testingWebhook, setTestingWebhook] = useState<string | null>(null);

  const fetchWebhooks = useCallback(async () => {
    setIsLoading(true);
    try {
      const res = await api.get<{ webhooks: Record<string, Webhook> }>(`/email/domains/${domain.id}/mailgun/webhooks`);
      setWebhooks(res.data.webhooks ?? {});
    } catch {
      toast.error("Failed to load webhooks");
    } finally {
      setIsLoading(false);
    }
  }, [domain.id]);

  useEffect(() => { void fetchWebhooks(); }, [fetchWebhooks]);

  const autoConfigure = async () => {
    setIsConfiguring(true);
    try {
      const res = await api.post(`/email/domains/${domain.id}/mailgun/webhooks/auto-configure`);
      if (res.data.errors && Object.keys(res.data.errors).length > 0) {
        const errorMessages = Object.values(res.data.errors as Record<string, string>).join(", ");
        toast.warning(`Some webhooks failed: ${errorMessages}`);
      } else {
        toast.success("Delivery webhooks configured for this domain.");
      }
      void fetchWebhooks();
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      toast.error(msg || "Auto-configure failed");
    } finally {
      setIsConfiguring(false);
    }
  };

  const openEdit = (event: string) => {
    const existing = webhooks[event];
    const url = existing?.urls?.[0] ?? "";
    setEditEvent(event);
    setEditUrl(url);
  };

  const saveWebhook = async () => {
    if (!editEvent) return;
    setIsSaving(true);
    try {
      const existing = webhooks[editEvent];
      if (existing?.urls?.length) {
        await api.put(`/email/domains/${domain.id}/mailgun/webhooks/${editEvent}`, { url: editUrl });
      } else {
        await api.post(`/email/domains/${domain.id}/mailgun/webhooks`, { event: editEvent, url: editUrl });
      }
      toast.success("Webhook saved");
      setEditEvent(null);
      void fetchWebhooks();
    } catch {
      toast.error("Failed to save webhook");
    } finally {
      setIsSaving(false);
    }
  };

  const testWebhook = async (event: string) => {
    setTestingWebhook(event);
    try {
      const res = await api.post<{ success: boolean; status_code?: number; message?: string }>(
        `/email/domains/${domain.id}/mailgun/webhooks/${event}/test`
      );
      if (res.data.success) {
        toast.success(`Test ${event} webhook: ${res.data.status_code} OK`);
      } else {
        toast.error(`Test failed: ${res.data.message ?? "Unknown error"}`);
      }
    } catch {
      toast.error("Webhook test failed");
    } finally {
      setTestingWebhook(null);
    }
  };

  const deleteWebhook = async (event: string) => {
    try {
      await api.delete(`/email/domains/${domain.id}/mailgun/webhooks/${event}`);
      toast.success("Webhook removed");
      void fetchWebhooks();
    } catch {
      toast.error("Failed to remove webhook");
    }
  };

  if (isLoading) return <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-muted-foreground">
          Configure which Mailgun events notify selfmx. Delivery events (delivered, bounced, complained) are required for status tracking.
        </p>
        <Button variant="outline" size="sm" onClick={autoConfigure} disabled={isConfiguring}>
          {isConfiguring ? <Loader2 className="mr-2 h-3 w-3 animate-spin" /> : <RefreshCw className="mr-2 h-3 w-3" />}
          Auto-configure
        </Button>
      </div>

      <div className="rounded-md border divide-y text-sm">
        {WEBHOOK_EVENTS.map((event) => {
          const hook = webhooks[event];
          const url = hook?.urls?.[0] ?? null;
          return (
            <div key={event} className="flex items-center justify-between gap-4 px-4 py-3">
              <div className="flex items-center gap-3 min-w-0">
                <EventBadge event={event} />
                {url ? (
                  <span className="font-mono text-xs text-muted-foreground truncate max-w-[280px]">{url}</span>
                ) : (
                  <span className="text-xs text-muted-foreground italic">Not configured</span>
                )}
              </div>
              <div className="flex items-center gap-1 shrink-0">
                <Button variant="ghost" size="sm" onClick={() => openEdit(event)}>
                  {url ? "Edit" : <Plus className="h-3 w-3" />}
                </Button>
                {url && (
                  <>
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-8 text-xs"
                      onClick={() => testWebhook(event)}
                      disabled={testingWebhook === event}
                    >
                      {testingWebhook === event ? <Loader2 className="h-3 w-3 animate-spin" /> : <Zap className="mr-1 h-3 w-3" />}
                      {testingWebhook === event ? "" : "Test"}
                    </Button>
                    <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => deleteWebhook(event)}>
                      <Trash2 className="h-3 w-3 text-destructive" />
                    </Button>
                  </>
                )}
              </div>
            </div>
          );
        })}
      </div>

      <Dialog open={editEvent !== null} onOpenChange={(o) => !o && setEditEvent(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Configure {editEvent} webhook</DialogTitle>
            <DialogDescription>Enter the URL Mailgun should POST to when this event occurs.</DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label>Webhook URL</Label>
            <Input
              value={editUrl}
              onChange={(e) => setEditUrl(e.target.value)}
              placeholder="https://example.com/webhook"
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditEvent(null)}>Cancel</Button>
            <Button onClick={saveWebhook} disabled={isSaving || !editUrl}>
              {isSaving && <Loader2 className="mr-2 h-3 w-3 animate-spin" />}
              Save
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

// ─── Tab: Inbound Routes ──────────────────────────────────────────────────────

function RoutesTab({ domain }: { domain: EmailDomain }) {
  const [routes, setRoutes] = useState<Route[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showAdd, setShowAdd] = useState(false);
  const [newExpr, setNewExpr] = useState(`match_recipient('.*@${domain.name}')`);
  const [newAction, setNewAction] = useState("");
  const [newDesc, setNewDesc] = useState("");
  const [isSaving, setIsSaving] = useState(false);

  const fetchRoutes = useCallback(async () => {
    setIsLoading(true);
    try {
      const res = await api.get<{ routes: Route[] }>(`/email/domains/${domain.id}/mailgun/routes`);
      setRoutes(res.data.routes ?? []);
    } catch {
      toast.error("Failed to load routes");
    } finally {
      setIsLoading(false);
    }
  }, [domain.id]);

  useEffect(() => { void fetchRoutes(); }, [fetchRoutes]);

  const createRoute = async () => {
    setIsSaving(true);
    try {
      await api.post(`/email/domains/${domain.id}/mailgun/routes`, {
        expression: newExpr,
        actions: newAction.split("\n").map((a) => a.trim()).filter(Boolean),
        description: newDesc,
      });
      toast.success("Route created");
      setShowAdd(false);
      void fetchRoutes();
    } catch {
      toast.error("Failed to create route");
    } finally {
      setIsSaving(false);
    }
  };

  const deleteRoute = async (routeId: string) => {
    try {
      await api.delete(`/email/domains/${domain.id}/mailgun/routes/${routeId}`);
      toast.success("Route deleted");
      void fetchRoutes();
    } catch {
      toast.error("Failed to delete route");
    }
  };

  if (isLoading) return <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-muted-foreground">
          Mailgun routing rules for this domain. selfmx auto-creates a catch-all forward route on domain setup.
        </p>
        <Button size="sm" onClick={() => setShowAdd(true)}>
          <Plus className="mr-2 h-3 w-3" />
          Add Route
        </Button>
      </div>

      {routes.length === 0 ? (
        <p className="text-sm text-muted-foreground">No routes found for this domain.</p>
      ) : (
        <div className="rounded-md border divide-y text-sm">
          {routes.map((route) => (
            <div key={route.id} className="flex items-start justify-between gap-4 px-4 py-3">
              <div className="min-w-0 space-y-1">
                <p className="font-mono text-xs">{route.expression}</p>
                {route.description && <p className="text-xs text-muted-foreground">{route.description}</p>}
                <div className="flex flex-wrap gap-1">
                  {(route.actions ?? []).map((a, i) => (
                    <span key={i} className="font-mono text-xs bg-muted rounded px-1 py-0.5">{a}</span>
                  ))}
                </div>
              </div>
              <div className="flex items-center gap-2 shrink-0">
                <Badge variant="outline" className="text-xs">priority {route.priority}</Badge>
                <Button variant="ghost" size="icon" className="h-7 w-7" onClick={() => deleteRoute(route.id)}>
                  <Trash2 className="h-3 w-3 text-destructive" />
                </Button>
              </div>
            </div>
          ))}
        </div>
      )}

      <Dialog open={showAdd} onOpenChange={setShowAdd}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Add Inbound Route</DialogTitle>
            <DialogDescription>Create a Mailgun routing rule for this domain.</DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            <div className="space-y-1">
              <Label>Filter Expression</Label>
              <Input value={newExpr} onChange={(e) => setNewExpr(e.target.value)} placeholder="match_recipient('.*@example.com')" />
            </div>
            <div className="space-y-1">
              <Label>Actions (one per line)</Label>
              <textarea
                className="w-full rounded-md border bg-background px-3 py-2 text-sm font-mono min-h-[80px] resize-y"
                value={newAction}
                onChange={(e) => setNewAction(e.target.value)}
                placeholder={"forward('https://...')\nstop()"}
              />
            </div>
            <div className="space-y-1">
              <Label>Description (optional)</Label>
              <Input value={newDesc} onChange={(e) => setNewDesc(e.target.value)} placeholder="Catch-all forward" />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowAdd(false)}>Cancel</Button>
            <Button onClick={createRoute} disabled={isSaving || !newExpr}>
              {isSaving && <Loader2 className="mr-2 h-3 w-3 animate-spin" />}
              Create Route
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

// ─── Tab: Event Log ───────────────────────────────────────────────────────────

const EVENT_TYPES = ["delivered", "opened", "clicked", "bounced", "failed", "complained", "accepted", "unsubscribed"];

function EventLogTab({ domain }: { domain: EmailDomain }) {
  const [items, setItems] = useState<EventItem[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [nextPage, setNextPage] = useState<string | null>(null);
  const [filterEvent, setFilterEvent] = useState("all");
  const [filterRecipient, setFilterRecipient] = useState("");

  const fetchEvents = useCallback(async (page?: string) => {
    setIsLoading(true);
    try {
      const params: Record<string, string> = { limit: "25" };
      if (filterEvent !== "all") params.event = filterEvent;
      if (filterRecipient) params.recipient = filterRecipient;
      if (page) params.page = page;

      const res = await api.get<{ items: EventItem[]; nextPage: string | null }>(
        `/email/domains/${domain.id}/mailgun/events`,
        { params }
      );
      setItems(page ? (prev) => [...prev, ...(res.data.items ?? [])] : (res.data.items ?? []));
      setNextPage(res.data.nextPage ?? null);
    } catch {
      toast.error("Failed to load events");
    } finally {
      setIsLoading(false);
    }
  }, [domain.id, filterEvent, filterRecipient]);

  useEffect(() => { void fetchEvents(); }, [fetchEvents]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <Select value={filterEvent} onValueChange={(v) => setFilterEvent(v)}>
          <SelectTrigger className="w-36 h-8 text-xs">
            <SelectValue placeholder="All events" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All events</SelectItem>
            {EVENT_TYPES.map((e) => <SelectItem key={e} value={e}>{e}</SelectItem>)}
          </SelectContent>
        </Select>
        <Input
          className="h-8 text-xs w-52"
          placeholder="Filter by recipient"
          value={filterRecipient}
          onChange={(e) => setFilterRecipient(e.target.value)}
          onKeyDown={(e) => e.key === "Enter" && fetchEvents()}
        />
        <Button size="sm" variant="outline" onClick={() => fetchEvents()}>
          <RefreshCw className="h-3 w-3" />
        </Button>
      </div>

      {isLoading && items.length === 0 ? (
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      ) : items.length === 0 ? (
        <p className="text-sm text-muted-foreground">No events found.</p>
      ) : (
        <>
          <div className="rounded-md border overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Time</TableHead>
                  <TableHead>Event</TableHead>
                  <TableHead>Recipient</TableHead>
                  <TableHead>Subject</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((item, i) => (
                  <TableRow key={i}>
                    <TableCell className="text-xs text-muted-foreground whitespace-nowrap">{formatTs(item.timestamp)}</TableCell>
                    <TableCell><EventBadge event={item.event} /></TableCell>
                    <TableCell className="text-xs max-w-[180px] truncate">{item.recipient ?? "—"}</TableCell>
                    <TableCell className="text-xs max-w-[200px] truncate">{item.message?.headers?.subject ?? "—"}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          {nextPage && (
            <Button variant="outline" size="sm" onClick={() => fetchEvents(nextPage)} disabled={isLoading}>
              {isLoading ? <Loader2 className="mr-2 h-3 w-3 animate-spin" /> : <ChevronRight className="mr-2 h-3 w-3" />}
              Load more
            </Button>
          )}
        </>
      )}
    </div>
  );
}

// ─── Tab: Suppressions ────────────────────────────────────────────────────────

type SuppressionType = "bounces" | "complaints" | "unsubscribes";

function SuppressionsTab({ domain }: { domain: EmailDomain }) {
  const [subTab, setSubTab] = useState<SuppressionType>("bounces");
  const [items, setItems] = useState<SuppressionItem[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [nextPage, setNextPage] = useState<string | null>(null);
  const [isImporting, setIsImporting] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const fetchItems = useCallback(async (type: SuppressionType, page?: string) => {
    setIsLoading(true);
    try {
      const params: Record<string, string> = { limit: "25" };
      if (page) params.page = page;
      const res = await api.get<{ items: SuppressionItem[]; nextPage: string | null }>(
        `/email/domains/${domain.id}/mailgun/suppressions/${type}`,
        { params }
      );
      setItems(page ? (prev) => [...prev, ...(res.data.items ?? [])] : (res.data.items ?? []));
      setNextPage(res.data.nextPage ?? null);
    } catch {
      toast.error(`Failed to load ${type}`);
    } finally {
      setIsLoading(false);
    }
  }, [domain.id]);

  useEffect(() => {
    setItems([]);
    setNextPage(null);
    void fetchItems(subTab);
  }, [subTab, fetchItems]);

  const deleteItem = async (address: string) => {
    try {
      await api.delete(`/email/domains/${domain.id}/mailgun/suppressions/${subTab}/${encodeURIComponent(address)}`);
      toast.success(`Removed ${address}`);
      void fetchItems(subTab);
    } catch {
      toast.error("Failed to remove entry");
    }
  };

  const handleExport = async () => {
    try {
      const res = await api.get(
        `/email/domains/${domain.id}/mailgun/suppressions/${subTab}/export`,
        { responseType: "blob" }
      );
      const url = URL.createObjectURL(res.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `${domain.name}_${subTab}_${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch {
      toast.error("Export failed");
    }
  };

  const handleImport = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setIsImporting(true);
    try {
      const formData = new FormData();
      formData.append("file", file);
      const res = await api.post<{ imported: number }>(
        `/email/domains/${domain.id}/mailgun/suppressions/${subTab}/import`,
        formData,
        { headers: { "Content-Type": "multipart/form-data" } }
      );
      toast.success(`Imported ${res.data.imported} entries`);
      void fetchItems(subTab);
    } catch {
      toast.error("Import failed");
    } finally {
      setIsImporting(false);
      if (fileInputRef.current) fileInputRef.current.value = "";
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between flex-wrap gap-2">
        <div className="flex gap-1">
          {(["bounces", "complaints", "unsubscribes"] as SuppressionType[]).map((t) => (
            <Button key={t} variant={subTab === t ? "secondary" : "outline"} size="sm" onClick={() => setSubTab(t)}>
              {t.charAt(0).toUpperCase() + t.slice(1)}
            </Button>
          ))}
        </div>
        <div className="flex gap-1">
          <Button variant="outline" size="sm" onClick={handleExport}>
            <Download className="mr-1 h-3 w-3" /> Export
          </Button>
          <Button variant="outline" size="sm" onClick={() => fileInputRef.current?.click()} disabled={isImporting}>
            {isImporting ? <Loader2 className="mr-1 h-3 w-3 animate-spin" /> : <Upload className="mr-1 h-3 w-3" />}
            Import
          </Button>
          <input ref={fileInputRef} type="file" accept=".csv,.txt" className="hidden" onChange={handleImport} aria-label="Import suppressions CSV" />
        </div>
      </div>

      {isLoading && items.length === 0 ? (
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      ) : items.length === 0 ? (
        <p className="text-sm text-muted-foreground">No {subTab} found.</p>
      ) : (
        <>
          <div className="rounded-md border overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Address</TableHead>
                  {subTab === "bounces" && <TableHead>Reason</TableHead>}
                  {subTab === "unsubscribes" && <TableHead>Tag</TableHead>}
                  <TableHead>Created</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((item, i) => (
                  <TableRow key={i}>
                    <TableCell className="text-xs font-mono">{item.address}</TableCell>
                    {subTab === "bounces" && (
                      <TableCell className="text-xs text-muted-foreground max-w-[200px] truncate">{item.error ?? "—"}</TableCell>
                    )}
                    {subTab === "unsubscribes" && (
                      <TableCell className="text-xs text-muted-foreground">{item.tag ?? "—"}</TableCell>
                    )}
                    <TableCell className="text-xs text-muted-foreground whitespace-nowrap">{formatTs(item.created_at)}</TableCell>
                    <TableCell>
                      <Button variant="ghost" size="icon" className="h-7 w-7" onClick={() => deleteItem(item.address)}>
                        <Trash2 className="h-3 w-3 text-destructive" />
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          {nextPage && (
            <Button variant="outline" size="sm" onClick={() => fetchItems(subTab, nextPage)} disabled={isLoading}>
              {isLoading ? <Loader2 className="mr-2 h-3 w-3 animate-spin" /> : <ChevronRight className="mr-2 h-3 w-3" />}
              Load more
            </Button>
          )}
        </>
      )}
    </div>
  );
}

// ─── Tab: Tracking ────────────────────────────────────────────────────────────

function TrackingTab({ domain }: { domain: EmailDomain }) {
  const [tracking, setTracking] = useState<TrackingSettings | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [saving, setSaving] = useState<string | null>(null);

  useEffect(() => {
    api.get<TrackingSettings>(`/email/domains/${domain.id}/mailgun/tracking`)
      .then((res) => setTracking(res.data))
      .catch(() => toast.error("Failed to load tracking settings"))
      .finally(() => setIsLoading(false));
  }, [domain.id]);

  const toggle = async (type: "click" | "open" | "unsubscribe", active: boolean) => {
    setSaving(type);
    try {
      await api.put(`/email/domains/${domain.id}/mailgun/tracking/${type}`, { active });
      // Re-fetch tracking state to confirm the change persisted
      const res = await api.get<TrackingSettings>(`/email/domains/${domain.id}/mailgun/tracking`);
      setTracking(res.data);
      const actualState = res.data[type]?.active ?? false;
      if (actualState !== active) {
        toast.error(`${type.charAt(0).toUpperCase() + type.slice(1)} tracking could not be ${active ? "enabled" : "disabled"}. The domain may need to be verified first.`);
      } else {
        toast.success(`${type.charAt(0).toUpperCase() + type.slice(1)} tracking ${active ? "enabled" : "disabled"}`);
      }
    } catch {
      toast.error("Failed to update tracking setting");
    } finally {
      setSaving(null);
    }
  };

  if (isLoading) return <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />;
  if (!tracking) return <p className="text-sm text-muted-foreground">Tracking settings unavailable.</p>;

  const rows: { key: "click" | "open" | "unsubscribe"; label: string; description: string }[] = [
    { key: "open", label: "Open Tracking", description: "Track when recipients open emails (inserts a tracking pixel)." },
    { key: "click", label: "Click Tracking", description: "Track when recipients click links in emails." },
    { key: "unsubscribe", label: "Unsubscribe Tracking", description: "Insert unsubscribe links and track unsubscribes." },
  ];

  return (
    <div className="space-y-1 divide-y rounded-md border text-sm">
      {rows.map(({ key, label, description }) => (
        <div key={key} className="flex items-center justify-between px-4 py-4">
          <div>
            <p className="font-medium">{label}</p>
            <p className="text-xs text-muted-foreground mt-0.5">{description}</p>
          </div>
          <Switch
            checked={tracking[key]?.active ?? false}
            disabled={saving === key}
            onCheckedChange={(v) => toggle(key, v)}
          />
        </div>
      ))}
    </div>
  );
}

// ─── Tab: Stats ───────────────────────────────────────────────────────────────

function StatsTab({ domain }: { domain: EmailDomain }) {
  const [stats, setStats] = useState<StatsPoint[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [duration, setDuration] = useState("30d");

  const fetchStats = useCallback(async (dur: string) => {
    setIsLoading(true);
    try {
      const res = await api.get<{ stats: StatsPoint[] }>(`/email/domains/${domain.id}/mailgun/stats`, {
        params: { duration: dur, resolution: "day" },
      });
      setStats(res.data.stats ?? []);
    } catch {
      toast.error("Failed to load stats");
    } finally {
      setIsLoading(false);
    }
  }, [domain.id]);

  useEffect(() => { void fetchStats(duration); }, [fetchStats, duration]);

  // Aggregate totals
  const totals = stats.reduce(
    (acc, s) => ({
      accepted: acc.accepted + (s.accepted?.total ?? 0),
      delivered: acc.delivered + (s.delivered?.total ?? 0),
      failed: acc.failed + ((s.failed?.permanent?.total ?? 0) + (s.failed?.temporary?.total ?? 0)),
      complained: acc.complained + (s.complained?.total ?? 0),
    }),
    { accepted: 0, delivered: 0, failed: 0, complained: 0 }
  );

  const deliveryRate = totals.accepted > 0 ? ((totals.delivered / totals.accepted) * 100).toFixed(1) : "—";
  const bounceRate = totals.accepted > 0 ? ((totals.failed / totals.accepted) * 100).toFixed(1) : "—";
  const complaintRate = totals.delivered > 0 ? ((totals.complained / totals.delivered) * 100).toFixed(2) : "—";

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        {(["7d", "30d", "90d"] as const).map((d) => (
          <Button key={d} size="sm" variant={duration === d ? "secondary" : "outline"} onClick={() => { setDuration(d); fetchStats(d); }}>
            {d}
          </Button>
        ))}
        {isLoading && <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />}
      </div>

      <div className="grid grid-cols-3 gap-4">
        {[
          { label: "Delivery Rate", value: `${deliveryRate}%`, desc: `${totals.delivered} of ${totals.accepted} delivered` },
          { label: "Bounce Rate", value: `${bounceRate}%`, desc: `${totals.failed} bounced` },
          { label: "Complaint Rate", value: `${complaintRate}%`, desc: `${totals.complained} complaints` },
        ].map(({ label, value, desc }) => (
          <div key={label} className="rounded-md border p-4 space-y-1">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="text-2xl font-semibold">{value}</p>
            <p className="text-xs text-muted-foreground">{desc}</p>
          </div>
        ))}
      </div>

      {stats.length > 0 && (
        <div className="rounded-md border overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Date</TableHead>
                <TableHead className="text-right">Accepted</TableHead>
                <TableHead className="text-right">Delivered</TableHead>
                <TableHead className="text-right">Failed</TableHead>
                <TableHead className="text-right">Complaints</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {[...stats].reverse().map((s, i) => (
                <TableRow key={i}>
                  <TableCell className="text-xs">{new Date(s.time).toLocaleDateString()}</TableCell>
                  <TableCell className="text-xs text-right">{s.accepted?.total ?? 0}</TableCell>
                  <TableCell className="text-xs text-right">{s.delivered?.total ?? 0}</TableCell>
                  <TableCell className="text-xs text-right">{(s.failed?.permanent?.total ?? 0) + (s.failed?.temporary?.total ?? 0)}</TableCell>
                  <TableCell className="text-xs text-right">{s.complained?.total ?? 0}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
    </div>
  );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function DomainDetailPage() {
  const params = useParams();
  const router = useRouter();
  const domainId = params.id as string;

  const [domain, setDomain] = useState<EmailDomain | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    api.get<{ domain: EmailDomain }>(`/email/domains/${domainId}`)
      .then((res) => setDomain(res.data.domain))
      .catch(() => { toast.error("Domain not found"); router.push("/configuration/email-domains"); })
      .finally(() => setIsLoading(false));
  }, [domainId, router]);

  if (isLoading) return <SettingsPageSkeleton />;
  if (!domain) return null;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between gap-4">
        <div className="space-y-1">
          <Link href="/configuration/email-domains" className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground mb-2">
            <ChevronLeft className="h-4 w-4" />
            Email Domains
          </Link>
          <h1 className="text-2xl font-bold tracking-tight">{domain.name}</h1>
          <div className="flex items-center gap-2">
            {domain.is_verified ? (
              <Badge className="bg-green-600">Verified</Badge>
            ) : (
              <Badge variant="secondary" className="flex items-center gap-1">
                <AlertTriangle className="h-3 w-3" />
                Unverified
              </Badge>
            )}
            <Badge variant="outline">Mailgun</Badge>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <Tabs defaultValue="dns">
        <TabsList className="flex-wrap h-auto">
          <TabsTrigger value="dns">DNS Records</TabsTrigger>
          <TabsTrigger value="dkim">DKIM</TabsTrigger>
          <TabsTrigger value="webhooks">Webhooks</TabsTrigger>
          <TabsTrigger value="routes">Inbound Routes</TabsTrigger>
          <TabsTrigger value="events">Event Log</TabsTrigger>
          <TabsTrigger value="suppressions">Suppressions</TabsTrigger>
          <TabsTrigger value="tracking">Tracking</TabsTrigger>
          <TabsTrigger value="stats">Stats</TabsTrigger>
        </TabsList>

        <Card className="mt-4">
          <CardContent className="pt-6">
            <TabsContent value="dns" className="mt-0"><DnsTab domain={domain} /></TabsContent>
            <TabsContent value="dkim" className="mt-0"><DkimTab domain={domain} /></TabsContent>
            <TabsContent value="webhooks" className="mt-0"><WebhooksTab domain={domain} /></TabsContent>
            <TabsContent value="routes" className="mt-0"><RoutesTab domain={domain} /></TabsContent>
            <TabsContent value="events" className="mt-0"><EventLogTab domain={domain} /></TabsContent>
            <TabsContent value="suppressions" className="mt-0"><SuppressionsTab domain={domain} /></TabsContent>
            <TabsContent value="tracking" className="mt-0"><TrackingTab domain={domain} /></TabsContent>
            <TabsContent value="stats" className="mt-0"><StatsTab domain={domain} /></TabsContent>
          </CardContent>
        </Card>
      </Tabs>
    </div>
  );
}
