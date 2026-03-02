"use client";

import { useState, useEffect, useRef } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { errorLogger } from "@/lib/error-logger";
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
import { SaveButton } from "@/components/ui/save-button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Loader2,
  Play,
  Eye,
  FileArchive,
  AlertTriangle,
  AlertCircle,
  CheckCircle,
} from "lucide-react";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { HelpLink } from "@/components/help/help-link";

interface LogRetentionSettings {
  app_retention_days: number;
  audit_retention_days: number;
}

type CleanupVariant = "normal" | "dry-run" | "archive";

export default function LogRetentionPage() {
  const [settings, setSettings] = useState<LogRetentionSettings>({
    app_retention_days: 90,
    audit_retention_days: 365,
  });
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [runVariant, setRunVariant] = useState<CleanupVariant | null>(null);
  const [isRunning, setIsRunning] = useState(false);
  const [runResult, setRunResult] = useState<{
    success: boolean;
    output: string;
    duration_ms: number;
  } | null>(null);
  const initialSettings = useRef<LogRetentionSettings>(settings);

  const isDirty =
    settings.app_retention_days !== initialSettings.current.app_retention_days ||
    settings.audit_retention_days !== initialSettings.current.audit_retention_days;

  useEffect(() => {
    api
      .get<{ settings: LogRetentionSettings }>("/log-retention")
      .then((res) => {
        const s = res.data.settings;
        const loaded: LogRetentionSettings = {
          app_retention_days: s.app_retention_days ?? 90,
          audit_retention_days: s.audit_retention_days ?? 365,
        };
        setSettings(loaded);
        initialSettings.current = loaded;
      })
      .catch(() => toast.error("Failed to load log retention settings"))
      .finally(() => setIsLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleSave = async () => {
    setIsSaving(true);
    try {
      await api.put("/log-retention", settings);
      initialSettings.current = { ...settings };
      toast.success("Log retention settings saved.");
    } catch (error: unknown) {
      toast.error(
        error instanceof Error ? error.message : "Failed to save log retention settings"
      );
    } finally {
      setIsSaving(false);
    }
  };

  const handleRunCommand = (variant: CleanupVariant) => {
    setRunVariant(variant);
    setRunResult(null);
  };

  const confirmRun = async () => {
    if (!runVariant) return;

    setIsRunning(true);
    setRunResult(null);

    const options =
      runVariant === "dry-run"
        ? { "--dry-run": true }
        : runVariant === "archive"
          ? { "--archive": true }
          : {};

    try {
      const response = await api.post<{
        success?: boolean;
        output?: string;
        message?: string;
        duration_ms?: number;
      }>(`/jobs/run/${encodeURIComponent("log:cleanup")}`, { options }, {
        validateStatus: (status) => (status >= 200 && status < 300) || status === 422,
      });

      const success = response.data?.success ?? false;
      const output = response.data?.output ?? response.data?.message ?? "";
      const durationMs = response.data?.duration_ms ?? 0;

      setRunResult({
        success,
        output,
        duration_ms: durationMs,
      });

      if (success) {
        toast.success(`log:cleanup completed in ${durationMs}ms`);
      } else {
        toast.error("log:cleanup failed");
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Request failed";
      setRunResult({
        success: false,
        output: msg,
        duration_ms: 0,
      });
      toast.error(msg);
      errorLogger.report(
        err instanceof Error ? err : new Error("Run command failed"),
        { command: "log:cleanup", variant: runVariant }
      );
    } finally {
      setIsRunning(false);
    }
  };

  const closeRunDialog = () => {
    if (!isRunning) {
      setRunVariant(null);
      setRunResult(null);
    }
  };

  if (isLoading) {
    return <SettingsPageSkeleton />;
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">
          Log retention
        </h1>
        <p className="text-muted-foreground mt-1">
          Configure how long to keep application and audit logs. Run cleanup from this page or via CLI.{" "}
          <HelpLink articleId="log-retention" />
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Retention (days)</CardTitle>
          <CardDescription>
            Entries and files older than these values are eligible for cleanup.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="app_retention_days">Application logs</Label>
              <Input
                id="app_retention_days"
                type="number"
                min={1}
                max={365}
                value={settings.app_retention_days}
                onChange={(e) =>
                  setSettings((s) => ({
                    ...s,
                    app_retention_days: Math.max(1, Math.min(365, parseInt(e.target.value, 10) || 1)),
                  }))
                }
              />
              <p className="text-xs text-muted-foreground">1–365 days (daily log files)</p>
            </div>
            <div className="space-y-2">
              <Label htmlFor="audit_retention_days">Audit logs</Label>
              <Input
                id="audit_retention_days"
                type="number"
                min={30}
                max={730}
                value={settings.audit_retention_days}
                onChange={(e) =>
                  setSettings((s) => ({
                    ...s,
                    audit_retention_days: Math.max(30, Math.min(730, parseInt(e.target.value, 10) || 30)),
                  }))
                }
              />
              <p className="text-xs text-muted-foreground">30–730 days</p>
            </div>
          </div>
        </CardContent>
        <CardFooter className="flex justify-end">
          <SaveButton
            type="button"
            isDirty={isDirty}
            isSaving={isSaving}
            onClick={handleSave}
          />
        </CardFooter>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Cleanup command</CardTitle>
          <CardDescription>
            Run log cleanup from here or from the server (e.g. via cron). Dry run previews what would be deleted; archive exports to CSV before deleting.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-wrap gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => handleRunCommand("dry-run")}
          >
            <Eye className="mr-2 h-4 w-4" />
            Dry Run
          </Button>
          <Button
            variant="default"
            size="sm"
            onClick={() => handleRunCommand("normal")}
          >
            <Play className="mr-2 h-4 w-4" />
            Run Cleanup
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => handleRunCommand("archive")}
          >
            <FileArchive className="mr-2 h-4 w-4" />
            Archive & Clean
          </Button>
        </CardContent>
      </Card>

      <Dialog open={!!runVariant} onOpenChange={(open) => !open && closeRunDialog()}>
        <DialogContent
          className="max-w-lg"
          onInteractOutside={(e) => isRunning && e.preventDefault()}
          onEscapeKeyDown={(e) => isRunning && e.preventDefault()}
        >
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              {runResult !== null ? (
                runResult.success ? (
                  <>
                    <CheckCircle className="h-5 w-5 text-green-600 dark:text-green-400" />
                    Command completed
                  </>
                ) : (
                  <>
                    <AlertCircle className="h-5 w-5 text-destructive" />
                    Command failed
                  </>
                )
              ) : (
                <>Run log:cleanup</>
              )}
            </DialogTitle>
            <DialogDescription>
              {runVariant && runResult === null && (
                <>
                  Run <code className="font-mono text-sm">log:cleanup</code>
                  {runVariant === "dry-run" && " (dry run — no changes)"}
                  {runVariant === "archive" && " (archive then delete)"}
                  {runVariant === "normal" && (
                    <span className="mt-2 flex items-center gap-2 text-amber-600">
                      <AlertTriangle className="h-4 w-4 shrink-0" />
                      This will delete old log entries.
                    </span>
                  )}
                </>
              )}
              {runVariant && runResult !== null && (
                <>Completed in {runResult.duration_ms}ms</>
              )}
            </DialogDescription>
          </DialogHeader>

          {isRunning && (
            <div className="flex items-center gap-2 py-4">
              <Loader2 className="h-5 w-5 animate-spin" />
              <span>Running log:cleanup…</span>
            </div>
          )}

          {runResult !== null && !isRunning && (
            <div className="space-y-2">
              <pre className="max-h-48 overflow-auto rounded bg-muted p-3 text-xs whitespace-pre-wrap">
                {runResult.output || "(no output)"}
              </pre>
            </div>
          )}

          <DialogFooter>
            {runResult !== null && !isRunning ? (
              <Button onClick={closeRunDialog}>Close</Button>
            ) : !isRunning ? (
              <>
                <Button variant="outline" onClick={closeRunDialog}>
                  Cancel
                </Button>
                <Button onClick={confirmRun}>Run now</Button>
              </>
            ) : null}
          </DialogFooter>
        </DialogContent>
      </Dialog>

    </div>
  );
}
