"use client";

import { useState, useEffect, useCallback, useRef } from "react";
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
import { Badge } from "@/components/ui/badge";
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
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Skeleton } from "@/components/ui/skeleton";
import { Download, Loader2, Search, Radio, Wifi, WifiOff } from "lucide-react";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useAuditStream, type AuditLogStreamPayload } from "@/lib/use-audit-stream";
import { HelpLink } from "@/components/help/help-link";

interface AuditLog {
  id: number;
  user_id: number | null;
  action: string;
  severity?: string;
  auditable_type: string | null;
  auditable_id: number | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  ip_address: string | null;
  user_agent: string | null;
  correlation_id?: string | null;
  created_at: string;
  user?: {
    id: number;
    name: string;
    email: string;
  };
}

interface UserOption {
  id: number;
  name: string;
  email: string;
}

interface PaginatedResponse {
  data: AuditLog[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

const SEVERITY_VARIANTS: Record<string, string> = {
  info: "bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20",
  warning: "bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20",
  error: "bg-red-500/10 text-red-600 dark:text-red-400 border-red-500/20",
  critical: "bg-red-700/10 text-red-700 dark:text-red-500 border-red-700/20",
};

export default function AuditLogPage() {
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [users, setUsers] = useState<UserOption[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [usersLoading, setUsersLoading] = useState(false);
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);
  const [filters, setFilters] = useState({
    user_id: "",
    action: "",
    severity: "",
    correlation_id: "",
    date_from: "",
    date_to: "",
  });
  const [isExporting, setIsExporting] = useState(false);
  const [detailLog, setDetailLog] = useState<AuditLog | null>(null);
  const [liveEnabled, setLiveEnabled] = useState(false);
  const [highlightedIds, setHighlightedIds] = useState<Set<number>>(new Set());
  const highlightTimeoutsRef = useRef<Map<number, ReturnType<typeof setTimeout>>>(new Map());

  const handleNewLog = useCallback((payload: AuditLogStreamPayload) => {
    setLogs((prev) => {
      const exists = prev.some((l) => l.id === payload.id);
      if (exists) return prev;
      const newLog: AuditLog = {
        id: payload.id,
        user_id: payload.user_id,
        action: payload.action,
        severity: payload.severity,
        auditable_type: payload.auditable_type,
        auditable_id: payload.auditable_id,
        old_values: payload.old_values,
        new_values: payload.new_values,
        ip_address: payload.ip_address,
        user_agent: payload.user_agent,
        correlation_id: payload.correlation_id ?? null,
        created_at: payload.created_at,
        user: payload.user,
      };
      setHighlightedIds((ids) => new Set(ids).add(payload.id));
      const t = setTimeout(() => {
        setHighlightedIds((ids) => {
          const next = new Set(ids);
          next.delete(payload.id);
          return next;
        });
        highlightTimeoutsRef.current.delete(payload.id);
      }, 3000);
      highlightTimeoutsRef.current.set(payload.id, t);
      setTotal((c) => c + 1);
      return [newLog, ...prev];
    });
  }, []);

  const { status: streamStatus } = useAuditStream(liveEnabled, handleNewLog);

  useEffect(() => {
    const timeouts = highlightTimeoutsRef.current;
    return () => {
      timeouts.forEach((t) => clearTimeout(t));
      timeouts.clear();
    };
  }, []);

  const fetchUsers = useCallback(async () => {
    setUsersLoading(true);
    try {
      const response = await api.get<{
        data: UserOption[];
        current_page: number;
        last_page: number;
      }>("/users?per_page=200");
      setUsers(response.data.data || []);
    } catch {
      setUsers([]);
    } finally {
      setUsersLoading(false);
    }
  }, []);

  const fetchLogs = useCallback(async () => {
    setIsLoading(true);
    try {
      const params = new URLSearchParams({
        page: currentPage.toString(),
        per_page: "50",
      });

      if (filters.user_id) params.append("user_id", filters.user_id);
      if (filters.action) params.append("action", filters.action);
      if (filters.severity) params.append("severity", filters.severity);
      if (filters.correlation_id) params.append("correlation_id", filters.correlation_id);
      if (filters.date_from) params.append("date_from", filters.date_from);
      if (filters.date_to) params.append("date_to", filters.date_to);

      const response = await api.get<PaginatedResponse>(`/audit-logs?${params}`);
      setLogs(response.data.data || []);
      setCurrentPage(response.data.current_page || 1);
      setTotalPages(response.data.last_page || 1);
      setTotal(response.data.total || 0);
    } catch (error: unknown) {
      toast.error(
        error instanceof Error ? error.message : "Failed to load audit logs"
      );
    } finally {
      setIsLoading(false);
    }
  }, [currentPage, filters]);

  useEffect(() => {
    fetchLogs();
  }, [fetchLogs]);

  useEffect(() => {
    fetchUsers();
  }, [fetchUsers]);

  const handleExport = async () => {
    setIsExporting(true);
    try {
      const params = new URLSearchParams();
      if (filters.user_id) params.append("user_id", filters.user_id);
      if (filters.action) params.append("action", filters.action);
      if (filters.severity) params.append("severity", filters.severity);
      if (filters.correlation_id) params.append("correlation_id", filters.correlation_id);
      if (filters.date_from) params.append("date_from", filters.date_from);
      if (filters.date_to) params.append("date_to", filters.date_to);

      const response = await api.get(`/audit-logs/export?${params}`, {
        responseType: "blob",
      });

      const url = window.URL.createObjectURL(new Blob([response.data]));
      const link = document.createElement("a");
      link.href = url;
      link.setAttribute(
        "download",
        `audit_logs_${new Date().toISOString().split("T")[0]}.csv`
      );
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);

      toast.success("Audit logs exported successfully");
    } catch (error: unknown) {
      toast.error(
        error instanceof Error ? error.message : "Failed to export audit logs"
      );
    } finally {
      setIsExporting(false);
    }
  };

  const formatAction = (action: string) => {
    return action.replace(/\./g, " ").replace(/\b\w/g, (l) => l.toUpperCase());
  };

  const severityClass = (severity: string) =>
    SEVERITY_VARIANTS[severity] ||
    "bg-muted text-muted-foreground border-muted";

  const resetFilters = () => {
    setFilters({
      user_id: "",
      action: "",
      severity: "",
      correlation_id: "",
      date_from: "",
      date_to: "",
    });
    setCurrentPage(1);
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight md:text-3xl">
            Audit Log
          </h1>
          <p className="text-muted-foreground mt-1">
            Track user actions and system changes.{" "}
            <HelpLink articleId="audit-logs" />
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant={liveEnabled ? "default" : "outline"}
            size="sm"
            onClick={() => setLiveEnabled((v) => !v)}
            title={
              streamStatus === "unavailable"
                ? "Real-time requires Reverb configuration"
                : liveEnabled
                  ? "Turn off live updates"
                  : "Stream new logs in real time"
            }
          >
            <Radio
              className={`mr-2 h-4 w-4 ${liveEnabled ? "animate-pulse" : ""}`}
            />
            Live
          </Button>
          {liveEnabled && (
            <span
              className="flex items-center gap-1.5 text-xs text-muted-foreground"
              title={
                streamStatus === "connected"
                  ? "Connected to real-time stream"
                  : streamStatus === "unavailable"
                    ? "Reverb not configured or auth failed"
                    : "Connecting…"
              }
            >
              {streamStatus === "connected" ? (
                <Wifi className="h-3.5 w-3.5 text-green-600 dark:text-green-400" />
              ) : streamStatus === "unavailable" ? (
                <WifiOff className="h-3.5 w-3.5 text-amber-600 dark:text-amber-400" />
              ) : (
                <Loader2 className="h-3.5 w-3.5 animate-spin" />
              )}
              {streamStatus === "connected"
                ? "Live"
                : streamStatus === "unavailable"
                  ? "Unavailable"
                  : "Connecting…"}
            </span>
          )}
          <Button onClick={handleExport} disabled={isExporting}>
            {isExporting ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Download className="mr-2 h-4 w-4" />
            )}
            Export CSV
          </Button>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Filters</CardTitle>
          <CardDescription>
            Filter audit logs by user, action, severity, correlation ID, or date range
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-6">
            <div className="space-y-2">
              <Label htmlFor="user">User</Label>
              <Select
                value={filters.user_id || "all"}
                onValueChange={(v) =>
                  setFilters({ ...filters, user_id: v === "all" ? "" : v })
                }
                disabled={usersLoading}
              >
                <SelectTrigger id="user">
                  <SelectValue placeholder="All users" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All users</SelectItem>
                  {users.map((u) => (
                    <SelectItem key={u.id} value={String(u.id)}>
                      {u.name} ({u.email})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="action">Action / Search</Label>
              <div className="relative">
                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                <Input
                  id="action"
                  placeholder="e.g., auth.login"
                  className="pl-8"
                  value={filters.action}
                  onChange={(e) =>
                    setFilters({ ...filters, action: e.target.value })
                  }
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="severity">Severity</Label>
              <Select
                value={filters.severity || "all"}
                onValueChange={(v) =>
                  setFilters({ ...filters, severity: v === "all" ? "" : v })
                }
              >
                <SelectTrigger id="severity">
                  <SelectValue placeholder="All" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All</SelectItem>
                  <SelectItem value="info">Info</SelectItem>
                  <SelectItem value="warning">Warning</SelectItem>
                  <SelectItem value="error">Error</SelectItem>
                  <SelectItem value="critical">Critical</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="correlation_id">Correlation ID</Label>
              <Input
                id="correlation_id"
                placeholder="Trace ID"
                value={filters.correlation_id}
                onChange={(e) =>
                  setFilters({ ...filters, correlation_id: e.target.value })
                }
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="date_from">From Date</Label>
              <Input
                id="date_from"
                type="date"
                value={filters.date_from}
                onChange={(e) =>
                  setFilters({ ...filters, date_from: e.target.value })
                }
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="date_to">To Date</Label>
              <Input
                id="date_to"
                type="date"
                value={filters.date_to}
                onChange={(e) =>
                  setFilters({ ...filters, date_to: e.target.value })
                }
              />
            </div>
          </div>
          <Button
            variant="outline"
            className="mt-4"
            onClick={resetFilters}
          >
            Clear filters
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Audit Logs</CardTitle>
          <CardDescription>
            {total} total log{total !== 1 ? "s" : ""}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="space-y-4">
              {[1, 2, 3, 4, 5].map((i) => (
                <Skeleton key={i} className="h-16 w-full" />
              ))}
            </div>
          ) : logs.length === 0 ? (
            <div className="text-center py-12 text-muted-foreground">
              No audit logs found
            </div>
          ) : (
            <>
              <div className="rounded-md border overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Date</TableHead>
                      <TableHead>User</TableHead>
                      <TableHead>Action</TableHead>
                      <TableHead>Severity</TableHead>
                      <TableHead>IP Address</TableHead>
                      <TableHead>Details</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {logs.map((log) => (
                      <TableRow
                        key={log.id}
                        className={
                          highlightedIds.has(log.id)
                            ? "bg-primary/10 transition-colors duration-300"
                            : ""
                        }
                      >
                        <TableCell className="whitespace-nowrap">
                          {new Date(log.created_at).toLocaleString()}
                        </TableCell>
                        <TableCell>
                          {log.user ? (
                            <div>
                              <div className="font-medium">{log.user.name}</div>
                              <div className="text-sm text-muted-foreground">
                                {log.user.email}
                              </div>
                            </div>
                          ) : (
                            <Badge variant="secondary">System</Badge>
                          )}
                        </TableCell>
                        <TableCell>
                          <Badge variant="outline">
                            {formatAction(log.action)}
                          </Badge>
                        </TableCell>
                        <TableCell>
                          <Badge
                            variant="outline"
                            className={severityClass(log.severity || "info")}
                          >
                            {(log.severity || "info").toLowerCase()}
                          </Badge>
                        </TableCell>
                        <TableCell>{log.ip_address || "—"}</TableCell>
                        <TableCell>
                          {log.old_values || log.new_values ? (
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => setDetailLog(log)}
                            >
                              View details
                            </Button>
                          ) : (
                            "—"
                          )}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>

              {totalPages > 1 && (
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between mt-4">
                  <div className="text-sm text-muted-foreground">
                    Page {currentPage} of {totalPages}
                  </div>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() =>
                        setCurrentPage((p) => Math.max(1, p - 1))
                      }
                      disabled={currentPage === 1}
                    >
                      Previous
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() =>
                        setCurrentPage((p) =>
                          Math.min(totalPages, p + 1)
                        )
                      }
                      disabled={currentPage === totalPages}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>

      <Dialog open={!!detailLog} onOpenChange={() => setDetailLog(null)}>
        <DialogContent className="max-w-2xl max-h-[85vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Log details</DialogTitle>
          </DialogHeader>
          {detailLog && (
            <div className="space-y-4 text-sm">
              <div className="grid grid-cols-2 gap-2">
                <span className="text-muted-foreground">Date</span>
                <span>
                  {new Date(detailLog.created_at).toLocaleString()}
                </span>
                <span className="text-muted-foreground">Action</span>
                <span>{detailLog.action}</span>
                <span className="text-muted-foreground">Severity</span>
                <Badge
                  variant="outline"
                  className={severityClass(detailLog.severity || "info")}
                >
                  {(detailLog.severity || "info").toLowerCase()}
                </Badge>
                <span className="text-muted-foreground">User</span>
                <span>
                  {detailLog.user
                    ? `${detailLog.user.name} (${detailLog.user.email})`
                    : "System"}
                </span>
                <span className="text-muted-foreground">IP</span>
                <span>{detailLog.ip_address || "—"}</span>
                {detailLog.user_agent && (
                  <>
                    <span className="text-muted-foreground">User agent</span>
                    <span className="break-all">{detailLog.user_agent}</span>
                  </>
                )}
              </div>
              {detailLog.old_values && (
                <div>
                  <Label className="text-muted-foreground">Old values</Label>
                  <pre className="mt-1 rounded bg-muted p-3 text-xs overflow-x-auto">
                    {JSON.stringify(detailLog.old_values, null, 2)}
                  </pre>
                </div>
              )}
              {detailLog.new_values && (
                <div>
                  <Label className="text-muted-foreground">New values</Label>
                  <pre className="mt-1 rounded bg-muted p-3 text-xs overflow-x-auto">
                    {JSON.stringify(detailLog.new_values, null, 2)}
                  </pre>
                </div>
              )}
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
