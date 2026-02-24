"use client";

import { useState, useEffect, useCallback, useRef } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { api } from "@/lib/api";
import { getErrorMessage } from "@/lib/utils";
import { usePermission } from "@/lib/use-permission";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { Badge } from "@/components/ui/badge";
import { Label } from "@/components/ui/label";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { CollapsibleCard } from "@/components/ui/collapsible-card";
import { FormField } from "@/components/ui/form-field";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { SaveButton } from "@/components/ui/save-button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
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
import { HelpLink } from "@/components/help/help-link";
import {
  Area,
  AreaChart,
  CartesianGrid,
  XAxis,
  YAxis,
} from "recharts";
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
} from "@/components/ui/chart";
import type { ChartConfig } from "@/components/ui/chart";
import {
  Settings2,
  Key,
  BarChart3,
  ExternalLink,
} from "lucide-react";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface GraphQLSettings {
  enabled: boolean;
  max_keys_per_user: number;
  default_rate_limit: number;
  introspection_enabled: boolean;
  max_query_depth: number;
  max_query_complexity: number;
  max_result_size: number;
  key_rotation_grace_days: number;
  cors_allowed_origins: string;
}

interface ApiKeyRecord {
  id: number;
  user: { id: number; name: string; email: string } | null;
  name: string;
  key_prefix: string;
  created_at: string;
  last_used_at: string | null;
  expires_at: string | null;
  revoked_at: string | null;
  status: string;
}

interface ApiKeyStatsData {
  total: number;
  active: number;
  expiring_soon: number;
  never_used: number;
}

interface UsageStatsData {
  total_7d: number;
  total_30d: number;
  daily: Record<string, number>;
  top_users: { user_id: number; name: string; email: string | null; total_requests: number }[];
  top_queries: { query_name: string; total_requests: number }[];
}

interface PaginatedApiKeys {
  data: ApiKeyRecord[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

const graphqlSettingsSchema = z.object({
  enabled: z.boolean(),
  max_keys_per_user: z.coerce.number().int().min(1).max(100),
  default_rate_limit: z.coerce.number().int().min(1).max(10000),
  introspection_enabled: z.boolean(),
  max_query_depth: z.coerce.number().int().min(1).max(50),
  max_query_complexity: z.coerce.number().int().min(1).max(10000),
  max_result_size: z.coerce.number().int().min(1).max(1000),
  key_rotation_grace_days: z.coerce.number().int().min(0).max(90),
  cors_allowed_origins: z.string().optional(),
});

type GraphQLForm = z.infer<typeof graphqlSettingsSchema>;

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const KEY_STATUS_VARIANTS: Record<string, string> = {
  active: "bg-green-500/10 text-green-600 dark:text-green-400 border-green-500/20",
  expired: "bg-muted text-muted-foreground border-muted",
  revoked: "bg-red-500/10 text-red-600 dark:text-red-400 border-red-500/20",
  expiring_soon: "bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20",
  deleted: "bg-muted text-muted-foreground border-muted",
};

const KEY_STATUS_LABELS: Record<string, string> = {
  active: "Active",
  expired: "Expired",
  revoked: "Revoked",
  expiring_soon: "Expiring Soon",
  deleted: "Deleted",
};

const CHART_CONFIG: ChartConfig = {
  count: { label: "Requests", color: "hsl(217 91% 60%)" },
};

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function parseLocalDate(dateStr: string): Date {
  const [y, m, d] = dateStr.split("-").map(Number);
  return new Date(y, m - 1, d);
}

function formatChartDate(dateStr: string) {
  return parseLocalDate(dateStr).toLocaleDateString(undefined, {
    month: "short",
    day: "numeric",
  });
}

function formatRelativeTime(dateStr: string | null): string {
  if (!dateStr) return "Never";
  const date = new Date(dateStr);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffMins = Math.floor(diffMs / 60000);
  if (diffMins < 1) return "Just now";
  if (diffMins < 60) return `${diffMins}m ago`;
  const diffHours = Math.floor(diffMins / 60);
  if (diffHours < 24) return `${diffHours}h ago`;
  const diffDays = Math.floor(diffHours / 24);
  if (diffDays < 30) return `${diffDays}d ago`;
  return date.toLocaleDateString();
}

// ---------------------------------------------------------------------------
// Settings Section
// ---------------------------------------------------------------------------

function SettingsSection() {
  const queryClient = useQueryClient();
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);

  const {
    register,
    handleSubmit,
    formState: { errors, isDirty },
    setValue,
    watch,
    reset,
  } = useForm<GraphQLForm>({
    resolver: zodResolver(graphqlSettingsSchema),
    mode: "onBlur",
    defaultValues: {
      enabled: false,
      max_keys_per_user: 5,
      default_rate_limit: 60,
      introspection_enabled: false,
      max_query_depth: 12,
      max_query_complexity: 200,
      max_result_size: 100,
      key_rotation_grace_days: 7,
      cors_allowed_origins: "*",
    },
  });

  useEffect(() => {
    (async () => {
      try {
        const res = await api.get<{ settings: GraphQLSettings }>("/graphql/settings");
        const s = res.data.settings;
        reset({
          enabled: !!s.enabled,
          max_keys_per_user: s.max_keys_per_user ?? 5,
          default_rate_limit: s.default_rate_limit ?? 60,
          introspection_enabled: !!s.introspection_enabled,
          max_query_depth: s.max_query_depth ?? 12,
          max_query_complexity: s.max_query_complexity ?? 200,
          max_result_size: s.max_result_size ?? 100,
          key_rotation_grace_days: s.key_rotation_grace_days ?? 7,
          cors_allowed_origins: s.cors_allowed_origins ?? "*",
        });
      } catch {
        toast.error("Failed to load GraphQL settings");
      } finally {
        setIsLoading(false);
      }
    })();
  }, [reset]);

  const onSave = useCallback(
    async (data: GraphQLForm) => {
      setIsSaving(true);
      try {
        await api.put("/graphql/settings", data);
        toast.success("GraphQL settings saved");
        reset(data);
        // Invalidate app-config cache so graphqlEnabled feature flag updates immediately
        queryClient.invalidateQueries({ queryKey: ["app-config"] });
      } catch (err: unknown) {
        toast.error(getErrorMessage(err, "Failed to save GraphQL settings"));
      } finally {
        setIsSaving(false);
      }
    },
    [reset]
  );

  if (isLoading) return <SettingsPageSkeleton />;

  return (
    <form onSubmit={handleSubmit(onSave)}>
      <CollapsibleCard
        title="Settings"
        description="GraphQL API configuration"
        icon={<Settings2 className="h-4 w-4" />}
        status={{
          label: watch("enabled") ? "Enabled" : "Disabled",
          variant: watch("enabled") ? "success" : "default",
        }}
        defaultOpen
      >
        <div className="space-y-6">
          {/* Enable toggle */}
          <div className="flex items-center justify-between">
            <div className="space-y-0.5">
              <label className="text-sm font-medium">Enable GraphQL API</label>
              <p className="text-sm text-muted-foreground">
                When disabled, all GraphQL routes return 404 and API keys are hidden from user preferences
              </p>
            </div>
            <Switch
              checked={watch("enabled")}
              onCheckedChange={(checked) =>
                setValue("enabled", checked, { shouldDirty: true })
              }
            />
          </div>

          {/* Core settings grid */}
          <div className="grid gap-4 md:grid-cols-2">
            <FormField
              id="max_keys_per_user"
              label="Max API Keys Per User"
              description="Maximum number of active keys each user can create"
              error={errors.max_keys_per_user?.message}
            >
              <Input
                id="max_keys_per_user"
                type="number"
                min={1}
                max={100}
                {...register("max_keys_per_user")}
                className="min-h-[44px]"
              />
            </FormField>
            <FormField
              id="default_rate_limit"
              label="Default Rate Limit"
              description="Requests per minute per API key"
              error={errors.default_rate_limit?.message}
            >
              <Input
                id="default_rate_limit"
                type="number"
                min={1}
                max={10000}
                {...register("default_rate_limit")}
                className="min-h-[44px]"
              />
            </FormField>
          </div>

          {/* Introspection toggle */}
          <div className="flex items-center justify-between">
            <div className="space-y-0.5">
              <label className="text-sm font-medium">Allow Introspection</label>
              <p className="text-sm text-muted-foreground">
                Enables schema exploration for developer tools. Disable in production for security.
              </p>
            </div>
            <Switch
              checked={watch("introspection_enabled")}
              onCheckedChange={(checked) =>
                setValue("introspection_enabled", checked, { shouldDirty: true })
              }
            />
          </div>

          {/* Query security settings */}
          <div className="grid gap-4 md:grid-cols-3">
            <FormField
              id="max_query_depth"
              label="Max Query Depth"
              error={errors.max_query_depth?.message}
            >
              <Input
                id="max_query_depth"
                type="number"
                min={1}
                max={50}
                {...register("max_query_depth")}
                className="min-h-[44px]"
              />
            </FormField>
            <FormField
              id="max_query_complexity"
              label="Max Query Complexity"
              error={errors.max_query_complexity?.message}
            >
              <Input
                id="max_query_complexity"
                type="number"
                min={1}
                max={10000}
                {...register("max_query_complexity")}
                className="min-h-[44px]"
              />
            </FormField>
            <FormField
              id="max_result_size"
              label="Max Result Size"
              description="Max items per query"
              error={errors.max_result_size?.message}
            >
              <Input
                id="max_result_size"
                type="number"
                min={1}
                max={1000}
                {...register("max_result_size")}
                className="min-h-[44px]"
              />
            </FormField>
          </div>

          {/* Rotation + CORS */}
          <div className="grid gap-4 md:grid-cols-2">
            <FormField
              id="key_rotation_grace_days"
              label="Key Rotation Grace Period"
              description="Days old key stays valid after rotation"
              error={errors.key_rotation_grace_days?.message}
            >
              <Input
                id="key_rotation_grace_days"
                type="number"
                min={0}
                max={90}
                {...register("key_rotation_grace_days")}
                className="min-h-[44px]"
              />
            </FormField>
            <FormField
              id="cors_allowed_origins"
              label="CORS Allowed Origins"
              description="Comma-separated origins, or * for any"
              error={errors.cors_allowed_origins?.message}
            >
              <Input
                id="cors_allowed_origins"
                placeholder="*"
                {...register("cors_allowed_origins")}
                className="min-h-[44px]"
              />
            </FormField>
          </div>

          <div className="flex justify-end">
            <SaveButton isDirty={isDirty} isSaving={isSaving} />
          </div>
        </div>
      </CollapsibleCard>
    </form>
  );
}

// ---------------------------------------------------------------------------
// API Keys Section
// ---------------------------------------------------------------------------

function ApiKeysSection() {
  const [keys, setKeys] = useState<ApiKeyRecord[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);
  const [stats, setStats] = useState<ApiKeyStatsData | null>(null);
  const [filters, setFilters] = useState({ user: "", status: "", expiring_soon: "" });
  const [debouncedUser, setDebouncedUser] = useState("");
  const userFilterTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const fetchKeys = useCallback(async () => {
    setIsLoading(true);
    try {
      const params = new URLSearchParams({
        page: currentPage.toString(),
        per_page: "50",
      });
      if (filters.status) params.append("status", filters.status);
      if (debouncedUser) params.append("user", debouncedUser);
      if (filters.expiring_soon === "true") params.append("expiring_soon", "true");

      const response = await api.get<PaginatedApiKeys>(
        `/graphql/admin/api-keys?${params.toString()}`
      );
      setKeys(response.data.data || []);
      setTotalPages(response.data.last_page || 1);
      setTotal(response.data.total || 0);
    } catch {
      toast.error("Failed to load API keys");
      setKeys([]);
    } finally {
      setIsLoading(false);
    }
  }, [currentPage, filters.status, filters.expiring_soon, debouncedUser]);

  const fetchStats = useCallback(async () => {
    try {
      const res = await api.get<{ data: ApiKeyStatsData }>("/graphql/admin/api-keys/stats");
      setStats(res.data.data);
    } catch {
      // Stats are optional
    }
  }, []);

  useEffect(() => { fetchKeys(); }, [fetchKeys]);
  useEffect(() => { fetchStats(); }, [fetchStats]);

  const handleFilterChange = (key: string, value: string) => {
    if (key === "user") {
      setFilters((prev) => ({ ...prev, [key]: value }));
      if (userFilterTimer.current) clearTimeout(userFilterTimer.current);
      userFilterTimer.current = setTimeout(() => {
        setDebouncedUser(value);
        setCurrentPage(1);
      }, 300);
      return;
    }
    setFilters((prev) => ({ ...prev, [key]: value }));
    setCurrentPage(1);
  };

  const clearFilters = () => {
    setFilters({ user: "", status: "", expiring_soon: "" });
    setDebouncedUser("");
    setCurrentPage(1);
  };

  const hasActiveFilters = Object.values(filters).some(Boolean);

  const handleRevoke = async (keyId: number) => {
    try {
      await api.delete(`/graphql/admin/api-keys/${keyId}`);
      toast.success("API key revoked");
      fetchKeys();
      fetchStats();
    } catch (err: unknown) {
      toast.error(getErrorMessage(err, "Failed to revoke API key"));
    }
  };

  return (
    <CollapsibleCard
      title="API Keys"
      description="All API keys across all users"
      icon={<Key className="h-4 w-4" />}
      status={stats ? { label: `${stats.active} active`, variant: "default" as const } : undefined}
    >
      <div className="space-y-6">
        {/* Stats cards */}
        {stats && (
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            {([
              { label: "Total Keys", value: stats.total },
              { label: "Active", value: stats.active },
              { label: "Expiring Soon", value: stats.expiring_soon },
              { label: "Never Used", value: stats.never_used },
            ] as const).map((item) => (
              <Card key={item.label}>
                <CardHeader className="pb-2">
                  <CardDescription>{item.label}</CardDescription>
                </CardHeader>
                <CardContent>
                  <p className="text-2xl font-bold">{item.value}</p>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

        {/* Filters */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Filters</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div className="space-y-1.5">
                <Label htmlFor="filter-user">User</Label>
                <Input
                  id="filter-user"
                  placeholder="Search by name or email"
                  value={filters.user}
                  onChange={(e) => handleFilterChange("user", e.target.value)}
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="filter-status">Status</Label>
                <Select
                  value={filters.status}
                  onValueChange={(v) => handleFilterChange("status", v === "all" ? "" : v)}
                >
                  <SelectTrigger id="filter-status">
                    <SelectValue placeholder="All statuses" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All statuses</SelectItem>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="expired">Expired</SelectItem>
                    <SelectItem value="revoked">Revoked</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="filter-expiring">Expiration</Label>
                <Select
                  value={filters.expiring_soon}
                  onValueChange={(v) => handleFilterChange("expiring_soon", v === "all" ? "" : v)}
                >
                  <SelectTrigger id="filter-expiring">
                    <SelectValue placeholder="All" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All</SelectItem>
                    <SelectItem value="true">Expiring within 7 days</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
            {hasActiveFilters && (
              <Button variant="ghost" size="sm" className="mt-3" onClick={clearFilters}>
                Clear filters
              </Button>
            )}
          </CardContent>
        </Card>

        {/* Table */}
        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <CardTitle className="text-base">
                API Keys
                {!isLoading && (
                  <span className="text-muted-foreground font-normal ml-2">({total})</span>
                )}
              </CardTitle>
            </div>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="space-y-3">
                {Array.from({ length: 8 }).map((_, i) => (
                  <Skeleton key={i} className="h-10 w-full" />
                ))}
              </div>
            ) : keys.length === 0 ? (
              <p className="text-center text-muted-foreground py-8">
                No API keys found.
              </p>
            ) : (
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>User</TableHead>
                      <TableHead>Name</TableHead>
                      <TableHead>Prefix</TableHead>
                      <TableHead>Created</TableHead>
                      <TableHead>Last Used</TableHead>
                      <TableHead>Expires</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead>Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {keys.map((key) => (
                      <TableRow key={key.id}>
                        <TableCell className="text-sm">
                          {key.user?.name ?? "Unknown user"}
                        </TableCell>
                        <TableCell className="text-sm">{key.name}</TableCell>
                        <TableCell className="text-sm font-mono">{key.key_prefix}...</TableCell>
                        <TableCell className="text-sm whitespace-nowrap">
                          {key.created_at ? new Date(key.created_at).toLocaleDateString() : "—"}
                        </TableCell>
                        <TableCell className="text-sm whitespace-nowrap">
                          {formatRelativeTime(key.last_used_at)}
                        </TableCell>
                        <TableCell className="text-sm whitespace-nowrap">
                          {key.expires_at ? new Date(key.expires_at).toLocaleDateString() : "Never"}
                        </TableCell>
                        <TableCell>
                          <Badge
                            variant="outline"
                            className={KEY_STATUS_VARIANTS[key.status] ?? ""}
                          >
                            {KEY_STATUS_LABELS[key.status] ?? key.status}
                          </Badge>
                        </TableCell>
                        <TableCell>
                          {key.status === "active" || key.status === "expiring_soon" ? (
                            <AlertDialog>
                              <AlertDialogTrigger asChild>
                                <Button variant="ghost" size="sm" className="text-destructive hover:text-destructive">
                                  Revoke
                                </Button>
                              </AlertDialogTrigger>
                              <AlertDialogContent>
                                <AlertDialogHeader>
                                  <AlertDialogTitle>Revoke API Key?</AlertDialogTitle>
                                  <AlertDialogDescription>
                                    This will revoke the key &ldquo;{key.name}&rdquo;
                                    {key.user?.name ? ` belonging to ${key.user.name}` : ""}.
                                    The key will immediately stop working.
                                  </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                  <AlertDialogCancel>Cancel</AlertDialogCancel>
                                  <AlertDialogAction onClick={() => handleRevoke(key.id)}>
                                    Revoke
                                  </AlertDialogAction>
                                </AlertDialogFooter>
                              </AlertDialogContent>
                            </AlertDialog>
                          ) : (
                            <span className="text-muted-foreground text-sm">—</span>
                          )}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}

            {/* Pagination */}
            {totalPages > 1 && !isLoading && (
              <div className="flex items-center justify-center gap-2 mt-4">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                  disabled={currentPage <= 1}
                >
                  Previous
                </Button>
                <span className="text-sm text-muted-foreground px-2">
                  Page {currentPage} of {totalPages}
                </span>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                  disabled={currentPage >= totalPages}
                >
                  Next
                </Button>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </CollapsibleCard>
  );
}

// ---------------------------------------------------------------------------
// Usage Stats Section
// ---------------------------------------------------------------------------

function UsageStatsSection() {
  const [stats, setStats] = useState<UsageStatsData | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    (async () => {
      try {
        const res = await api.get<{ data: UsageStatsData }>("/graphql/admin/usage-stats");
        setStats(res.data.data);
      } catch {
        toast.error("Failed to load usage stats");
      } finally {
        setIsLoading(false);
      }
    })();
  }, []);

  const chartData = stats
    ? Object.entries(stats.daily)
        .map(([date, count]) => ({ date, count: Number(count) }))
        .sort((a, b) => a.date.localeCompare(b.date))
    : [];

  return (
    <CollapsibleCard
      title="API Usage"
      description="Request volume and top consumers"
      icon={<BarChart3 className="h-4 w-4" />}
    >
      {isLoading ? (
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-4">
            <Skeleton className="h-24" />
            <Skeleton className="h-24" />
          </div>
          <Skeleton className="h-[200px]" />
        </div>
      ) : stats ? (
        <div className="space-y-6">
          {/* Summary cards */}
          <div className="grid grid-cols-2 gap-4">
            <Card>
              <CardHeader className="pb-2">
                <CardDescription>Total Requests (7 days)</CardDescription>
              </CardHeader>
              <CardContent>
                <p className="text-2xl font-bold">{stats.total_7d.toLocaleString()}</p>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="pb-2">
                <CardDescription>Total Requests (30 days)</CardDescription>
              </CardHeader>
              <CardContent>
                <p className="text-2xl font-bold">{stats.total_30d.toLocaleString()}</p>
              </CardContent>
            </Card>
          </div>

          {/* Area chart */}
          {chartData.length > 0 ? (
            <ChartContainer
              config={CHART_CONFIG}
              className="min-h-[200px] w-full"
            >
              <AreaChart data={chartData} accessibilityLayer>
                <CartesianGrid vertical={false} strokeDasharray="3 3" />
                <XAxis
                  dataKey="date"
                  tickLine={false}
                  tickMargin={8}
                  axisLine={false}
                  tickFormatter={formatChartDate}
                />
                <YAxis tickLine={false} axisLine={false} tickMargin={8} />
                <ChartTooltip content={<ChartTooltipContent />} />
                <Area
                  type="monotone"
                  dataKey="count"
                  stroke="var(--color-count)"
                  fill="var(--color-count)"
                  fillOpacity={0.2}
                  strokeWidth={2}
                />
              </AreaChart>
            </ChartContainer>
          ) : (
            <div className="flex min-h-[200px] items-center justify-center rounded-lg border border-dashed bg-muted/30 text-sm text-muted-foreground">
              No API usage data
            </div>
          )}

          {/* Top users */}
          {stats.top_users.length > 0 && (
            <div>
              <h3 className="text-sm font-medium mb-3">Top Users by Requests</h3>
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>User</TableHead>
                      <TableHead className="text-right">Requests</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {stats.top_users.map((u) => (
                      <TableRow key={u.user_id}>
                        <TableCell className="text-sm">{u.name}</TableCell>
                        <TableCell className="text-sm text-right">{u.total_requests.toLocaleString()}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </div>
          )}

          {/* Top queries */}
          {stats.top_queries.length > 0 && (
            <div>
              <h3 className="text-sm font-medium mb-3">Top Queries</h3>
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Query Name</TableHead>
                      <TableHead className="text-right">Count</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {stats.top_queries.map((q) => (
                      <TableRow key={q.query_name}>
                        <TableCell className="text-sm font-mono">{q.query_name}</TableCell>
                        <TableCell className="text-sm text-right">{q.total_requests.toLocaleString()}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </div>
          )}

          {/* Audit link */}
          <div className="flex justify-end">
            <Button variant="outline" size="sm" asChild>
              <Link href="/configuration/audit?action=api.query">
                <ExternalLink className="mr-2 h-4 w-4" />
                View API audit logs
              </Link>
            </Button>
          </div>
        </div>
      ) : (
        <p className="text-center text-muted-foreground py-8">
          Failed to load usage statistics.
        </p>
      )}
    </CollapsibleCard>
  );
}

// ---------------------------------------------------------------------------
// Main Page
// ---------------------------------------------------------------------------

export default function GraphQLSettingsPage() {
  const canManageKeys = usePermission("api_keys.manage");

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight md:text-3xl">
          GraphQL API
        </h1>
        <p className="text-muted-foreground mt-1">
          Configure the GraphQL API endpoint, security settings, and manage API keys.{" "}
          <HelpLink articleId="graphql-configuration" />
        </p>
      </div>

      <SettingsSection />

      {canManageKeys && <ApiKeysSection />}

      <UsageStatsSection />
    </div>
  );
}
