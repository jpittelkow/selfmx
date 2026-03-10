"use client";

import { useState, useEffect } from "react";
import Image from "next/image";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { errorLogger } from "@/lib/error-logger";
import { getErrorMessage } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import {
  Card,
  CardContent,
  CardDescription,
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
import { Separator } from "@/components/ui/separator";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Shield, Copy, Check, AlertTriangle } from "lucide-react";
import { HelpLink } from "@/components/help/help-link";

interface TwoFactorStatus {
  enabled: boolean;
  confirmed: boolean;
  recovery_codes_count?: number;
}

export function TwoFactorSection() {
  const [twoFactorStatus, setTwoFactorStatus] = useState<TwoFactorStatus | null>(null);
  const [qrCode, setQrCode] = useState<string | null>(null);
  const [setupSecret, setSetupSecret] = useState<string | null>(null);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [showSetupDialog, setShowSetupDialog] = useState(false);
  const [showRecoveryDialog, setShowRecoveryDialog] = useState(false);
  const [verificationCode, setVerificationCode] = useState("");
  const [showDisableDialog, setShowDisableDialog] = useState(false);
  const [disablePassword, setDisablePassword] = useState("");
  const [copied, setCopied] = useState(false);

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const fetchStatus = async () => {
    try {
      const res = await api.get("/auth/2fa/status");
      setTwoFactorStatus(res.data);
    } catch (error) {
      errorLogger.report(
        error instanceof Error ? error : new Error("Failed to fetch 2FA status"),
        { source: "two-factor-section" }
      );
    }
  };

  useEffect(() => {
    fetchStatus();
  }, []);

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
      fetchStatus();
      toast.success("Two-factor authentication enabled");
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Invalid verification code"));
    }
  };

  const handleDisable2FA = async () => {
    try {
      await api.post("/auth/2fa/disable", { password: disablePassword });
      setShowDisableDialog(false);
      setDisablePassword("");
      fetchStatus();
      toast.success("Two-factor authentication disabled");
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Incorrect password"));
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

  return (
    <>
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Shield className="h-5 w-5" />
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
                    setShowDisableDialog(true);
                  }
                }}
              />
            </div>
          </div>
        </CardContent>
      </Card>

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

      {/* Disable 2FA Confirmation Dialog */}
      <Dialog open={showDisableDialog} onOpenChange={(open) => {
        setShowDisableDialog(open);
        if (!open) setDisablePassword("");
      }}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Disable Two-Factor Authentication</DialogTitle>
            <DialogDescription>
              Enter your password to confirm disabling 2FA. This will remove the
              extra layer of security from your account.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="disable_password">Password</Label>
              <Input
                id="disable_password"
                type="password"
                value={disablePassword}
                onChange={(e) => setDisablePassword(e.target.value)}
                placeholder="Enter your password"
                onKeyDown={(e) => {
                  if (e.key === "Enter" && disablePassword) handleDisable2FA();
                }}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => {
              setShowDisableDialog(false);
              setDisablePassword("");
            }}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleDisable2FA}
              disabled={!disablePassword}
            >
              Disable 2FA
            </Button>
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
    </>
  );
}
