"use client";

import { useState, useEffect, useCallback } from "react";
import Image from "next/image";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { errorLogger } from "@/lib/error-logger";
import { getErrorMessage } from "@/lib/utils";
import { useAppConfig } from "@/lib/app-config";
import { usePasskeys } from "@/lib/use-passkeys";
import { PasskeyRegisterDialog } from "@/components/auth/passkey-register-dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
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
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Badge } from "@/components/ui/badge";
import { Separator } from "@/components/ui/separator";
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
  Shield,
  Smartphone,
  Key,
  Fingerprint,
  Trash2,
  Link as LinkIcon,
  Unlink,
  Copy,
  Check,
  AlertTriangle,
  Plus,
  RefreshCw,
} from "lucide-react";
import { HelpLink } from "@/components/help/help-link";

const passwordSchema = z
  .object({
    current_password: z.string().min(1, "Current password is required"),
    password: z.string().min(8, "Password must be at least 8 characters"),
    password_confirmation: z.string(),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: "Passwords don't match",
    path: ["password_confirmation"],
  });

type PasswordForm = z.infer<typeof passwordSchema>;

interface TwoFactorStatus {
  enabled: boolean;
  confirmed: boolean;
  recovery_codes_count?: number;
}

interface SSOProvider {
  id: string;
  name: string;
  icon: string;
  connected: boolean;
  nickname?: string;
}

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

export default function SecurityPage() {
  const [isLoading, setIsLoading] = useState(false);
  const [twoFactorStatus, setTwoFactorStatus] = useState<TwoFactorStatus | null>(null);
  const [ssoProviders, setSsoProviders] = useState<SSOProvider[]>([]);
  const [qrCode, setQrCode] = useState<string | null>(null);
  const [setupSecret, setSetupSecret] = useState<string | null>(null);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [showSetupDialog, setShowSetupDialog] = useState(false);
  const [showRecoveryDialog, setShowRecoveryDialog] = useState(false);
  const [verificationCode, setVerificationCode] = useState("");
  const [copied, setCopied] = useState(false);
  const [showPasskeyRegisterDialog, setShowPasskeyRegisterDialog] = useState(false);

  // API key state
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

  const { features } = useAppConfig();
  const passkeyMode = features?.passkeyMode ?? "disabled";
  const passkeysEnabled = passkeyMode !== "disabled";
  const {
    passkeys,
    loading: passkeysLoading,
    supported: passkeySupported,
    registerPasskey,
    deletePasskey,
    fetchPasskeys,
  } = usePasskeys();

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<PasswordForm>({
    resolver: zodResolver(passwordSchema),
  });

  // Fetch 2FA status and SSO providers
  useEffect(() => {
    fetchSecurityStatus();
  }, []);

  const fetchSecurityStatus = async () => {
    try {
      const [twoFactorRes, ssoRes] = await Promise.all([
        api.get("/auth/2fa/status"),
        api.get("/auth/sso/providers"),
      ]);
      setTwoFactorStatus(twoFactorRes.data);
      setSsoProviders(ssoRes.data.providers || []);
    } catch (error) {
      errorLogger.report(
        error instanceof Error ? error : new Error("Failed to fetch security status"),
        { source: "user-security-page" }
      );
    }
  };

  // API key handlers
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

  const onPasswordSubmit = async (data: PasswordForm) => {
    setIsLoading(true);
    try {
      await api.put("/profile/password", data);
      toast.success("Password updated successfully");
      reset();
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to update password"));
    } finally {
      setIsLoading(false);
    }
  };

  const handleEnable2FA = async () => {
    try {
      const response = await api.post("/auth/2fa/enable");
      setQrCode(response.data.qr_code);
      setSetupSecret(response.data.secret);
      setShowSetupDialog(true);
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to enable 2FA"));
    }
  };

  const handleConfirm2FA = async () => {
    if (verificationCode.length !== 6) {
      toast.error("Please enter a 6-digit code");
      return;
    }

    try {
      const response = await api.post("/auth/2fa/confirm", {
        code: verificationCode,
      });
      setRecoveryCodes(response.data.recovery_codes || []);
      setShowSetupDialog(false);
      setShowRecoveryDialog(true);
      setVerificationCode("");
      fetchSecurityStatus();
      toast.success("Two-factor authentication enabled");
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Invalid verification code"));
    }
  };

  const handleDisable2FA = async () => {
    try {
      await api.post("/auth/2fa/disable");
      fetchSecurityStatus();
      toast.success("Two-factor authentication disabled");
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to disable 2FA"));
    }
  };

  const handleViewRecoveryCodes = async () => {
    try {
      const response = await api.get("/auth/2fa/recovery-codes");
      setRecoveryCodes(response.data.recovery_codes || []);
      setShowRecoveryDialog(true);
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to get recovery codes"));
    }
  };

  const handleRegenerateRecoveryCodes = async () => {
    try {
      const response = await api.post("/auth/2fa/recovery-codes/regenerate");
      setRecoveryCodes(response.data.recovery_codes || []);
      toast.success("Recovery codes regenerated");
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to regenerate codes"));
    }
  };

  const handleLinkSSO = (provider: string) => {
    window.location.href = `/api/auth/sso/${provider}?link=true`;
  };

  const handleUnlinkSSO = async (provider: string) => {
    try {
      await api.delete(`/auth/sso/${provider}/unlink`);
      fetchSecurityStatus();
      toast.success(`${provider} account unlinked`);
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to unlink account"));
    }
  };

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Security</h1>
        <p className="text-muted-foreground">
          Manage your password, two-factor authentication, and connected accounts.
        </p>
      </div>

      {/* Password Change */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Key className="h-5 w-5" />
            Change Password
          </CardTitle>
          <CardDescription>
            Update your password to keep your account secure.
          </CardDescription>
        </CardHeader>
        <form onSubmit={handleSubmit(onPasswordSubmit)}>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="current_password">Current Password</Label>
              <Input
                id="current_password"
                type="password"
                {...register("current_password")}
                disabled={isLoading}
              />
              {errors.current_password && (
                <p className="text-sm text-destructive">
                  {errors.current_password.message}
                </p>
              )}
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="password">New Password</Label>
                <Input
                  id="password"
                  type="password"
                  {...register("password")}
                  disabled={isLoading}
                />
                {errors.password && (
                  <p className="text-sm text-destructive">
                    {errors.password.message}
                  </p>
                )}
              </div>

              <div className="space-y-2">
                <Label htmlFor="password_confirmation">Confirm Password</Label>
                <Input
                  id="password_confirmation"
                  type="password"
                  {...register("password_confirmation")}
                  disabled={isLoading}
                />
                {errors.password_confirmation && (
                  <p className="text-sm text-destructive">
                    {errors.password_confirmation.message}
                  </p>
                )}
              </div>
            </div>
          </CardContent>
          <CardFooter>
            <Button type="submit" disabled={isLoading}>
              {isLoading && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Update Password
            </Button>
          </CardFooter>
        </form>
      </Card>

      {/* Two-Factor Authentication */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Smartphone className="h-5 w-5" />
            Two-Factor Authentication
          </CardTitle>
          <CardDescription>
            Add an extra layer of security to your account using an authenticator app.{" "}
            <HelpLink articleId="two-factor" />
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-4">
              <div
                className={`p-3 rounded-full ${
                  twoFactorStatus?.enabled
                    ? "bg-green-500/10 text-green-600 dark:text-green-400"
                    : "bg-muted text-muted-foreground"
                }`}
              >
                <Shield className="h-6 w-6" />
              </div>
              <div>
                <p className="font-medium">
                  {twoFactorStatus?.enabled ? "Enabled" : "Disabled"}
                </p>
                <p className="text-sm text-muted-foreground">
                  {twoFactorStatus?.enabled
                    ? "Your account is protected with 2FA"
                    : "Add 2FA for enhanced security"}
                </p>
              </div>
            </div>
            <div className="flex items-center gap-2">
              {twoFactorStatus?.enabled && (
                <Button variant="outline" onClick={handleViewRecoveryCodes}>
                  Recovery Codes
                </Button>
              )}
              <Switch
                checked={twoFactorStatus?.enabled || false}
                onCheckedChange={(checked) => {
                  if (checked) {
                    handleEnable2FA();
                  } else {
                    handleDisable2FA();
                  }
                }}
              />
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Passkeys */}
      {passkeysEnabled && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Fingerprint className="h-5 w-5" />
              Passkeys
            </CardTitle>
            <CardDescription>
              Sign in with your fingerprint, face, or hardware security key.{" "}
              <HelpLink articleId="passkeys" />
            </CardDescription>
          </CardHeader>
          <CardContent>
            {!passkeySupported ? (
              <Alert>
                <AlertTriangle className="h-4 w-4" />
                <AlertTitle>Not supported</AlertTitle>
                <AlertDescription>
                  Passkeys are not supported in this browser. Use a modern browser
                  (Chrome, Safari, Edge, Firefox) with WebAuthn support.
                </AlertDescription>
              </Alert>
            ) : passkeysLoading ? (
              <p className="text-sm text-muted-foreground">Loading passkeys...</p>
            ) : (
              <div className="space-y-4">
                {passkeys.length === 0 ? (
                  <p className="text-sm text-muted-foreground">
                    No passkeys registered. Add one to sign in with your device.
                  </p>
                ) : (
                  <ul className="space-y-2">
                    {passkeys.map((pk) => (
                      <li
                        key={pk.id}
                        className="flex items-center justify-between py-2 border-b border-border last:border-0"
                      >
                        <div>
                          <p className="font-medium">{pk.alias}</p>
                          {pk.created_at && (
                            <p className="text-xs text-muted-foreground">
                              Added {new Date(pk.created_at).toLocaleDateString()}
                            </p>
                          )}
                        </div>
                        <Button
                          variant="ghost"
                          size="icon"
                          className="text-destructive hover:text-destructive"
                          onClick={async () => {
                            const ok = await deletePasskey(pk.id);
                            if (ok) toast.success("Passkey removed");
                            else toast.error("Failed to remove passkey");
                          }}
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            )}
          </CardContent>
          {passkeySupported && (
            <CardFooter>
              <Button onClick={() => setShowPasskeyRegisterDialog(true)}>
                <Fingerprint className="mr-2 h-4 w-4" />
                Add Passkey
              </Button>
            </CardFooter>
          )}
        </Card>
      )}

      <PasskeyRegisterDialog
        open={showPasskeyRegisterDialog}
        onOpenChange={setShowPasskeyRegisterDialog}
        onSuccess={fetchPasskeys}
        registerPasskey={registerPasskey}
      />

      {/* SSO Connections */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <LinkIcon className="h-5 w-5" />
            Connected Accounts
          </CardTitle>
          <CardDescription>
            Link your account with external providers for easy sign-in.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="space-y-4">
            {ssoProviders.map((provider) => (
              <div
                key={provider.id}
                className="flex items-center justify-between py-2"
              >
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-full bg-muted flex items-center justify-center text-lg">
                    {provider.icon || provider.name[0]}
                  </div>
                  <div>
                    <p className="font-medium capitalize">{provider.name}</p>
                    {provider.connected && provider.nickname && (
                      <p className="text-sm text-muted-foreground">
                        {provider.nickname}
                      </p>
                    )}
                  </div>
                </div>
                {provider.connected ? (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => handleUnlinkSSO(provider.id)}
                  >
                    <Unlink className="mr-2 h-4 w-4" />
                    Disconnect
                  </Button>
                ) : (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => handleLinkSSO(provider.id)}
                  >
                    <LinkIcon className="mr-2 h-4 w-4" />
                    Connect
                  </Button>
                )}
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* API Keys (visible when GraphQL is enabled) */}
      {features?.graphqlEnabled && (
        <Card>
          <CardHeader>
            <div className="flex items-center justify-between gap-4 flex-wrap">
              <div>
                <CardTitle className="flex items-center gap-2">
                  <Key className="h-5 w-5" />
                  API Keys
                </CardTitle>
                <CardDescription>
                  Manage personal API keys for programmatic access via the GraphQL API.{" "}
                  <HelpLink articleId="api-keys" />
                </CardDescription>
              </div>
              <Button
                size="sm"
                onClick={() => {
                  setCreatedKeyPlaintext(null);
                  setNewKeyName("");
                  setNewKeyExpires("");
                  setCreateKeyDialogOpen(true);
                }}
              >
                <Plus className="mr-2 h-4 w-4" />
                Create API Key
              </Button>
            </div>
          </CardHeader>
          <CardContent>
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
          </CardContent>
        </Card>
      )}

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

      {/* 2FA Setup Dialog */}
      <Dialog open={showSetupDialog} onOpenChange={setShowSetupDialog}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Set Up Two-Factor Authentication</DialogTitle>
            <DialogDescription>
              Scan this QR code with your authenticator app, then enter the
              verification code.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            {qrCode && (
              <div className="flex justify-center">
                <Image src={qrCode} alt="2FA QR Code" width={192} height={192} unoptimized />
              </div>
            )}
            {setupSecret && (
              <div className="space-y-2">
                <p className="text-sm text-muted-foreground text-center">
                  Or enter this code manually:
                </p>
                <div className="flex items-center justify-center gap-2">
                  <code className="bg-muted px-2 py-1 rounded text-sm font-mono">
                    {setupSecret}
                  </code>
                  <Button
                    variant="ghost"
                    size="icon"
                    onClick={() => copyToClipboard(setupSecret)}
                  >
                    {copied ? (
                      <Check className="h-4 w-4" />
                    ) : (
                      <Copy className="h-4 w-4" />
                    )}
                  </Button>
                </div>
              </div>
            )}
            <Separator />
            <div className="space-y-2">
              <Label htmlFor="verification_code">Verification Code</Label>
              <Input
                id="verification_code"
                value={verificationCode}
                onChange={(e) =>
                  setVerificationCode(e.target.value.replace(/\D/g, "").slice(0, 6))
                }
                placeholder="000000"
                className="text-center text-2xl tracking-widest"
                maxLength={6}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowSetupDialog(false)}>
              Cancel
            </Button>
            <Button onClick={handleConfirm2FA}>Verify & Enable</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Recovery Codes Dialog */}
      <Dialog open={showRecoveryDialog} onOpenChange={setShowRecoveryDialog}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Recovery Codes</DialogTitle>
            <DialogDescription>
              Save these codes in a secure location. You can use them to access
              your account if you lose access to your authenticator app.
            </DialogDescription>
          </DialogHeader>
          <Alert variant="warning" className="my-4">
            <AlertTriangle className="h-4 w-4" />
            <AlertTitle>Important</AlertTitle>
            <AlertDescription>
              Each code can only be used once. Keep them safe!
            </AlertDescription>
          </Alert>
          <div className="grid grid-cols-2 gap-2 py-4">
            {recoveryCodes.map((code, index) => (
              <code
                key={index}
                className="bg-muted px-3 py-2 rounded text-sm font-mono text-center"
              >
                {code}
              </code>
            ))}
          </div>
          <DialogFooter className="flex-col sm:flex-row gap-2">
            <Button
              variant="outline"
              onClick={() => copyToClipboard(recoveryCodes.join("\n"))}
              className="w-full sm:w-auto"
            >
              {copied ? (
                <Check className="mr-2 h-4 w-4" />
              ) : (
                <Copy className="mr-2 h-4 w-4" />
              )}
              Copy All
            </Button>
            <Button
              variant="outline"
              onClick={handleRegenerateRecoveryCodes}
              className="w-full sm:w-auto"
            >
              Regenerate
            </Button>
            <Button
              onClick={() => setShowRecoveryDialog(false)}
              className="w-full sm:w-auto"
            >
              Done
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
