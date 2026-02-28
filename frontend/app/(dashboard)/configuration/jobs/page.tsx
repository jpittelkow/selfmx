"use client";

import { useState, useEffect, useCallback } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { errorLogger } from "@/lib/error-logger";
import { getErrorMessage } from "@/lib/utils";
import { Button } from "@/components/ui/button";
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
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
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
  RefreshCw,
  Trash2,
  Play,
  AlertCircle,
  AlertTriangle,
  CheckCircle,
} from "lucide-react";
import { HelpLink } from "@/components/help/help-link";

interface ScheduledTask {
  command: string;
  schedule: string;
  description: string;
  triggerable?: boolean;
  dangerous?: boolean;
  last_run?: { at: string; status: string } | null;
  next_run?: string | null;
}

interface FailedJob {
  id: number;
  uuid: string;
  connection: string;
  queue: string;
  payload: string;
  exception: string;
  failed_at: string;
}

interface QueueStatus {
  pending: number;
  failed: number;
  queues?: Record<string, number>;
}

export default function JobsPage() {
  const [scheduledTasks, setScheduledTasks] = useState<ScheduledTask[]>([]);
  const [queueStatus, setQueueStatus] = useState<QueueStatus | null>(null);
  const [failedJobs, setFailedJobs] = useState<FailedJob[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isLoadingScheduled, setIsLoadingScheduled] = useState(false);
  const [isLoadingQueue, setIsLoadingQueue] = useState(false);
  const [isLoadingFailed, setIsLoadingFailed] = useState(false);
  const [runTarget, setRunTarget] = useState<ScheduledTask | null>(null);
  const [isRunning, setIsRunning] = useState(false);
  const [runResult, setRunResult] = useState<{
    success: boolean;
    output: string;
    duration_ms: number;
  } | null>(null);

  const fetchAll = useCallback(async () => {
    setIsLoading(true);
    await Promise.all([
      fetchScheduled(),
      fetchQueueStatus(),
      fetchFailedJobs(),
    ]);
    setIsLoading(false);
  }, []);

  useEffect(() => {
    fetchAll();
  }, [fetchAll]);

  const fetchScheduled = async () => {
    setIsLoadingScheduled(true);
    try {
      const response = await api.get("/jobs/scheduled");
      setScheduledTasks(response.data.tasks || []);
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to load scheduled tasks"));
    } finally {
      setIsLoadingScheduled(false);
    }
  };

  const fetchQueueStatus = async () => {
    setIsLoadingQueue(true);
    try {
      const response = await api.get("/jobs/queue");
      setQueueStatus(response.data);
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to load queue status"));
    } finally {
      setIsLoadingQueue(false);
    }
  };

  const fetchFailedJobs = async () => {
    setIsLoadingFailed(true);
    try {
      const response = await api.get("/jobs/failed");
      setFailedJobs(response.data.data || []);
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to load failed jobs"));
    } finally {
      setIsLoadingFailed(false);
    }
  };

  const handleRetryJob = async (id: number) => {
    try {
      await api.post(`/jobs/failed/${id}/retry`);
      toast.success("Job queued for retry");
      fetchFailedJobs();
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to retry job"));
    }
  };

  const handleDeleteJob = async (id: number) => {
    if (!confirm("Are you sure you want to delete this failed job?")) {
      return;
    }

    try {
      await api.delete(`/jobs/failed/${id}`);
      toast.success("Failed job deleted");
      fetchFailedJobs();
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to delete job"));
    }
  };

  const handleRetryAll = async () => {
    if (!confirm("Retry all failed jobs?")) {
      return;
    }

    try {
      await api.post("/jobs/failed/retry-all");
      toast.success("All failed jobs queued for retry");
      fetchFailedJobs();
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to retry jobs"));
    }
  };

  const handleClearFailed = async () => {
    if (!confirm("Clear all failed jobs? This cannot be undone.")) {
      return;
    }

    try {
      await api.delete("/jobs/failed/clear");
      toast.success("All failed jobs cleared");
      fetchFailedJobs();
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to clear jobs"));
    }
  };

  const handleRunNow = (task: ScheduledTask) => {
    setRunTarget(task);
    setRunResult(null);
  };

  const confirmRunNow = async () => {
    if (!runTarget) return;

    setIsRunning(true);
    setRunResult(null);

    try {
      const response = await api.post<{
        success: boolean;
        output?: string;
        duration_ms?: number;
      }>(`/jobs/run/${encodeURIComponent(runTarget.command)}`, {
        options: {},
      });

      const success = response.data?.success ?? false;
      const output = response.data?.output ?? "";
      const durationMs = response.data?.duration_ms ?? 0;

      setRunResult({
        success,
        output,
        duration_ms: durationMs,
      });

      if (success) {
        toast.success(
          `${runTarget.command} completed in ${durationMs}ms`
        );
        setRunTarget(null);
        fetchScheduled();
      } else {
        toast.error(`${runTarget.command} failed`);
      }
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : "Request failed";
      setRunResult({
        success: false,
        output: message,
        duration_ms: 0,
      });
      toast.error(message);
      errorLogger.report(
        err instanceof Error ? err : new Error("Run command failed"),
        { command: runTarget.command }
      );
    } finally {
      setIsRunning(false);
    }
  };

  const closeRunDialog = () => {
    if (!isRunning) {
      setRunTarget(null);
      setRunResult(null);
    }
  };

  const formatLastRun = (task: ScheduledTask) => {
    const lr = task.last_run;
    if (!lr) return "Never";
    const date = new Date(lr.at).toLocaleString();
    return `${date} (${lr.status})`;
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Scheduled Jobs</h1>
          <p className="text-muted-foreground mt-2">
            Monitor and manage background jobs and scheduled tasks.{" "}
            <HelpLink articleId="scheduled-jobs" />
          </p>
        </div>
        <Button onClick={fetchAll} disabled={isLoading}>
          <RefreshCw className={`mr-2 h-4 w-4 ${isLoading ? "animate-spin" : ""}`} />
          Refresh
        </Button>
      </div>

      <Tabs defaultValue="scheduled" className="space-y-6">
        <TabsList>
          <TabsTrigger value="scheduled">Scheduled Tasks</TabsTrigger>
          <TabsTrigger value="queue">Queue Status</TabsTrigger>
          <TabsTrigger value="failed">Failed Jobs</TabsTrigger>
        </TabsList>

        <TabsContent value="scheduled">
          <Card>
            <CardHeader>
              <CardTitle>Scheduled Tasks</CardTitle>
              <CardDescription>
                Tasks configured to run on a schedule
              </CardDescription>
            </CardHeader>
            <CardContent>
              {isLoadingScheduled ? (
                <div className="space-y-4">
                  {[1, 2, 3].map((i) => (
                    <Skeleton key={i} className="h-16 w-full" />
                  ))}
                </div>
              ) : scheduledTasks.length === 0 ? (
                <div className="text-center py-12 text-muted-foreground">
                  No scheduled tasks found
                </div>
              ) : (
                <div className="rounded-md border overflow-x-auto">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Command</TableHead>
                        <TableHead>Schedule</TableHead>
                        <TableHead>Last Run</TableHead>
                        <TableHead>Description</TableHead>
                        <TableHead className="w-[100px]">Actions</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {scheduledTasks.map((task, index) => (
                        <TableRow key={`${task.command}-${index}`}>
                          <TableCell className="font-mono text-sm">
                            {task.command}
                            {task.dangerous && (
                              <Badge
                                variant="outline"
                                className="ml-2 text-amber-600 border-amber-600"
                              >
                                Destructive
                              </Badge>
                            )}
                          </TableCell>
                          <TableCell>
                            <Badge variant="secondary">{task.schedule}</Badge>
                          </TableCell>
                          <TableCell className="text-muted-foreground text-sm">
                            {formatLastRun(task)}
                          </TableCell>
                          <TableCell className="text-muted-foreground">
                            {task.description || "-"}
                          </TableCell>
                          <TableCell>
                            {task.triggerable ? (
                              <Button
                                variant="outline"
                                size="sm"
                                onClick={() => handleRunNow(task)}
                              >
                                <Play className="mr-2 h-4 w-4" />
                                Run Now
                              </Button>
                            ) : (
                              <span className="text-muted-foreground text-sm">
                                —
                              </span>
                            )}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="queue">
          <Card>
            <CardHeader>
              <CardTitle>Queue Status</CardTitle>
              <CardDescription>
                Current queue statistics
              </CardDescription>
            </CardHeader>
            <CardContent>
              {isLoadingQueue ? (
                <div className="space-y-4">
                  <Skeleton className="h-24 w-full" />
                </div>
              ) : queueStatus ? (
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <Card>
                    <CardHeader className="pb-2">
                      <CardDescription>Pending Jobs</CardDescription>
                    </CardHeader>
                    <CardContent>
                      <div className="text-3xl font-bold">{queueStatus.pending}</div>
                    </CardContent>
                  </Card>
                  <Card>
                    <CardHeader className="pb-2">
                      <CardDescription>Failed Jobs</CardDescription>
                    </CardHeader>
                    <CardContent>
                      <div className="text-3xl font-bold text-destructive">
                        {queueStatus.failed}
                      </div>
                    </CardContent>
                  </Card>
                  {queueStatus.queues && (
                    <Card>
                      <CardHeader className="pb-2">
                        <CardDescription>Queue Breakdown</CardDescription>
                      </CardHeader>
                      <CardContent>
                        <div className="space-y-2">
                          {Object.entries(queueStatus.queues).map(([queue, count]) => (
                            <div key={queue} className="flex justify-between">
                              <span className="text-sm">{queue}</span>
                              <Badge>{count}</Badge>
                            </div>
                          ))}
                        </div>
                      </CardContent>
                    </Card>
                  )}
                </div>
              ) : (
                <div className="text-center py-12 text-muted-foreground">
                  Unable to load queue status
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="failed">
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle>Failed Jobs</CardTitle>
                  <CardDescription>
                    Jobs that failed to execute
                  </CardDescription>
                </div>
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={handleRetryAll}
                    disabled={failedJobs.length === 0}
                  >
                    <Play className="mr-2 h-4 w-4" />
                    Retry All
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={handleClearFailed}
                    disabled={failedJobs.length === 0}
                  >
                    <Trash2 className="mr-2 h-4 w-4" />
                    Clear All
                  </Button>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              {isLoadingFailed ? (
                <div className="space-y-4">
                  {[1, 2, 3].map((i) => (
                    <Skeleton key={i} className="h-24 w-full" />
                  ))}
                </div>
              ) : failedJobs.length === 0 ? (
                <div className="text-center py-12">
                  <CheckCircle className="h-12 w-12 text-green-600 dark:text-green-400 mx-auto mb-4" />
                  <p className="text-muted-foreground">No failed jobs</p>
                </div>
              ) : (
                <div className="space-y-4">
                  {failedJobs.map((job) => (
                    <Card key={job.id}>
                      <CardContent className="pt-6">
                        <div className="flex items-start justify-between">
                          <div className="flex-1 space-y-2">
                            <div className="flex items-center gap-2">
                              <Badge variant="destructive">Failed</Badge>
                              <span className="text-sm text-muted-foreground">
                                {new Date(job.failed_at).toLocaleString()}
                              </span>
                            </div>
                            <div className="font-mono text-sm">
                              Queue: {job.queue}
                            </div>
                            <details className="text-sm">
                              <summary className="cursor-pointer text-muted-foreground">
                                View exception
                              </summary>
                              <pre className="mt-2 p-2 bg-muted rounded text-xs overflow-auto">
                                {job.exception}
                              </pre>
                            </details>
                          </div>
                          <div className="flex gap-2 ml-4">
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() => handleRetryJob(job.id)}
                            >
                              <Play className="mr-2 h-4 w-4" />
                              Retry
                            </Button>
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() => handleDeleteJob(job.id)}
                            >
                              <Trash2 className="mr-2 h-4 w-4" />
                              Delete
                            </Button>
                          </div>
                        </div>
                      </CardContent>
                    </Card>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      <Dialog open={!!runTarget} onOpenChange={(open) => !open && closeRunDialog()}>
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
                <>Run command</>
              )}
            </DialogTitle>
            <DialogDescription>
              {runTarget && runResult === null && (
                <>
                  Run <code className="font-mono text-sm">{runTarget.command}</code> now?
                  {runTarget.dangerous && (
                    <span className="mt-2 flex items-center gap-2 text-amber-600">
                      <AlertTriangle className="h-4 w-4 shrink-0" />
                      This action can delete or modify data.
                    </span>
                  )}
                </>
              )}
              {runTarget && runResult !== null && (
                <>Completed in {runResult.duration_ms}ms</>
              )}
            </DialogDescription>
          </DialogHeader>

          {isRunning && (
            <div className="flex items-center gap-2 py-4">
              <Loader2 className="h-5 w-5 animate-spin" />
              <span>Running {runTarget?.command}…</span>
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
                <Button onClick={confirmRunNow}>Run now</Button>
              </>
            ) : null}
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
