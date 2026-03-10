"use client";

import { useState, useEffect, useCallback } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { getErrorMessage } from "@/lib/utils";
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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { EmptyState } from "@/components/ui/empty-state";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import Link from "next/link";
import { Globe, Plus, Trash2, RefreshCw, Loader2, Info, CheckCircle2, XCircle, ChevronRight, Search, Circle } from "lucide-react";

const providerLabels: Record<string, string> = {
  mailgun: "Mailgun",
  ses: "AWS SES",
  postmark: "Postmark",
  resend: "Resend",
  mailersend: "MailerSend",
  smtp2go: "SMTP2GO",
};

interface ProviderAccountRef {
  id: number;
  name: string;
  provider: string;
  is_default?: boolean;
  health_status?: string | null;
}

interface EmailDomain {
  id: number;
  name: string;
  provider: string;
  email_provider_account_id: number | null;
  provider_account: ProviderAccountRef | null;
  is_verified: boolean;
  is_active: boolean;
  verified_at: string | null;
  created_at: string;
}

function ProviderHealthBadge() {
  const [health, setHealth] = useState<{ healthy: boolean; latency_ms?: number; provider?: string } | null>(null);

  useEffect(() => {
    api.get<{ healthy: boolean; latency_ms?: number; provider?: string }>("/email/provider/health")
      .then((res) => setHealth(res.data))
      .catch(() => setHealth({ healthy: false }));
  }, []);

  if (!health) return null;

  return (
    <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
      <Circle
        className={`h-2 w-2 fill-current ${health.healthy ? "text-green-500" : "text-red-500"}`}
      />
      <span>{(health.provider && providerLabels[health.provider]) ?? "Provider"}: {health.healthy ? "Connected" : "Unreachable"}</span>
      {health.healthy && health.latency_ms != null && (
        <span className="text-xs">({health.latency_ms}ms)</span>
      )}
    </div>
  );
}

export default function EmailDomainsPage() {
  const [domains, setDomains] = useState<EmailDomain[]>([]);
  const [accounts, setAccounts] = useState<ProviderAccountRef[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showAddDialog, setShowAddDialog] = useState(false);
  const [newDomain, setNewDomain] = useState("");
  const [newAccountId, setNewAccountId] = useState<string>("");
  const [isAdding, setIsAdding] = useState(false);
  const [verifyingId, setVerifyingId] = useState<number | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [dnsRecords, setDnsRecords] = useState<{ domainId: number; records: Array<{ type: string; name: string; value: string; valid: string }> } | null>(null);
  const [searchQuery, setSearchQuery] = useState("");
  const [verifiedFilter, setVerifiedFilter] = useState<"all" | "verified" | "unverified">("all");

  const fetchDomains = useCallback(async () => {
    try {
      const params = new URLSearchParams();
      if (searchQuery.trim()) params.set("search", searchQuery.trim());
      if (verifiedFilter === "verified") params.set("verified", "1");
      if (verifiedFilter === "unverified") params.set("verified", "0");
      const qs = params.toString();
      const res = await api.get<{ domains: EmailDomain[] }>(`/email/domains${qs ? `?${qs}` : ""}`);
      setDomains(res.data.domains);
    } catch {
      toast.error("Failed to load domains");
    } finally {
      setIsLoading(false);
    }
  }, [searchQuery, verifiedFilter]);

  const fetchAccounts = useCallback(async () => {
    try {
      const res = await api.get<{ accounts: ProviderAccountRef[] }>("/email/provider-accounts");
      setAccounts(res.data.accounts);
      // Default to first account if available
      if (res.data.accounts.length > 0 && !newAccountId) {
        const defaultAccount = res.data.accounts.find((a) => a.is_default) || res.data.accounts[0];
        setNewAccountId(String(defaultAccount.id));
      }
    } catch {
      // Non-critical — user may not have settings.view permission
    }
  }, []);

  // Debounce search — refetch 300ms after search/filter changes
  useEffect(() => {
    const timer = setTimeout(() => {
      fetchDomains();
    }, searchQuery ? 300 : 0);
    return () => clearTimeout(timer);
  }, [fetchDomains, searchQuery]);

  useEffect(() => {
    fetchAccounts();
  }, [fetchAccounts]);

  const handleAdd = async () => {
    if (!newDomain.trim()) return;
    setIsAdding(true);
    try {
      const body: Record<string, unknown> = { name: newDomain.trim() };
      if (newAccountId) {
        body.email_provider_account_id = parseInt(newAccountId, 10);
      } else {
        body.provider = "mailgun"; // fallback for installations without accounts
      }
      const res = await api.post<{ domain: unknown; warnings?: string[] }>("/email/domains", body);
      if (res.data.warnings?.length) {
        toast.warning(`Domain created, but: ${res.data.warnings.join("; ")}`);
      } else {
        toast.success("Domain added successfully");
      }
      setShowAddDialog(false);
      setNewDomain("");
      fetchDomains();
    } catch (err) {
      toast.error(getErrorMessage(err, "Failed to add domain"));
    } finally {
      setIsAdding(false);
    }
  };

  const handleVerify = async (id: number) => {
    setVerifyingId(id);
    try {
      const res = await api.post<{ is_verified: boolean; dns_records: Array<{ type: string; name: string; value: string; valid: string }> }>(
        `/email/domains/${id}/verify`
      );
      if (res.data.is_verified) {
        toast.success("Domain verified! DNS records are correctly configured.");
        setDnsRecords(null);
      } else {
        toast.info("Domain not yet verified. Check the DNS records below.");
        if (res.data.dns_records?.length) {
          setDnsRecords({ domainId: id, records: res.data.dns_records });
        }
      }
      fetchDomains();
    } catch {
      toast.error("Verification check failed");
    } finally {
      setVerifyingId(null);
    }
  };

  const handleDelete = async (id: number) => {
    setDeletingId(id);
    try {
      await api.delete(`/email/domains/${id}`);
      toast.success("Domain deleted");
      fetchDomains();
    } catch {
      toast.error("Failed to delete domain");
    } finally {
      setDeletingId(null);
    }
  };

  if (isLoading) return <SettingsPageSkeleton variant="table" />;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-bold tracking-tight">Email Domains</h1>
            <ProviderHealthBadge />
          </div>
          <p className="text-muted-foreground mt-1">
            Manage domains configured for receiving and sending email.
          </p>
        </div>
        <Button onClick={() => setShowAddDialog(true)}>
          <Plus className="mr-2 h-4 w-4" />
          Add Domain
        </Button>
      </div>

      <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3">
        <div className="relative flex-1 w-full sm:max-w-xs">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            placeholder="Search domains..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="pl-9"
          />
        </div>
        <div className="flex gap-1">
          {(["all", "verified", "unverified"] as const).map((filter) => (
            <Button
              key={filter}
              variant={verifiedFilter === filter ? "default" : "outline"}
              size="sm"
              onClick={() => setVerifiedFilter(filter)}
            >
              {filter === "all" ? "All" : filter === "verified" ? "Verified" : "Unverified"}
            </Button>
          ))}
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Domains</CardTitle>
          <CardDescription>
            Add your domain here, then configure DNS records in your domain registrar.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {domains.length === 0 ? (
            <EmptyState
              icon={Globe}
              title="No domains configured"
              description="Add a domain to get started receiving email."
              action={{ label: "Add Domain", onClick: () => setShowAddDialog(true) }}
            />
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Domain</TableHead>
                    <TableHead>Account</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {domains.map((domain) => (
                    <TableRow key={domain.id}>
                      <TableCell className="font-medium">
                        <Link
                          href={`/configuration/email-domains/${domain.id}`}
                          className="hover:underline underline-offset-4"
                        >
                          {domain.name}
                        </Link>
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-2">
                          <Badge variant="outline">{providerLabels[domain.provider] ?? domain.provider}</Badge>
                          {domain.provider_account && (
                            <span className="text-xs text-muted-foreground">{domain.provider_account.name}</span>
                          )}
                        </div>
                      </TableCell>
                      <TableCell>
                        {domain.is_verified ? (
                          <Badge variant="default" className="bg-green-600">Verified</Badge>
                        ) : (
                          <Badge variant="secondary">Unverified</Badge>
                        )}
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex items-center justify-end gap-2">
                          {!domain.is_verified && (
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() => handleVerify(domain.id)}
                              disabled={verifyingId === domain.id}
                            >
                              {verifyingId === domain.id ? (
                                <Loader2 className="mr-1 h-3 w-3 animate-spin" />
                              ) : (
                                <RefreshCw className="mr-1 h-3 w-3" />
                              )}
                              Verify
                            </Button>
                          )}
                          <Link href={`/configuration/email-domains/${domain.id}`}>
                            <Button variant="outline" size="sm">
                              Manage
                              <ChevronRight className="ml-1 h-3 w-3" />
                            </Button>
                          </Link>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => handleDelete(domain.id)}
                            disabled={deletingId === domain.id}
                          >
                            <Trash2 className="h-4 w-4 text-destructive" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>

      <Alert>
        <Info className="h-4 w-4" />
        <AlertTitle>How domain verification works</AlertTitle>
        <AlertDescription>
          When you add a domain, your email provider generates DNS records (SPF, DKIM, MX) that
          you need to add at your domain registrar. Clicking <strong>Verify</strong> checks with the
          provider to confirm these records are in place. Once verified, the domain can send and
          receive email. DNS changes can take up to 48 hours to propagate.
        </AlertDescription>
      </Alert>

      {dnsRecords && (
        <Card>
          <CardHeader>
            <CardTitle>Required DNS Records</CardTitle>
            <CardDescription>
              Add these records to your domain&apos;s DNS settings, then click Verify again.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Type</TableHead>
                    <TableHead>Name</TableHead>
                    <TableHead>Value</TableHead>
                    <TableHead>Status</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {dnsRecords.records.map((record, i) => (
                    <TableRow key={i}>
                      <TableCell className="font-mono text-sm">{record.type}</TableCell>
                      <TableCell className="font-mono text-sm max-w-[200px] truncate">{record.name}</TableCell>
                      <TableCell className="font-mono text-sm max-w-[300px] truncate">{record.value}</TableCell>
                      <TableCell>
                        {record.valid === "valid" ? (
                          <CheckCircle2 className="h-4 w-4 text-green-600" />
                        ) : (
                          <XCircle className="h-4 w-4 text-destructive" />
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </CardContent>
        </Card>
      )}

      <Dialog open={showAddDialog} onOpenChange={setShowAddDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Add Domain</DialogTitle>
            <DialogDescription>
              Enter the domain you want to use for email. You&apos;ll need to configure DNS records after adding it.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label>Domain Name</Label>
              <Input
                placeholder="example.com"
                value={newDomain}
                onChange={(e) => setNewDomain(e.target.value)}
                onKeyDown={(e) => e.key === "Enter" && handleAdd()}
              />
            </div>
            <div className="space-y-2">
              <Label>Provider Account</Label>
              {accounts.length > 0 ? (
                <Select value={newAccountId} onValueChange={setNewAccountId}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select an account" />
                  </SelectTrigger>
                  <SelectContent>
                    {accounts.map((account) => (
                      <SelectItem key={account.id} value={String(account.id)}>
                        {account.name} ({providerLabels[account.provider] ?? account.provider})
                        {account.is_default ? " \u2014 Default" : ""}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              ) : (
                <p className="text-sm text-muted-foreground">
                  No provider accounts configured.{" "}
                  <Link href="/configuration/email-accounts" className="text-primary hover:underline">
                    Add one first
                  </Link>.
                </p>
              )}
              <p className="text-xs text-muted-foreground">
                The provider account to use for this domain. Configure accounts on the Provider Accounts page.
              </p>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowAddDialog(false)}>
              Cancel
            </Button>
            <Button onClick={handleAdd} disabled={isAdding || !newDomain.trim()}>
              {isAdding && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Add Domain
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
