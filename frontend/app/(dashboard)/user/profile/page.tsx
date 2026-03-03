"use client";

import { useState, useEffect, useRef, useCallback, useMemo } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { useAuth } from "@/lib/auth";
import { useMailData } from "@/lib/mail-data-provider";
import { api } from "@/lib/api";
import { getErrorMessage } from "@/lib/utils";
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
import {
  Avatar,
  AvatarFallback,
  AvatarImage,
} from "@/components/ui/avatar";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Separator } from "@/components/ui/separator";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { AlertTriangle, Loader2, Upload, FileUp, CheckCircle, AlertCircle } from "lucide-react";
import { HelpLink } from "@/components/help/help-link";
import { SaveButton } from "@/components/ui/save-button";
import { Badge } from "@/components/ui/badge";

const profileSchema = z.object({
  name: z.string().min(2, "Name must be at least 2 characters"),
  email: z.string().email("Invalid email address"),
});

type ProfileForm = z.infer<typeof profileSchema>;

interface ImportResult {
  imported: number;
  skipped: number;
  failed: number;
  errors: string[];
}

export default function ProfilePage() {
  const { user, fetchUser } = useAuth();
  const { accessibleMailboxes } = useMailData();
  // Exclude catchall mailboxes — importing into a wildcard address is not meaningful.
  // useMemo for stable reference so the default-mailbox effect doesn't re-run every render.
  const importableMailboxes = useMemo(
    () => accessibleMailboxes.filter((m) => m.address !== "*"),
    [accessibleMailboxes]
  );
  const [isLoading, setIsLoading] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [deleteConfirmation, setDeleteConfirmation] = useState("");

  // Import state
  const [importMailboxId, setImportMailboxId] = useState<string>("");
  const [importFormat, setImportFormat] = useState<string>("mbox");
  const [importFile, setImportFile] = useState<File | null>(null);
  const [isImporting, setIsImporting] = useState(false);
  const [importResult, setImportResult] = useState<ImportResult | null>(null);
  const [importAsyncJobId, setImportAsyncJobId] = useState<string | null>(null);
  const [importAsyncStatus, setImportAsyncStatus] = useState<string | null>(null);
  const importFileInputRef = useRef<HTMLInputElement>(null);
  const importPollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const {
    register,
    handleSubmit,
    formState: { errors, isDirty },
    reset,
  } = useForm<ProfileForm>({
    resolver: zodResolver(profileSchema),
    mode: "onBlur",
    defaultValues: {
      name: "",
      email: "",
    },
  });

  useEffect(() => {
    if (user) {
      reset({
        name: user.name,
        email: user.email,
      });
    }
  }, [user, reset]);

  // Set default mailbox when mailboxes load
  useEffect(() => {
    if (importableMailboxes.length > 0 && !importMailboxId) {
      setImportMailboxId(importableMailboxes[0].id.toString());
    }
  }, [importableMailboxes, importMailboxId]);

  // Poll async import job status
  useEffect(() => {
    if (!importAsyncJobId) return;
    let cancelled = false;
    const poll = async () => {
      try {
        const res = await api.get<{ status: string; result?: ImportResult }>(
          `/email/import/${importAsyncJobId}/status`
        );
        if (cancelled) return;
        setImportAsyncStatus(res.data.status);
        if (res.data.status === "completed" || res.data.status === "failed") {
          if (importPollRef.current) {
            clearInterval(importPollRef.current);
            importPollRef.current = null;
          }
          setIsImporting(false);
          if (res.data.result) setImportResult(res.data.result);
        }
      } catch {
        // ignore poll errors
      }
    };
    // Run once immediately, then every 3s
    poll();
    importPollRef.current = setInterval(poll, 3000);
    return () => {
      cancelled = true;
      if (importPollRef.current) {
        clearInterval(importPollRef.current);
        importPollRef.current = null;
      }
    };
  }, [importAsyncJobId]);

  const onSubmit = async (data: ProfileForm) => {
    setIsLoading(true);
    try {
      await api.put("/profile", data);
      await fetchUser();
      toast.success("Profile updated successfully");
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to update profile"));
    } finally {
      setIsLoading(false);
    }
  };

  const handleDeleteAccount = async () => {
    if (deleteConfirmation !== user?.email) {
      toast.error("Please type your email to confirm");
      return;
    }

    setIsDeleting(true);
    try {
      await api.delete("/profile");
      toast.success("Account deleted");
      window.location.href = "/login";
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to delete account"));
    } finally {
      setIsDeleting(false);
    }
  };

  const handleImportFileChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    const selected = e.target.files?.[0];
    if (!selected) return;
    setImportFile(selected);
    setImportResult(null);
    const ext = selected.name.split(".").pop()?.toLowerCase();
    if (ext === "eml") setImportFormat("eml");
    else if (ext === "mbox" || ext === "mbx") setImportFormat("mbox");
  }, []);

  const handleImport = async () => {
    if (!importFile || !importMailboxId) return;
    setIsImporting(true);
    setImportResult(null);
    setImportAsyncJobId(null);
    setImportAsyncStatus(null);

    try {
      const formData = new FormData();
      formData.append("file", importFile);
      formData.append("mailbox_id", importMailboxId);
      formData.append("format", importFormat);

      const res = await api.post<{ status: string; job_id?: string; result?: ImportResult }>(
        "/email/import",
        formData,
        { headers: { "Content-Type": "multipart/form-data" }, timeout: 120000 }
      );

      if (res.data.status === "completed" && res.data.result) {
        setImportResult(res.data.result);
        setIsImporting(false);
        toast.success(`Import complete: ${res.data.result.imported} emails imported`);
      } else if (res.data.status === "queued" && res.data.job_id) {
        setImportAsyncJobId(res.data.job_id);
        setImportAsyncStatus("queued");
        toast.info("Large file queued for processing. Status will update automatically.");
      } else {
        // Unexpected response shape — reset state so the UI doesn't get stuck
        setIsImporting(false);
        toast.error("Unexpected response from server. Please try again.");
      }
    } catch {
      toast.error("Failed to import emails");
      setIsImporting(false);
    }
  };

  const getInitials = (name: string) => {
    return name
      .split(" ")
      .map((n) => n[0])
      .join("")
      .toUpperCase()
      .slice(0, 2);
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Profile</h1>
        <p className="text-muted-foreground">
          Manage your account settings and profile information.{" "}
          <HelpLink articleId="profile" />
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Profile Information</CardTitle>
          <CardDescription>
            Update your account details and personal information.
          </CardDescription>
        </CardHeader>
        <form onSubmit={handleSubmit(onSubmit)}>
          <CardContent className="space-y-6">
            {/* Avatar section */}
            <div className="flex items-center gap-4">
              <Avatar className="h-20 w-20">
                <AvatarImage src={user?.avatar || undefined} />
                <AvatarFallback className="text-lg">
                  {user?.name ? getInitials(user.name) : "?"}
                </AvatarFallback>
              </Avatar>
              <div>
                <p className="font-medium">{user?.name}</p>
                <p className="text-sm text-muted-foreground">{user?.email}</p>
              </div>
            </div>

            <Separator />

            {/* Form fields */}
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="name">Name</Label>
                <Input
                  id="name"
                  {...register("name")}
                  disabled={isLoading}
                />
                {errors.name && (
                  <p className="text-sm text-destructive">
                    {errors.name.message}
                  </p>
                )}
              </div>

              <div className="space-y-2">
                <Label htmlFor="email">Email</Label>
                <Input
                  id="email"
                  type="email"
                  {...register("email")}
                  disabled={isLoading}
                />
                {errors.email && (
                  <p className="text-sm text-destructive">
                    {errors.email.message}
                  </p>
                )}
              </div>
            </div>
          </CardContent>
          <CardFooter className="flex justify-end">
            <SaveButton isDirty={isDirty} isSaving={isLoading} />
          </CardFooter>
        </form>
      </Card>

      {/* Group Memberships (from auth user) */}
      <Card>
        <CardHeader>
          <CardTitle className="text-lg">Group Memberships</CardTitle>
          <CardDescription>
            Groups determine your permissions and access levels.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-wrap gap-2">
            {user?.groups && user.groups.length > 0 ? (
              user.groups.map((g) => (
                <Badge key={g.id} variant="secondary">
                  {g.name}
                </Badge>
              ))
            ) : (
              <span className="text-sm text-muted-foreground">
                No groups assigned
              </span>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Import Emails */}
      {importableMailboxes.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Import Emails</CardTitle>
            <CardDescription>
              Upload an mbox or eml file to import emails into one of your mailboxes.
              Files over 10MB are processed in the background.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label>Destination Mailbox</Label>
                <Select value={importMailboxId} onValueChange={setImportMailboxId}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select a mailbox" />
                  </SelectTrigger>
                  <SelectContent>
                    {importableMailboxes.map((mb) => (
                      <SelectItem key={mb.id} value={mb.id.toString()}>
                        {mb.display_name
                          ? `${mb.display_name} <${mb.address}@${mb.email_domain.name}>`
                          : `${mb.address}@${mb.email_domain.name}`}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label>Format</Label>
                <Select value={importFormat} onValueChange={setImportFormat}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="mbox">mbox (multiple emails)</SelectItem>
                    <SelectItem value="eml">eml (single email)</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="space-y-2">
              <Label>File</Label>
              <input
                ref={importFileInputRef}
                type="file"
                accept=".mbox,.mbx,.eml"
                onChange={handleImportFileChange}
                className="hidden"
                aria-label="Select email file to import"
              />
              <div
                className="flex cursor-pointer flex-col items-center justify-center rounded-md border-2 border-dashed border-muted-foreground/25 p-6 transition hover:border-muted-foreground/50"
                onClick={() => importFileInputRef.current?.click()}
              >
                {importFile ? (
                  <>
                    <FileUp className="mb-2 h-8 w-8 text-muted-foreground" />
                    <p className="text-sm font-medium">{importFile.name}</p>
                    <p className="text-xs text-muted-foreground">
                      {(importFile.size / 1024 / 1024).toFixed(1)} MB
                    </p>
                  </>
                ) : (
                  <>
                    <Upload className="mb-2 h-8 w-8 text-muted-foreground" />
                    <p className="text-sm text-muted-foreground">Click to select a file</p>
                    <p className="text-xs text-muted-foreground">.mbox, .mbx, or .eml up to 100MB</p>
                  </>
                )}
              </div>
            </div>

            {(isImporting || importAsyncStatus) && !importResult && (
              <div className="flex items-center gap-2 rounded-md bg-muted/50 p-3">
                <Loader2 className="h-4 w-4 animate-spin" />
                <span className="text-sm">
                  {importAsyncStatus === "queued" && "Queued for processing..."}
                  {importAsyncStatus === "processing" && "Importing emails..."}
                  {!importAsyncStatus && "Importing..."}
                </span>
              </div>
            )}

            {importResult && (
              <div className="space-y-2 rounded-md border p-3">
                <div className="flex items-center gap-2">
                  {importResult.failed === 0 ? (
                    <CheckCircle className="h-4 w-4 text-green-600" />
                  ) : (
                    <AlertCircle className="h-4 w-4 text-yellow-600" />
                  )}
                  <span className="text-sm font-medium">Import complete</span>
                </div>
                <div className="grid grid-cols-3 gap-2 text-center text-sm">
                  <div>
                    <p className="text-lg font-bold">{importResult.imported}</p>
                    <p className="text-xs text-muted-foreground">Imported</p>
                  </div>
                  <div>
                    <p className="text-lg font-bold">{importResult.skipped}</p>
                    <p className="text-xs text-muted-foreground">Skipped</p>
                  </div>
                  <div>
                    <p className="text-lg font-bold">{importResult.failed}</p>
                    <p className="text-xs text-muted-foreground">Failed</p>
                  </div>
                </div>
                {importResult.errors.length > 0 && (
                  <div className="mt-2 max-h-20 overflow-y-auto text-xs text-destructive">
                    {importResult.errors.map((err, i) => (
                      <p key={i}>{err}</p>
                    ))}
                  </div>
                )}
                <Button
                  variant="outline"
                  size="sm"
                  className="mt-2"
                  onClick={() => {
                    setImportFile(null);
                    setImportResult(null);
                    setImportAsyncJobId(null);
                    setImportAsyncStatus(null);
                  }}
                >
                  Import another file
                </Button>
              </div>
            )}
          </CardContent>
          {!importResult && (
            <CardFooter className="flex justify-end">
              <Button
                onClick={handleImport}
                disabled={isImporting || !importFile || !importMailboxId}
              >
                {isImporting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Import
              </Button>
            </CardFooter>
          )}
        </Card>
      )}

      {/* Danger Zone */}
      <Card className="border-destructive">
        <CardHeader>
          <CardTitle className="text-destructive">Danger Zone</CardTitle>
          <CardDescription>
            Irreversible and destructive actions.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex items-center justify-between">
            <div>
              <p className="font-medium">Delete Account</p>
              <p className="text-sm text-muted-foreground">
                Permanently delete your account and all associated data.
              </p>
            </div>
            <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
              <DialogTrigger asChild>
                <Button variant="destructive">Delete Account</Button>
              </DialogTrigger>
              <DialogContent>
                <DialogHeader>
                  <DialogTitle className="flex items-center gap-2">
                    <AlertTriangle className="h-5 w-5 text-destructive" />
                    Delete Account
                  </DialogTitle>
                  <DialogDescription>
                    This action cannot be undone. This will permanently delete
                    your account and remove all associated data.
                  </DialogDescription>
                </DialogHeader>
                <div className="space-y-4 py-4">
                  <p className="text-sm text-muted-foreground">
                    Please type <strong>{user?.email}</strong> to confirm:
                  </p>
                  <Input
                    value={deleteConfirmation}
                    onChange={(e) => setDeleteConfirmation(e.target.value)}
                    placeholder="your@email.com"
                  />
                </div>
                <DialogFooter>
                  <Button
                    variant="outline"
                    onClick={() => {
                      setDeleteDialogOpen(false);
                      setDeleteConfirmation("");
                    }}
                  >
                    Cancel
                  </Button>
                  <Button
                    variant="destructive"
                    onClick={handleDeleteAccount}
                    disabled={isDeleting || deleteConfirmation !== user?.email}
                  >
                    {isDeleting && (
                      <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Delete Account
                  </Button>
                </DialogFooter>
              </DialogContent>
            </Dialog>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
