"use client";

import { useState, useEffect, useCallback } from "react";
import Link from "next/link";
import { api } from "@/lib/api";
import { useAuth, isAdminUser } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";
import { Badge } from "@/components/ui/badge";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Loader2, FolderOpen, Globe, Archive, Database, Users, FileText, AlertTriangle } from "lucide-react";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { HelpLink } from "@/components/help/help-link";
import { StorageAnalyticsCard } from "@/components/storage/storage-analytics-card";
import { StorageCleanupCard } from "@/components/storage/storage-cleanup-dialog";
import { StorageDriverForm } from "@/components/storage/storage-driver-form";

interface StorageStats {
  driver: string;
  total_size: number;
  total_size_formatted: string;
  file_count: number;
  breakdown?: Record<string, { size: number; size_formatted: string }>;
}

interface StoragePath {
  key: string;
  path: string;
  description: string;
}

interface StorageHealth {
  status: "healthy" | "warning";
  writable: boolean;
  disk_used_percent: number;
  disk_free_formatted: string;
  disk_total_formatted: string;
}

interface StorageAnalytics {
  driver: string;
  by_type?: Record<string, number>;
  top_files?: Array<{
    path: string;
    name: string;
    size: number;
    size_formatted: string;
    lastModified: number;
    lastModifiedFormatted: string;
  }>;
  recent_files?: Array<{
    path: string;
    name: string;
    size: number;
    size_formatted: string;
    lastModified: number;
    lastModifiedFormatted: string;
  }>;
  note?: string;
}

interface CleanupSuggestions {
  suggestions: Record<string, { count: number; size: number; size_formatted?: string; description: string }>;
  total_reclaimable: number;
  total_reclaimable_formatted?: string;
  note?: string;
}

export default function StorageSettingsPage() {
  const { user } = useAuth();
  const [initialLoading, setInitialLoading] = useState(true);
  const [isLoadingStats, setIsLoadingStats] = useState(false);
  const [stats, setStats] = useState<StorageStats | null>(null);
  const [paths, setPaths] = useState<StoragePath[]>([]);
  const [health, setHealth] = useState<StorageHealth | null>(null);
  const [analytics, setAnalytics] = useState<StorageAnalytics | null>(null);
  const [isLoadingAnalytics, setIsLoadingAnalytics] = useState(false);
  const [cleanupSuggestions, setCleanupSuggestions] = useState<CleanupSuggestions | null>(null);
  const [isLoadingCleanup, setIsLoadingCleanup] = useState(false);

  const fetchStats = useCallback(async () => {
    setIsLoadingStats(true);
    try {
      const response = await api.get("/storage-settings/stats");
      setStats(response.data);
    } catch {
      // Stats might not be available
    } finally {
      setIsLoadingStats(false);
    }
  }, []);

  const fetchPaths = useCallback(async () => {
    try {
      const response = await api.get("/storage-settings/paths");
      setPaths(response.data.paths ?? []);
    } catch {
      // Paths might not be available
    }
  }, []);

  const fetchHealth = useCallback(async () => {
    try {
      const response = await api.get("/storage-settings/health");
      setHealth(response.data);
    } catch {
      // Health might not be available
    }
  }, []);

  const fetchAnalytics = useCallback(async () => {
    setIsLoadingAnalytics(true);
    try {
      const response = await api.get("/storage-settings/analytics");
      setAnalytics(response.data);
    } catch {
      // Analytics might not be available
    } finally {
      setIsLoadingAnalytics(false);
    }
  }, []);

  const fetchCleanupSuggestions = useCallback(async () => {
    setIsLoadingCleanup(true);
    try {
      const response = await api.get("/storage-settings/cleanup-suggestions");
      setCleanupSuggestions(response.data);
    } catch {
      // Cleanup might not be available
    } finally {
      setIsLoadingCleanup(false);
    }
  }, []);

  const refreshAll = useCallback(async () => {
    await Promise.all([
      fetchStats(),
      fetchPaths(),
      fetchHealth(),
      fetchAnalytics(),
      fetchCleanupSuggestions(),
    ]);
  }, [fetchStats, fetchPaths, fetchHealth, fetchAnalytics, fetchCleanupSuggestions]);

  useEffect(() => {
    refreshAll().finally(() => setInitialLoading(false));
  }, [refreshAll]);

  if (initialLoading) {
    return <SettingsPageSkeleton />;
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Storage Settings</h1>
          <p className="text-muted-foreground mt-1">
            Configure file storage and upload policies.{" "}
            <HelpLink articleId="storage-settings" />
          </p>
        </div>
        {isAdminUser(user) && (
          <Button asChild variant="outline" className="shrink-0">
            <Link href="/configuration/storage/files" className="flex items-center gap-2">
              <FolderOpen className="h-4 w-4" />
              Manage Files
            </Link>
          </Button>
        )}
      </div>

      {health && health.status === "warning" && (
        <Alert variant="warning">
          <AlertTriangle className="h-4 w-4" />
          <AlertTitle>Storage Health Warning</AlertTitle>
          <AlertDescription>
            {!health.writable
              ? "Storage directory is not writable. Check permissions."
              : `Disk usage is at ${health.disk_used_percent}%. Free space: ${health.disk_free_formatted} of ${health.disk_total_formatted}. Consider freeing space or expanding storage.`}
          </AlertDescription>
        </Alert>
      )}

      {stats && (
        <Card>
          <CardHeader>
            <CardTitle>Storage Statistics</CardTitle>
            <CardDescription>Current storage usage</CardDescription>
          </CardHeader>
          <CardContent>
            {isLoadingStats ? (
              <div className="flex items-center justify-center py-8">
                <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
              </div>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <div className="text-sm text-muted-foreground">Total Size</div>
                  <div className="text-2xl font-bold">{stats.total_size_formatted}</div>
                </div>
                <div>
                  <div className="text-sm text-muted-foreground">File Count</div>
                  <div className="text-2xl font-bold">{stats.file_count}</div>
                </div>
                <div>
                  <div className="text-sm text-muted-foreground">Driver</div>
                  <Badge>{stats.driver}</Badge>
                </div>
              </div>
            )}
            {!isLoadingStats && stats.breakdown && Object.keys(stats.breakdown).length > 0 && (
              <>
                <Separator className="my-4" />
                <div className="space-y-2">
                  <div className="text-sm font-medium text-muted-foreground">Usage by directory</div>
                  <div className="space-y-2">
                    {Object.entries(stats.breakdown).map(([dir, { size_formatted }]) => (
                      <div key={dir} className="flex items-center justify-between text-sm">
                        <span className="font-mono text-muted-foreground">{dir}</span>
                        <span className="font-medium">{size_formatted}</span>
                      </div>
                    ))}
                  </div>
                </div>
              </>
            )}
          </CardContent>
        </Card>
      )}

      {analytics && (
        <StorageAnalyticsCard
          analytics={analytics}
          isLoading={isLoadingAnalytics}
          user={user}
        />
      )}

      {cleanupSuggestions && stats?.driver === "local" && (
        <StorageCleanupCard
          suggestions={cleanupSuggestions}
          isLoading={isLoadingCleanup}
          onCleanupComplete={refreshAll}
        />
      )}

      {paths.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Storage Paths</CardTitle>
            <CardDescription>
              Where files are stored on this server (local driver only)
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              {paths.map((item) => {
                const Icon = {
                  app: FolderOpen,
                  public: Globe,
                  backups: Archive,
                  cache: Database,
                  sessions: Users,
                  logs: FileText,
                }[item.key] ?? FolderOpen;
                return (
                  <div
                    key={item.key}
                    className="flex gap-3 rounded-lg border p-3"
                  >
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted">
                      <Icon className="h-4 w-4 text-muted-foreground" />
                    </div>
                    <div className="min-w-0 flex-1 space-y-1">
                      <div className="font-medium capitalize">{item.key}</div>
                      <p className="text-sm text-muted-foreground">{item.description}</p>
                      <code className="block truncate rounded bg-muted px-1.5 py-0.5 text-xs font-mono">
                        {item.path}
                      </code>
                    </div>
                  </div>
                );
              })}
            </div>
          </CardContent>
        </Card>
      )}

      <StorageDriverForm health={health} onSaved={refreshAll} />
    </div>
  );
}
