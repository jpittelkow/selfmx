"use client";

import { useState, useEffect, useCallback } from "react";
import { toast } from "sonner";
import { Plus, Trash2, CheckCircle2, XCircle, Loader2, Star, PlugZap } from "lucide-react";
import { api } from "@/lib/api";
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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
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
import { Badge } from "@/components/ui/badge";
import { PasswordInput } from "@/components/ui/password-input";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { EmptyState } from "@/components/ui/empty-state";
import { SaveButton } from "@/components/ui/save-button";
import { Slider } from "@/components/ui/slider";
import { cn } from "@/lib/utils";

interface ProviderAccount {
  id: number;
  user_id: number;
  provider: string;
  name: string;
  is_default: boolean;
  is_active: boolean;
  health_status: string | null;
  last_health_check: string | null;
  domains_count: number;
  created_at: string;
  updated_at: string;
}

const PROVIDERS = [
  { value: "mailgun", label: "Mailgun" },
  { value: "ses", label: "AWS SES" },
  { value: "postmark", label: "Postmark" },
  { value: "resend", label: "Resend" },
  { value: "mailersend", label: "MailerSend" },
  { value: "smtp2go", label: "SMTP2GO" },
] as const;

const providerLabels: Record<string, string> = Object.fromEntries(
  PROVIDERS.map((p) => [p.value, p.label])
);

interface CredentialField {
  key: string;
  label: string;
  placeholder: string;
  type: "password" | "text" | "select";
  options?: { value: string; label: string }[];
}

const CREDENTIAL_FIELDS: Record<string, CredentialField[]> = {
  mailgun: [
    { key: "api_key", label: "API Key", placeholder: "key-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", type: "password" },
    {
      key: "region", label: "Region", placeholder: "", type: "select",
      options: [{ value: "us", label: "US" }, { value: "eu", label: "EU" }],
    },
    { key: "webhook_signing_key", label: "Webhook Signing Key", placeholder: "Mailgun webhook signing key", type: "password" },
  ],
  ses: [
    { key: "access_key_id", label: "Access Key ID", placeholder: "AKIAIOSFODNN7EXAMPLE", type: "password" },
    { key: "secret_access_key", label: "Secret Access Key", placeholder: "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY", type: "password" },
    {
      key: "region", label: "Region", placeholder: "", type: "select",
      options: [
        { value: "us-east-1", label: "US East (N. Virginia)" },
        { value: "us-east-2", label: "US East (Ohio)" },
        { value: "us-west-1", label: "US West (N. California)" },
        { value: "us-west-2", label: "US West (Oregon)" },
        { value: "eu-west-1", label: "EU (Ireland)" },
        { value: "eu-west-2", label: "EU (London)" },
        { value: "eu-central-1", label: "EU (Frankfurt)" },
        { value: "ap-southeast-1", label: "Asia Pacific (Singapore)" },
        { value: "ap-southeast-2", label: "Asia Pacific (Sydney)" },
        { value: "ap-northeast-1", label: "Asia Pacific (Tokyo)" },
      ],
    },
    { key: "configuration_set", label: "Configuration Set (Optional)", placeholder: "my-configuration-set", type: "text" },
  ],
  postmark: [
    { key: "server_token", label: "Server Token", placeholder: "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx", type: "password" },
  ],
  resend: [
    { key: "api_key", label: "API Key", placeholder: "re_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", type: "password" },
    { key: "webhook_signing_secret", label: "Webhook Signing Secret (Optional)", placeholder: "whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", type: "password" },
  ],
  mailersend: [
    { key: "api_key", label: "API Key", placeholder: "mlsn.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", type: "password" },
    { key: "webhook_signing_secret", label: "Webhook Signing Secret (Optional)", placeholder: "Your webhook signing secret", type: "password" },
  ],
  smtp2go: [
    { key: "api_key", label: "API Key", placeholder: "api-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", type: "password" },
  ],
};

interface HostingSettings {
  spam_threshold: string;
  max_attachment_size: string;
}

const defaultHostingSettings: HostingSettings = {
  spam_threshold: "5.0",
  max_attachment_size: "25",
};

export default function EmailAccountsPage() {
  const [accounts, setAccounts] = useState<ProviderAccount[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showAddDialog, setShowAddDialog] = useState(false);
  const [editingAccount, setEditingAccount] = useState<ProviderAccount | null>(null);
  const [deletingAccount, setDeletingAccount] = useState<ProviderAccount | null>(null);
  const [testingId, setTestingId] = useState<number | null>(null);

  // General settings state
  const [hostingSettings, setHostingSettings] = useState<HostingSettings>(defaultHostingSettings);
  const [initialHostingSettings, setInitialHostingSettings] = useState<HostingSettings>(defaultHostingSettings);
  const [isSavingHosting, setIsSavingHosting] = useState(false);
  const isHostingDirty = JSON.stringify(hostingSettings) !== JSON.stringify(initialHostingSettings);

  // Form state
  const [formProvider, setFormProvider] = useState("mailgun");
  const [formName, setFormName] = useState("");
  const [formCredentials, setFormCredentials] = useState<Record<string, string>>({});
  const [isSaving, setIsSaving] = useState(false);

  const fetchAccounts = useCallback(async () => {
    try {
      const res = await api.get<{ accounts: ProviderAccount[] }>("/email/provider-accounts");
      setAccounts(res.data.accounts);
    } catch {
      toast.error("Failed to load provider accounts");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchAccounts();
    api
      .get<{ email_hosting: HostingSettings }>("/email-hosting-settings")
      .then((res) => {
        const loaded = { ...defaultHostingSettings, ...res.data.email_hosting };
        setHostingSettings(loaded);
        setInitialHostingSettings(loaded);
      })
      .catch(() => {/* use defaults */});
  }, [fetchAccounts]);

  const saveHostingSettings = async () => {
    const size = parseFloat(hostingSettings.max_attachment_size);
    if (!hostingSettings.max_attachment_size || isNaN(size) || size < 1) {
      toast.error("Max attachment size must be at least 1 MB");
      return;
    }
    setIsSavingHosting(true);
    try {
      await api.put("/email-hosting-settings", hostingSettings);
      setInitialHostingSettings({ ...hostingSettings });
      toast.success("Hosting settings saved.");
    } catch {
      toast.error("Failed to save hosting settings");
    } finally {
      setIsSavingHosting(false);
    }
  };

  const resetForm = () => {
    setFormProvider("mailgun");
    setFormName("");
    setFormCredentials({});
    setEditingAccount(null);
  };

  const openAddDialog = () => {
    resetForm();
    setShowAddDialog(true);
  };

  const openEditDialog = (account: ProviderAccount) => {
    setEditingAccount(account);
    setFormProvider(account.provider);
    setFormName(account.name);
    setFormCredentials({});
    setShowAddDialog(true);
  };

  const handleSave = async () => {
    if (!formName.trim()) {
      toast.error("Account name is required");
      return;
    }

    setIsSaving(true);
    try {
      if (editingAccount) {
        const data: Record<string, unknown> = { name: formName };
        if (Object.keys(formCredentials).length > 0) {
          data.credentials = formCredentials;
        }
        await api.put(`/email/provider-accounts/${editingAccount.id}`, data);
        toast.success("Provider account updated");
      } else {
        await api.post("/email/provider-accounts", {
          provider: formProvider,
          name: formName,
          credentials: formCredentials,
        });
        toast.success("Provider account created");
      }
      setShowAddDialog(false);
      resetForm();
      await fetchAccounts();
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message || "Failed to save account";
      toast.error(message);
    } finally {
      setIsSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!deletingAccount) return;
    try {
      await api.delete(`/email/provider-accounts/${deletingAccount.id}`);
      toast.success("Provider account deleted");
      setDeletingAccount(null);
      await fetchAccounts();
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message || "Failed to delete account";
      toast.error(message);
    }
  };

  const handleTest = async (account: ProviderAccount) => {
    setTestingId(account.id);
    try {
      const res = await api.post<{ healthy: boolean; latency_ms: number; error?: string }>(
        `/email/provider-accounts/${account.id}/test`
      );
      if (res.data.healthy) {
        toast.success(`${account.name} is healthy (${res.data.latency_ms}ms)`);
      } else {
        toast.error(`${account.name} is unreachable${res.data.error ? `: ${res.data.error}` : ""}`);
      }
      await fetchAccounts();
    } catch {
      toast.error("Connection test failed");
    } finally {
      setTestingId(null);
    }
  };

  const handleSetDefault = async (account: ProviderAccount) => {
    try {
      await api.post(`/email/provider-accounts/${account.id}/default`);
      toast.success(`${account.name} set as default`);
      await fetchAccounts();
    } catch {
      toast.error("Failed to set default");
    }
  };

  if (isLoading) return <SettingsPageSkeleton />;

  const fields = CREDENTIAL_FIELDS[editingAccount?.provider ?? formProvider] ?? [];

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Provider Accounts</h1>
          <p className="text-muted-foreground mt-1">
            Manage email provider accounts. Each account stores credentials for one provider. Domains are linked to accounts.
          </p>
        </div>
        <Button onClick={openAddDialog}>
          <Plus className="mr-2 h-4 w-4" />
          Add Account
        </Button>
      </div>

      {accounts.length === 0 ? (
        <EmptyState
          icon={PlugZap}
          title="No provider accounts"
          description="Add an email provider account to start managing domains."
          action={{ label: "Add Account", onClick: openAddDialog }}
        />
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {accounts.map((account) => (
            <Card key={account.id} className="relative">
              <CardHeader className="pb-3">
                <div className="flex items-start justify-between">
                  <div className="space-y-1">
                    <CardTitle className="text-base flex items-center gap-2">
                      {account.name}
                      {account.is_default && (
                        <Badge variant="secondary" className="text-xs">Default</Badge>
                      )}
                    </CardTitle>
                    <CardDescription>
                      {providerLabels[account.provider] || account.provider}
                      {" \u00b7 "}
                      {account.domains_count} domain{account.domains_count !== 1 ? "s" : ""}
                    </CardDescription>
                  </div>
                  <div className="flex items-center gap-1">
                    {account.health_status === "healthy" && (
                      <CheckCircle2 className="h-4 w-4 text-green-600" />
                    )}
                    {account.health_status === "unhealthy" && (
                      <XCircle className="h-4 w-4 text-destructive" />
                    )}
                  </div>
                </div>
              </CardHeader>
              <CardContent>
                <div className="flex flex-wrap gap-2">
                  <Button variant="outline" size="sm" onClick={() => openEditDialog(account)}>
                    Edit
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => handleTest(account)}
                    disabled={testingId === account.id}
                  >
                    {testingId === account.id ? (
                      <Loader2 className="mr-1 h-3 w-3 animate-spin" />
                    ) : null}
                    Test
                  </Button>
                  {!account.is_default && (
                    <Button variant="outline" size="sm" onClick={() => handleSetDefault(account)}>
                      <Star className="mr-1 h-3 w-3" />
                      Set Default
                    </Button>
                  )}
                  <Button
                    variant="ghost"
                    size="sm"
                    className="text-destructive hover:text-destructive"
                    onClick={() => setDeletingAccount(account)}
                  >
                    <Trash2 className="mr-1 h-3 w-3" />
                    Delete
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Add/Edit Dialog */}
      <Dialog open={showAddDialog} onOpenChange={(open) => { if (!open) { setShowAddDialog(false); resetForm(); } }}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{editingAccount ? "Edit Account" : "Add Provider Account"}</DialogTitle>
            <DialogDescription>
              {editingAccount
                ? "Update the account name or credentials. Leave credentials blank to keep existing values."
                : "Configure a new email provider account with API credentials."}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-2">
            {!editingAccount && (
              <div className="space-y-2">
                <Label>Provider</Label>
                <Select value={formProvider} onValueChange={(v) => { setFormProvider(v); setFormCredentials({}); }}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {PROVIDERS.map((p) => (
                      <SelectItem key={p.value} value={p.value}>{p.label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            )}
            <div className="space-y-2">
              <Label>Account Name</Label>
              <Input
                value={formName}
                onChange={(e) => setFormName(e.target.value)}
                placeholder={`e.g. Production ${providerLabels[formProvider] || ""}`}
              />
              <p className="text-xs text-muted-foreground">A label to identify this account.</p>
            </div>
            {fields.map((field) => (
              <div key={field.key} className="space-y-2">
                <Label>{field.label}</Label>
                {field.type === "select" ? (
                  <Select
                    value={formCredentials[field.key] || field.options?.[0]?.value || ""}
                    onValueChange={(v) => setFormCredentials((prev) => ({ ...prev, [field.key]: v }))}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {field.options?.map((opt) => (
                        <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                ) : field.type === "password" ? (
                  <PasswordInput
                    value={formCredentials[field.key] || ""}
                    onChange={(e) => setFormCredentials((prev) => ({ ...prev, [field.key]: e.target.value }))}
                    placeholder={editingAccount ? "(unchanged)" : field.placeholder}
                  />
                ) : (
                  <Input
                    value={formCredentials[field.key] || ""}
                    onChange={(e) => setFormCredentials((prev) => ({ ...prev, [field.key]: e.target.value }))}
                    placeholder={field.placeholder}
                  />
                )}
              </div>
            ))}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => { setShowAddDialog(false); resetForm(); }}>Cancel</Button>
            <Button onClick={handleSave} disabled={isSaving}>
              {isSaving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              {editingAccount ? "Update" : "Create"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* General Settings */}
      <Card>
        <CardHeader>
          <CardTitle>General Settings</CardTitle>
          <CardDescription>Email hosting settings that apply across all providers.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label>Spam Threshold</Label>
              <span className="text-sm font-medium tabular-nums">{parseFloat(hostingSettings.spam_threshold) || 5.0}</span>
            </div>
            <Slider
              min={1}
              max={10}
              step={0.5}
              value={[parseFloat(hostingSettings.spam_threshold) || 5.0]}
              onValueChange={([v]) =>
                setHostingSettings((s) => ({ ...s, spam_threshold: v.toString() }))
              }
            />
            <p className="text-xs text-muted-foreground">
              Emails with a spam score at or above this value are flagged as spam. Lower values catch more spam but may increase false positives.
            </p>
          </div>
          <div className="space-y-2">
            <Label>Max Attachment Size (MB)</Label>
            <Input
              type="number"
              min="1"
              max="100"
              value={hostingSettings.max_attachment_size}
              onChange={(e) =>
                setHostingSettings((s) => ({ ...s, max_attachment_size: e.target.value }))
              }
              className="w-32"
            />
          </div>
        </CardContent>
        <CardFooter className="flex justify-end">
          <SaveButton type="button" isDirty={isHostingDirty} isSaving={isSavingHosting} onClick={saveHostingSettings} />
        </CardFooter>
      </Card>

      {/* Delete Confirmation */}
      <AlertDialog open={!!deletingAccount} onOpenChange={(open) => { if (!open) setDeletingAccount(null); }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete provider account?</AlertDialogTitle>
            <AlertDialogDescription>
              {deletingAccount?.domains_count
                ? `This account has ${deletingAccount.domains_count} domain(s) linked to it. You must reassign them before deleting.`
                : `This will permanently delete "${deletingAccount?.name}". This action cannot be undone.`}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              disabled={!!deletingAccount?.domains_count}
              className={cn(deletingAccount?.domains_count ? "opacity-50 cursor-not-allowed" : "bg-destructive text-destructive-foreground hover:bg-destructive/90")}
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
