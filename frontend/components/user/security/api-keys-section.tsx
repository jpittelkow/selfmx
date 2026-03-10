"use client";

import { useState, useEffect, useCallback } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { getErrorMessage } from "@/lib/utils";
import { useAppConfig } from "@/lib/app-config";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { CollapsibleCard } from "@/components/ui/collapsible-card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Badge } from "@/components/ui/badge";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
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
import { Skeleton } from "@/components/ui/skeleton";
import {
  Loader2,
  Key,
  Trash2,
  Plus,
  RefreshCw,
  Copy,
  AlertTriangle,
} from "lucide-react";


interface ApiKey {
  id: number;
  name: string;
  key_prefix: string;
  created_at: string;
  last_used_at: string | null;
  expires_at: string | null;
  revoked_at: string | null;
}

function formatRelativeTime(dateStr: string | null): string {
  if (!dateStr) return "Never";
  const date = new Date(dateStr);
  const diffMs = Date.now() - date.getTime();
  const diffMin = Math.floor(diffMs / 60000);
  if (diffMin < 1) return "Just now";
  if (diffMin < 60) return `${diffMin}m ago`;
  const diffHr = Math.floor(diffMin / 60);
  if (diffHr < 24) return `${diffHr}h ago`;
  const diffDay = Math.floor(diffHr / 24);
  if (diffDay < 30) return `${diffDay}d ago`;
  return date.toLocaleDateString();
}

function getExpirationBadge(key: ApiKey): { label: string; variant: "success" | "destructive" | "warning" } {
  if (key.revoked_at) return { label: "Revoked", variant: "destructive" };
  if (!key.expires_at) return { label: "Active", variant: "success" };
  const expiresAt = new Date(key.expires_at);
  const now = new Date();
  if (expiresAt < now) return { label: "Expired", variant: "destructive" };
  const daysUntilExpiry = Math.ceil((expiresAt.getTime() - now.getTime()) / (1000 * 60 * 60 * 24));
  if (daysUntilExpiry <= 7) return { label: `Expires in ${daysUntilExpiry}d`, variant: "warning" };
  return { label: "Active", variant: "success" };
}

export function ApiKeysSection() {
  const { features } = useAppConfig();

  const [apiKeys, setApiKeys] = useState<ApiKey[]>([]);
  const [apiKeysLoading, setApiKeysLoading] = useState(false);
  const [apiKeysError, setApiKeysError] = useState(false);
  const [createKeyDialogOpen, setCreateKeyDialogOpen] = useState(false);
  const [newKeyName, setNewKeyName] = useState("");
  const [newKeyExpires, setNewKeyExpires] = useState("");
  const [isCreatingKey, setIsCreatingKey] = useState(false);
  const [createdKeyPlaintext, setCreatedKeyPlaintext] = useState<string | null>(null);
  const [revokeKeyTarget, setRevokeKeyTarget] = useState<ApiKey | null>(null);
  const [isRevokingKey, setIsRevokingKey] = useState(false);
  const [rotateKeyTarget, setRotateKeyTarget] = useState<ApiKey | null>(null);
  const [isRotatingKey, setIsRotatingKey] = useState(false);
  const [copied, setCopied] = useState(false);

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const fetchApiKeys = useCallback(async () => {
    setApiKeysLoading(true);
    setApiKeysError(false);
    try {
      const response = await api.get("/user/api-keys");
      setApiKeys(response.data.keys || []);
    } catch {
      setApiKeysError(true);
    } finally {
      setApiKeysLoading(false);
    }
  }, []);

  useEffect(() => {
    if (features?.graphqlEnabled) {
      fetchApiKeys();
    }
  }, [features?.graphqlEnabled, fetchApiKeys]);

  const handleCreateKey = async () => {
    if (!newKeyName.trim()) {
      toast.error("Please enter a key name");
      return;
    }
    setIsCreatingKey(true);
    try {
      const payload: { name: string; expires_at?: string } = { name: newKeyName.trim() };
      if (newKeyExpires) payload.expires_at = newKeyExpires;
      const response = await api.post("/user/api-keys", payload);
      setCreatedKeyPlaintext(response.data.key);
      setNewKeyName("");
      setNewKeyExpires("");
      await fetchApiKeys();
      toast.success("API key created");
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to create API key"));
    } finally {
      setIsCreatingKey(false);
    }
  };

  const handleRevokeKey = async () => {
    if (!revokeKeyTarget) return;
    setIsRevokingKey(true);
    try {
      await api.delete(`/user/api-keys/${revokeKeyTarget.id}`);
      toast.success("API key revoked");
      setRevokeKeyTarget(null);
      await fetchApiKeys();
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to revoke API key"));
    } finally {
      setIsRevokingKey(false);
    }
  };

  const handleRotateKey = async () => {
    if (!rotateKeyTarget) return;
    setIsRotatingKey(true);
    try {
      const response = await api.post(`/user/api-keys/${rotateKeyTarget.id}/rotate`);
      setRotateKeyTarget(null);
      setCreatedKeyPlaintext(response.data.key);
      setCreateKeyDialogOpen(true);
      await fetchApiKeys();
      toast.success("API key rotated");
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to rotate API key"));
    } finally {
      setIsRotatingKey(false);
    }
  };

  if (!features?.graphqlEnabled) return null;

  return (
    <>
      <CollapsibleCard
        title="API Keys"
        description="Manage personal API keys for programmatic access via the GraphQL API."
        icon={<Key className="h-5 w-5" />}
        defaultOpen={false}
        headerActions={
          <Button
            size="sm"
            onClick={(e) => {
              e.stopPropagation();
              setCreatedKeyPlaintext(null);
              setNewKeyName("");
              setNewKeyExpires("");
              setCreateKeyDialogOpen(true);
            }}
          >
            <Plus className="mr-2 h-4 w-4" />
            Create API Key
          </Button>
        }
      >
          {apiKeysLoading ? (
            <div className="space-y-3">
              {[1, 2].map((i) => (
                <Skeleton key={i} className="h-12 w-full" />
              ))}
            </div>
          ) : apiKeysError ? (
            <div className="flex flex-col items-center gap-3 py-8 text-center">
              <p className="text-sm text-muted-foreground">Failed to load API keys.</p>
              <Button size="sm" variant="outline" onClick={fetchApiKeys}>
                Retry
              </Button>
            </div>
          ) : apiKeys.length === 0 ? (
            <div className="flex flex-col items-center gap-3 py-8 text-center text-muted-foreground">
              <Key className="h-8 w-8 text-muted-foreground/50" />
              <p className="text-sm">
                API keys let you access the application programmatically via the GraphQL API.
              </p>
              <Button
                size="sm"
                variant="outline"
                onClick={() => {
                  setCreatedKeyPlaintext(null);
                  setNewKeyName("");
                  setNewKeyExpires("");
                  setCreateKeyDialogOpen(true);
                }}
              >
                <Plus className="mr-2 h-4 w-4" />
                Create your first key
              </Button>
            </div>
          ) : (
            <div className="rounded-md border overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Name</TableHead>
                    <TableHead>Prefix</TableHead>
                    <TableHead>Created</TableHead>
                    <TableHead>Last Used</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {apiKeys.map((key) => {
                    const { label, variant } = getExpirationBadge(key);
                    return (
                      <TableRow key={key.id}>
                        <TableCell className="font-medium">{key.name}</TableCell>
                        <TableCell>
                          <button
                            type="button"
                            className="font-mono text-xs text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
                            onClick={() => copyToClipboard(key.key_prefix)}
                            title="Click to copy prefix"
                          >
                            {key.key_prefix}
                          </button>
                        </TableCell>
                        <TableCell className="text-sm">
                          {new Date(key.created_at).toLocaleDateString()}
                        </TableCell>
                        <TableCell className="text-sm">
                          {formatRelativeTime(key.last_used_at)}
                        </TableCell>
                        <TableCell>
                          <Badge variant={variant as Parameters<typeof Badge>[0]["variant"]}>
                            {label}
                          </Badge>
                        </TableCell>
                        <TableCell className="text-right">
                          <div className="flex items-center justify-end gap-1">
                            {!key.revoked_at && (
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setRotateKeyTarget(key)}
                                title="Rotate key"
                              >
                                <RefreshCw className="h-4 w-4" />
                              </Button>
                            )}
                            {!key.revoked_at && (
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setRevokeKeyTarget(key)}
                                title="Revoke key"
                              >
                                <Trash2 className="h-4 w-4" />
                              </Button>
                            )}
                          </div>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </div>
          )}
      </CollapsibleCard>

      {/* Create / Display API Key Dialog */}
      <Dialog
        open={createKeyDialogOpen}
        onOpenChange={(open) => {
          if (!open && createdKeyPlaintext) return;
          if (!open) {
            setCreateKeyDialogOpen(false);
            setCreatedKeyPlaintext(null);
            setNewKeyName("");
            setNewKeyExpires("");
          } else {
            setCreateKeyDialogOpen(true);
          }
        }}
      >
        <DialogContent
          onPointerDownOutside={createdKeyPlaintext ? (e) => e.preventDefault() : undefined}
          onEscapeKeyDown={createdKeyPlaintext ? (e) => e.preventDefault() : undefined}
        >
          <DialogHeader>
            <DialogTitle>
              {createdKeyPlaintext ? "Your API Key" : "Create API Key"}
            </DialogTitle>
            <DialogDescription>
              {createdKeyPlaintext
                ? "Copy this key now — it will only be shown once."
                : "Create a new personal API key for programmatic access."}
            </DialogDescription>
          </DialogHeader>

          {createdKeyPlaintext ? (
            <div className="space-y-4 py-2">
              <Alert variant="destructive">
                <AlertTriangle className="h-4 w-4" />
                <AlertTitle>Important</AlertTitle>
                <AlertDescription>
                  This key will only be shown once. Copy it now and store it securely.
                </AlertDescription>
              </Alert>
              <div className="space-y-1">
                <Label>API Key</Label>
                <div className="flex gap-2">
                  <Input
                    value={createdKeyPlaintext}
                    readOnly
                    className="font-mono text-xs"
                  />
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => copyToClipboard(createdKeyPlaintext)}
                    title="Copy to clipboard"
                  >
                    <Copy className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            </div>
          ) : (
            <div className="space-y-4 py-2">
              <div className="space-y-2">
                <Label htmlFor="api-key-name">Name</Label>
                <Input
                  id="api-key-name"
                  value={newKeyName}
                  onChange={(e) => setNewKeyName(e.target.value)}
                  placeholder="e.g. My script, CI pipeline"
                  autoFocus
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="api-key-expires">Expiration date (optional)</Label>
                <Input
                  id="api-key-expires"
                  type="date"
                  value={newKeyExpires}
                  onChange={(e) => setNewKeyExpires(e.target.value)}
                  min={new Date(Date.now() + 86400000).toISOString().split("T")[0]}
                />
              </div>
            </div>
          )}

          <DialogFooter>
            {createdKeyPlaintext ? (
              <Button
                onClick={() => {
                  setCreatedKeyPlaintext(null);
                  setCreateKeyDialogOpen(false);
                }}
              >
                Done
              </Button>
            ) : (
              <>
                <Button
                  variant="outline"
                  onClick={() => {
                    setCreateKeyDialogOpen(false);
                    setNewKeyName("");
                    setNewKeyExpires("");
                  }}
                >
                  Cancel
                </Button>
                <Button onClick={handleCreateKey} disabled={isCreatingKey || !newKeyName.trim()}>
                  {isCreatingKey && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                  Create
                </Button>
              </>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Revoke API Key Confirmation */}
      <AlertDialog
        open={!!revokeKeyTarget}
        onOpenChange={(open) => { if (!open) setRevokeKeyTarget(null); }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Revoke API Key</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to revoke <strong>{revokeKeyTarget?.name}</strong>?
              This key will stop working immediately and cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isRevokingKey}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleRevokeKey}
              disabled={isRevokingKey}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {isRevokingKey && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Revoke
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Rotate API Key Confirmation */}
      <AlertDialog
        open={!!rotateKeyTarget}
        onOpenChange={(open) => { if (!open) setRotateKeyTarget(null); }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Rotate API Key</AlertDialogTitle>
            <AlertDialogDescription>
              Rotating <strong>{rotateKeyTarget?.name}</strong> will generate a new key.
              The old key will remain valid for a grace period to give you time to update
              your applications, then it will be revoked automatically.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isRotatingKey}>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={handleRotateKey} disabled={isRotatingKey}>
              {isRotatingKey && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Rotate
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
