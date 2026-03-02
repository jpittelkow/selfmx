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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { EmptyState } from "@/components/ui/empty-state";
import { ShieldBan, ShieldCheck, Plus, Trash2, Loader2 } from "lucide-react";

interface SpamFilterEntry {
  id: number;
  type: "allow" | "block";
  match_type: "exact" | "domain";
  value: string;
  created_at: string;
}

export default function SpamFilterPage() {
  const [entries, setEntries] = useState<SpamFilterEntry[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [newValue, setNewValue] = useState("");
  const [newType, setNewType] = useState<"allow" | "block">("block");
  const [newMatchType, setNewMatchType] = useState<"exact" | "domain">("exact");
  const [isAdding, setIsAdding] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const fetchEntries = useCallback(async () => {
    try {
      const res = await api.get<{ entries: SpamFilterEntry[] }>("/email/spam-filter");
      setEntries(res.data.entries);
    } catch {
      toast.error("Failed to load spam filter lists");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchEntries();
  }, [fetchEntries]);

  const handleAdd = async () => {
    if (!newValue.trim()) return;
    setIsAdding(true);
    try {
      await api.post("/email/spam-filter", {
        type: newType,
        match_type: newMatchType,
        value: newValue.trim(),
      });
      toast.success(`Added to ${newType} list`);
      setNewValue("");
      fetchEntries();
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      toast.error(message || "Failed to add entry");
    } finally {
      setIsAdding(false);
    }
  };

  const handleDelete = async (id: number) => {
    setDeletingId(id);
    try {
      await api.delete(`/email/spam-filter/${id}`);
      toast.success("Entry removed");
      fetchEntries();
    } catch {
      toast.error("Failed to remove entry");
    } finally {
      setDeletingId(null);
    }
  };

  if (isLoading) return <SettingsPageSkeleton />;

  const allowEntries = entries.filter((e) => e.type === "allow");
  const blockEntries = entries.filter((e) => e.type === "block");

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-2xl font-bold tracking-tight">Spam Filter</h2>
        <p className="text-muted-foreground">
          Manage allow and block lists to control which senders can reach your inbox.
        </p>
      </div>

      {/* Add entry form */}
      <Card>
        <CardHeader>
          <CardTitle>Add Entry</CardTitle>
          <CardDescription>
            Add an email address or domain to your allow or block list.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col sm:flex-row gap-3">
            <div className="w-full sm:w-28">
              <Label className="sr-only">List type</Label>
              <Select value={newType} onValueChange={(v) => setNewType(v as "allow" | "block")}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="block">Block</SelectItem>
                  <SelectItem value="allow">Allow</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="w-full sm:w-32">
              <Label className="sr-only">Match type</Label>
              <Select value={newMatchType} onValueChange={(v) => setNewMatchType(v as "exact" | "domain")}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="exact">Email</SelectItem>
                  <SelectItem value="domain">Domain</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="flex-1">
              <Label className="sr-only">Value</Label>
              <Input
                placeholder={newMatchType === "exact" ? "user@example.com" : "example.com"}
                value={newValue}
                onChange={(e) => setNewValue(e.target.value)}
                onKeyDown={(e) => e.key === "Enter" && handleAdd()}
              />
            </div>
            <Button onClick={handleAdd} disabled={isAdding || !newValue.trim()}>
              {isAdding ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}
              <span className="ml-2">Add</span>
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Block list */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <ShieldBan className="h-5 w-5 text-destructive" />
            Block List
          </CardTitle>
          <CardDescription>
            Emails from blocked senders are always marked as spam.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {blockEntries.length === 0 ? (
            <EmptyState
              icon={ShieldBan}
              title="No blocked senders"
              description="Add email addresses or domains to block."
            />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Value</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead className="w-16" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {blockEntries.map((entry) => (
                  <TableRow key={entry.id}>
                    <TableCell className="font-mono text-sm">{entry.value}</TableCell>
                    <TableCell>
                      <Badge variant="outline">{entry.match_type === "domain" ? "Domain" : "Email"}</Badge>
                    </TableCell>
                    <TableCell>
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => handleDelete(entry.id)}
                        disabled={deletingId === entry.id}
                      >
                        {deletingId === entry.id ? (
                          <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                          <Trash2 className="h-4 w-4 text-destructive" />
                        )}
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Allow list */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <ShieldCheck className="h-5 w-5 text-green-600" />
            Allow List
          </CardTitle>
          <CardDescription>
            Emails from allowed senders bypass spam score checks.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {allowEntries.length === 0 ? (
            <EmptyState
              icon={ShieldCheck}
              title="No allowed senders"
              description="Add email addresses or domains to always allow."
            />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Value</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead className="w-16" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {allowEntries.map((entry) => (
                  <TableRow key={entry.id}>
                    <TableCell className="font-mono text-sm">{entry.value}</TableCell>
                    <TableCell>
                      <Badge variant="outline">{entry.match_type === "domain" ? "Domain" : "Email"}</Badge>
                    </TableCell>
                    <TableCell>
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => handleDelete(entry.id)}
                        disabled={deletingId === entry.id}
                      >
                        {deletingId === entry.id ? (
                          <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                          <Trash2 className="h-4 w-4 text-destructive" />
                        )}
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
