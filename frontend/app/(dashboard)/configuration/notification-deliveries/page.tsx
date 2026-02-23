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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

interface NotificationDeliveryRecord {
  id: number;
  user_id: number;
  notification_type: string;
  channel: string;
  status: "success" | "failed" | "rate_limited" | "skipped";
  error: string | null;
  attempt: number;
  attempted_at: string;
  user?: { id: number; name: string; email: string };
}

interface PaginatedResponse {
  data: NotificationDeliveryRecord[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

interface StatsResponse {
  data: {
    by_channel: { channel: string; total: number; successes: number; failures: number }[];
    by_status: Record<string, number>;
  };
}

const STATUS_VARIANTS: Record<string, string> = {
  success: "bg-green-500/10 text-green-600 dark:text-green-400 border-green-500/20",
  failed: "bg-red-500/10 text-red-600 dark:text-red-400 border-red-500/20",
  rate_limited: "bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20",
  skipped: "bg-muted text-muted-foreground border-muted",
};

const STATUS_LABELS: Record<string, string> = {
  success: "Success",
  failed: "Failed",
  rate_limited: "Rate Limited",
  skipped: "Skipped",
};

const CHANNELS = [
  "database", "email", "telegram", "discord", "slack",
  "twilio", "signal", "matrix", "vonage", "sns",
  "webpush", "fcm", "ntfy",
];

export default function NotificationDeliveryLogPage() {
  const [records, setRecords] = useState<NotificationDeliveryRecord[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);
  const [filters, setFilters] = useState({
    channel: "",
    status: "",
    notification_type: "",
    date_from: "",
    date_to: "",
  });
  const [detailRecord, setDetailRecord] = useState<NotificationDeliveryRecord | null>(null);
  const [stats, setStats] = useState<StatsResponse["data"] | null>(null);

  const fetchRecords = useCallback(async () => {
    setIsLoading(true);
    try {
      const params = new URLSearchParams({
        page: currentPage.toString(),
        per_page: "50",
      });
      if (filters.channel) params.append("channel", filters.channel);
      if (filters.status) params.append("status", filters.status);
      if (filters.notification_type) params.append("notification_type", filters.notification_type);
      if (filters.date_from) params.append("date_from", filters.date_from);
      if (filters.date_to) params.append("date_to", filters.date_to);

      const response = await api.get<PaginatedResponse>(
        `/notification-deliveries?${params.toString()}`
      );
      setRecords(response.data.data || []);
      setTotalPages(response.data.last_page || 1);
      setTotal(response.data.total || 0);
    } catch {
      toast.error("Failed to load delivery log");
      setRecords([]);
    } finally {
      setIsLoading(false);
    }
  }, [currentPage, filters]);

  const fetchStats = useCallback(async () => {
    try {
      const response = await api.get<StatsResponse>("/notification-deliveries/stats");
      setStats(response.data.data);
    } catch {
      // Stats are optional, don't show error
    }
  }, []);

  useEffect(() => {
    fetchRecords();
  }, [fetchRecords]);

  useEffect(() => {
    fetchStats();
  }, [fetchStats]);

  const typeFilterTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const handleFilterChange = (key: string, value: string) => {
    if (key === "notification_type") {
      setFilters((prev) => ({ ...prev, [key]: value }));
      if (typeFilterTimer.current) clearTimeout(typeFilterTimer.current);
      typeFilterTimer.current = setTimeout(() => setCurrentPage(1), 300);
      return;
    }
    setFilters((prev) => ({ ...prev, [key]: value }));
    setCurrentPage(1);
  };

  const clearFilters = () => {
    setFilters({ channel: "", status: "", notification_type: "", date_from: "", date_to: "" });
    setCurrentPage(1);
  };

  const hasActiveFilters = Object.values(filters).some(Boolean);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Delivery Log</h1>
        <p className="text-muted-foreground mt-1">
          Notification delivery attempts across all channels.
        </p>
      </div>

      {/* Stats cards */}
      {stats && stats.by_status && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          {(["success", "failed", "rate_limited", "skipped"] as const).map((status) => (
            <Card key={status}>
              <CardHeader className="pb-2">
                <CardDescription>{STATUS_LABELS[status]}</CardDescription>
              </CardHeader>
              <CardContent>
                <p className="text-2xl font-bold">{stats.by_status[status] ?? 0}</p>
                <p className="text-xs text-muted-foreground">Last 7 days</p>
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
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="filter-channel">Channel</Label>
              <Select
                value={filters.channel}
                onValueChange={(v) => handleFilterChange("channel", v === "all" ? "" : v)}
              >
                <SelectTrigger id="filter-channel">
                  <SelectValue placeholder="All channels" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All channels</SelectItem>
                  {CHANNELS.map((ch) => (
                    <SelectItem key={ch} value={ch}>
                      {ch}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
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
                  {Object.entries(STATUS_LABELS).map(([value, label]) => (
                    <SelectItem key={value} value={value}>
                      {label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="filter-type">Type</Label>
              <Input
                id="filter-type"
                placeholder="e.g. backup.completed"
                value={filters.notification_type}
                onChange={(e) => handleFilterChange("notification_type", e.target.value)}
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="filter-from">From</Label>
              <Input
                id="filter-from"
                type="date"
                value={filters.date_from}
                onChange={(e) => handleFilterChange("date_from", e.target.value)}
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="filter-to">To</Label>
              <Input
                id="filter-to"
                type="date"
                value={filters.date_to}
                onChange={(e) => handleFilterChange("date_to", e.target.value)}
              />
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
              Delivery Attempts
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
          ) : records.length === 0 ? (
            <p className="text-center text-muted-foreground py-8">
              No delivery records found.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Date</TableHead>
                    <TableHead>User</TableHead>
                    <TableHead>Channel</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Attempt</TableHead>
                    <TableHead>Error</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {records.map((record) => (
                    <TableRow key={record.id}>
                      <TableCell className="whitespace-nowrap text-sm">
                        {new Date(record.attempted_at).toLocaleString()}
                      </TableCell>
                      <TableCell className="text-sm">
                        {record.user?.name ?? `User #${record.user_id}`}
                      </TableCell>
                      <TableCell>
                        <Badge variant="outline" className="font-mono text-xs">
                          {record.channel}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-sm font-mono">
                        {record.notification_type}
                      </TableCell>
                      <TableCell>
                        <Badge
                          variant="outline"
                          className={STATUS_VARIANTS[record.status] ?? ""}
                        >
                          {STATUS_LABELS[record.status] ?? record.status}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-sm text-center">
                        {record.attempt}
                      </TableCell>
                      <TableCell className="text-sm max-w-[200px]">
                        {record.error ? (
                          <button
                            type="button"
                            className="text-left text-destructive truncate block w-full hover:underline cursor-pointer"
                            onClick={() => setDetailRecord(record)}
                            title="Click to view full error"
                          >
                            {record.error}
                          </button>
                        ) : (
                          <span className="text-muted-foreground">—</span>
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

      {/* Error detail dialog */}
      <Dialog open={!!detailRecord} onOpenChange={() => setDetailRecord(null)}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Delivery Error Details</DialogTitle>
          </DialogHeader>
          {detailRecord && (
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-2 text-sm">
                <div>
                  <span className="text-muted-foreground">Channel:</span>{" "}
                  <Badge variant="outline" className="font-mono">{detailRecord.channel}</Badge>
                </div>
                <div>
                  <span className="text-muted-foreground">Type:</span>{" "}
                  <span className="font-mono">{detailRecord.notification_type}</span>
                </div>
                <div>
                  <span className="text-muted-foreground">Status:</span>{" "}
                  <Badge variant="outline" className={STATUS_VARIANTS[detailRecord.status] ?? ""}>
                    {STATUS_LABELS[detailRecord.status]}
                  </Badge>
                </div>
                <div>
                  <span className="text-muted-foreground">Attempt:</span>{" "}
                  {detailRecord.attempt}
                </div>
                <div className="col-span-2">
                  <span className="text-muted-foreground">User:</span>{" "}
                  {detailRecord.user?.name ?? `#${detailRecord.user_id}`}{" "}
                  {detailRecord.user?.email && (
                    <span className="text-muted-foreground">({detailRecord.user.email})</span>
                  )}
                </div>
                <div className="col-span-2">
                  <span className="text-muted-foreground">Date:</span>{" "}
                  {new Date(detailRecord.attempted_at).toLocaleString()}
                </div>
              </div>
              {detailRecord.error && (
                <div>
                  <Label className="text-muted-foreground">Error Message</Label>
                  <pre className="mt-1 p-3 bg-muted rounded-md text-sm whitespace-pre-wrap break-words max-h-64 overflow-y-auto">
                    {detailRecord.error}
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
