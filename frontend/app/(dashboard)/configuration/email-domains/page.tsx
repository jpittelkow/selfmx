"use client";

import { useState, useEffect, useCallback } from "react";
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
import { Globe, Plus, Trash2, RefreshCw, Loader2, Info, CheckCircle2, XCircle, ChevronRight } from "lucide-react";

const providerLabels: Record<string, string> = {
  mailgun: "Mailgun",
  ses: "AWS SES",
  sendgrid: "SendGrid",
  postmark: "Postmark",
};

interface EmailDomain {
  id: number;
  name: string;
  provider: string;
  is_verified: boolean;
  is_active: boolean;
  verified_at: string | null;
  created_at: string;
}

export default function EmailDomainsPage() {
  const [domains, setDomains] = useState<EmailDomain[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showAddDialog, setShowAddDialog] = useState(false);
  const [newDomain, setNewDomain] = useState("");
  const [newProvider, setNewProvider] = useState("mailgun");
  const [isAdding, setIsAdding] = useState(false);
  const [verifyingId, setVerifyingId] = useState<number | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [dnsRecords, setDnsRecords] = useState<{ domainId: number; records: Array<{ type: string; name: string; value: string; valid: string }> } | null>(null);

  const fetchDomains = useCallback(async () => {
    try {
      const res = await api.get<{ domains: EmailDomain[] }>("/email/domains");
      setDomains(res.data.domains);
    } catch {
      toast.error("Failed to load domains");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchDomains();
  }, [fetchDomains]);

  const handleAdd = async () => {
    if (!newDomain.trim()) return;
    setIsAdding(true);
    try {
      await api.post("/email/domains", { name: newDomain.trim(), provider: newProvider });
      toast.success("Domain added successfully");
      setShowAddDialog(false);
      setNewDomain("");
      setNewProvider("mailgun");
      fetchDomains();
    } catch {
      toast.error("Failed to add domain");
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
          <h1 className="text-2xl font-bold tracking-tight">Email Domains</h1>
          <p className="text-muted-foreground mt-1">
            Manage domains configured for receiving and sending email.
          </p>
        </div>
        <Button onClick={() => setShowAddDialog(true)}>
          <Plus className="mr-2 h-4 w-4" />
          Add Domain
        </Button>
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
                    <TableHead>Provider</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {domains.map((domain) => (
                    <TableRow key={domain.id}>
                      <TableCell className="font-medium">
                        {domain.provider === "mailgun" ? (
                          <Link
                            href={`/configuration/email-domains/${domain.id}`}
                            className="hover:underline underline-offset-4"
                          >
                            {domain.name}
                          </Link>
                        ) : (
                          domain.name
                        )}
                      </TableCell>
                      <TableCell>
                        <Badge variant="outline">{providerLabels[domain.provider] ?? domain.provider}</Badge>
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
                          {domain.provider === "mailgun" && (
                            <Link href={`/configuration/email-domains/${domain.id}`}>
                              <Button variant="outline" size="sm">
                                Manage
                                <ChevronRight className="ml-1 h-3 w-3" />
                              </Button>
                            </Link>
                          )}
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
              <Label>Provider</Label>
              <Select value={newProvider} onValueChange={setNewProvider}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="mailgun">Mailgun</SelectItem>
                  <SelectItem value="ses">AWS SES</SelectItem>
                  <SelectItem value="sendgrid">SendGrid</SelectItem>
                  <SelectItem value="postmark">Postmark</SelectItem>
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                The email provider to use for this domain. Configure credentials on the Email Provider page first.
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
