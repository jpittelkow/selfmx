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
import { Textarea } from "@/components/ui/textarea";
import { Plus, Trash2, Loader2, Pencil, Users, X, Globe, Mail as MailIcon } from "lucide-react";

interface EmailDomain {
  id: number;
  name: string;
}

interface Mailbox {
  id: number;
  address: string;
  display_name: string | null;
  signature: string | null;
  is_active: boolean;
  email_domain: EmailDomain;
  user_role?: string;
  created_at: string;
}

interface MailboxMember {
  id: number;
  type: "user" | "group";
  name: string;
  email?: string;
  role: string;
}

export default function MailboxesPage() {
  const [mailboxes, setMailboxes] = useState<Mailbox[]>([]);
  const [domains, setDomains] = useState<EmailDomain[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showAddDialog, setShowAddDialog] = useState(false);
  const [newAddress, setNewAddress] = useState("");
  const [newDisplayName, setNewDisplayName] = useState("");
  const [selectedDomainId, setSelectedDomainId] = useState<string>("");
  const [isAdding, setIsAdding] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [editMailbox, setEditMailbox] = useState<Mailbox | null>(null);
  const [editDisplayName, setEditDisplayName] = useState("");
  const [editSignature, setEditSignature] = useState("");
  const [isSaving, setIsSaving] = useState(false);
  const [membersMailbox, setMembersMailbox] = useState<Mailbox | null>(null);
  const [members, setMembers] = useState<MailboxMember[]>([]);
  const [isMembersLoading, setIsMembersLoading] = useState(false);
  const [addMemberType, setAddMemberType] = useState<"user" | "group">("user");
  const [addMemberSearch, setAddMemberSearch] = useState("");
  const [addMemberRole, setAddMemberRole] = useState("member");
  const [isAddingMember, setIsAddingMember] = useState(false);
  const [searchResults, setSearchResults] = useState<Array<{ id: number; name: string; email?: string }>>([]);
  const [isSearching, setIsSearching] = useState(false);
  const [removingMemberId, setRemovingMemberId] = useState<number | null>(null);

  const fetchData = useCallback(async () => {
    try {
      const [mailboxRes, domainRes] = await Promise.all([
        api.get<{ mailboxes: Mailbox[] }>("/email/mailboxes"),
        api.get<{ domains: EmailDomain[] }>("/email/domains"),
      ]);
      setMailboxes(mailboxRes.data.mailboxes);
      setDomains(domainRes.data.domains);
    } catch {
      toast.error("Failed to load mailboxes");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  const handleAdd = async () => {
    if (!newAddress.trim() || !selectedDomainId) return;
    setIsAdding(true);
    try {
      await api.post("/email/mailboxes", {
        email_domain_id: parseInt(selectedDomainId),
        address: newAddress.trim(),
        display_name: newDisplayName.trim() || null,
      });
      toast.success("Mailbox created successfully");
      setShowAddDialog(false);
      setNewAddress("");
      setNewDisplayName("");
      setSelectedDomainId("");
      fetchData();
    } catch {
      toast.error("Failed to create mailbox");
    } finally {
      setIsAdding(false);
    }
  };

  const handleDelete = async (id: number) => {
    setDeletingId(id);
    try {
      await api.delete(`/email/mailboxes/${id}`);
      toast.success("Mailbox deleted");
      fetchData();
    } catch {
      toast.error("Failed to delete mailbox");
    } finally {
      setDeletingId(null);
    }
  };

  const openEditDialog = (mailbox: Mailbox) => {
    setEditMailbox(mailbox);
    setEditDisplayName(mailbox.display_name || "");
    setEditSignature(mailbox.signature || "");
  };

  const handleSaveEdit = async () => {
    if (!editMailbox) return;
    setIsSaving(true);
    try {
      await api.put(`/email/mailboxes/${editMailbox.id}`, {
        display_name: editDisplayName.trim() || null,
        signature: editSignature.trim() || null,
      });
      toast.success("Mailbox updated");
      setEditMailbox(null);
      fetchData();
    } catch {
      toast.error("Failed to update mailbox");
    } finally {
      setIsSaving(false);
    }
  };

  const openMembersDialog = async (mailbox: Mailbox) => {
    setMembersMailbox(mailbox);
    setIsMembersLoading(true);
    try {
      const res = await api.get<{ members: MailboxMember[] }>(`/email/mailboxes/${mailbox.id}/members`);
      setMembers(res.data.members);
    } catch {
      toast.error("Failed to load members");
    } finally {
      setIsMembersLoading(false);
    }
  };

  const handleSearchUsers = useCallback(async (query: string) => {
    if (query.length < 2) {
      setSearchResults([]);
      return;
    }
    setIsSearching(true);
    try {
      const endpoint = addMemberType === "user" ? "/users" : "/user-groups";
      const res = await api.get<{ data: Array<{ id: number; name: string; email?: string }> }>(
        `${endpoint}?search=${encodeURIComponent(query)}&per_page=10`
      );
      setSearchResults(res.data.data || []);
    } catch {
      setSearchResults([]);
    } finally {
      setIsSearching(false);
    }
  }, [addMemberType]);

  useEffect(() => {
    const timer = setTimeout(() => {
      handleSearchUsers(addMemberSearch);
    }, 300);
    return () => clearTimeout(timer);
  }, [addMemberSearch, handleSearchUsers]);

  const handleAddMember = async (targetId: number) => {
    if (!membersMailbox) return;
    setIsAddingMember(true);
    try {
      await api.post(`/email/mailboxes/${membersMailbox.id}/members`, {
        type: addMemberType,
        target_id: targetId,
        role: addMemberRole,
      });
      toast.success(`${addMemberType === "user" ? "User" : "Group"} added`);
      setAddMemberSearch("");
      setSearchResults([]);
      // Refresh members list
      const res = await api.get<{ members: MailboxMember[] }>(`/email/mailboxes/${membersMailbox.id}/members`);
      setMembers(res.data.members);
    } catch {
      toast.error("Failed to add member");
    } finally {
      setIsAddingMember(false);
    }
  };

  const handleUpdateMemberRole = async (memberId: number, newRole: string) => {
    if (!membersMailbox) return;
    try {
      await api.put(`/email/mailboxes/${membersMailbox.id}/members/${memberId}`, { role: newRole });
      setMembers((prev) => prev.map((m) => (m.id === memberId ? { ...m, role: newRole } : m)));
      toast.success("Role updated");
    } catch {
      toast.error("Failed to update role");
    }
  };

  const handleRemoveMember = async (memberId: number) => {
    if (!membersMailbox) return;
    setRemovingMemberId(memberId);
    try {
      await api.delete(`/email/mailboxes/${membersMailbox.id}/members/${memberId}`);
      setMembers((prev) => prev.filter((m) => m.id !== memberId));
      toast.success("Member removed");
    } catch {
      toast.error("Failed to remove member");
    } finally {
      setRemovingMemberId(null);
    }
  };

  const selectedDomain = domains.find((d) => d.id === parseInt(selectedDomainId));

  if (isLoading) return <SettingsPageSkeleton />;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Mailboxes</h1>
          <p className="text-muted-foreground mt-1">
            Manage email addresses for your domains.
          </p>
        </div>
        <Button onClick={() => setShowAddDialog(true)} disabled={domains.length === 0}>
          <Plus className="mr-2 h-4 w-4" />
          Add Mailbox
        </Button>
      </div>

      {domains.length === 0 && (
        <Card>
          <CardContent className="pt-6">
            <EmptyState
              icon={Globe}
              title="No domains configured"
              description="Add a domain first before creating mailboxes."
            />
          </CardContent>
        </Card>
      )}

      {domains.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Email Addresses</CardTitle>
            <CardDescription>
              Each mailbox represents an email address that can send and receive mail. Use * for a catchall address.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {mailboxes.length === 0 ? (
              <EmptyState
                icon={MailIcon}
                title="No mailboxes"
                description="Add a mailbox to start receiving email."
                action={{ label: "Add Mailbox", onClick: () => setShowAddDialog(true) }}
              />
            ) : (
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Email Address</TableHead>
                      <TableHead>Display Name</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead>Members</TableHead>
                      <TableHead className="text-right">Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {mailboxes.map((mailbox) => (
                      <TableRow key={mailbox.id}>
                        <TableCell className="font-medium">
                          {mailbox.address === "*" ? (
                            <span>
                              <Badge variant="outline" className="mr-2">catchall</Badge>
                              *@{mailbox.email_domain.name}
                            </span>
                          ) : (
                            `${mailbox.address}@${mailbox.email_domain.name}`
                          )}
                        </TableCell>
                        <TableCell>{mailbox.display_name || "—"}</TableCell>
                        <TableCell>
                          {mailbox.is_active ? (
                            <Badge variant="default" className="bg-green-600">Active</Badge>
                          ) : (
                            <Badge variant="secondary">Inactive</Badge>
                          )}
                        </TableCell>
                        <TableCell>
                          {mailbox.user_role === "owner" ? (
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => openMembersDialog(mailbox)}
                              title="Manage members"
                            >
                              <Users className="mr-1 h-4 w-4" />
                              Manage
                            </Button>
                          ) : (
                            <span className="text-xs text-muted-foreground capitalize">{mailbox.user_role}</span>
                          )}
                        </TableCell>
                        <TableCell className="text-right">
                          {mailbox.user_role === "owner" && (
                            <>
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => openEditDialog(mailbox)}
                                title="Edit mailbox"
                              >
                                <Pencil className="h-4 w-4" />
                              </Button>
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => handleDelete(mailbox.id)}
                                disabled={deletingId === mailbox.id}
                                title="Delete mailbox"
                              >
                                <Trash2 className="h-4 w-4 text-destructive" />
                              </Button>
                            </>
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
      )}

      <Dialog open={showAddDialog} onOpenChange={setShowAddDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Add Mailbox</DialogTitle>
            <DialogDescription>
              Create a new email address. Use * as the address for a catchall mailbox.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label>Domain</Label>
              <Select value={selectedDomainId} onValueChange={setSelectedDomainId}>
                <SelectTrigger>
                  <SelectValue placeholder="Select a domain" />
                </SelectTrigger>
                <SelectContent>
                  {domains.map((d) => (
                    <SelectItem key={d.id} value={d.id.toString()}>
                      {d.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>Address</Label>
              <div className="flex items-center gap-2">
                <Input
                  placeholder="hello"
                  value={newAddress}
                  onChange={(e) => setNewAddress(e.target.value)}
                />
                <span className="text-muted-foreground whitespace-nowrap">
                  @{selectedDomain?.name || "domain.com"}
                </span>
              </div>
              <p className="text-xs text-muted-foreground">
                Use * for a catchall that receives mail to any address on this domain.
              </p>
            </div>
            <div className="space-y-2">
              <Label>Display Name (optional)</Label>
              <Input
                placeholder="John Doe"
                value={newDisplayName}
                onChange={(e) => setNewDisplayName(e.target.value)}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowAddDialog(false)}>
              Cancel
            </Button>
            <Button onClick={handleAdd} disabled={isAdding || !newAddress.trim() || !selectedDomainId}>
              {isAdding && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Create Mailbox
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Edit mailbox dialog */}
      <Dialog open={!!editMailbox} onOpenChange={(open) => !open && setEditMailbox(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit Mailbox</DialogTitle>
            <DialogDescription>
              Update display name and email signature for{" "}
              {editMailbox && `${editMailbox.address}@${editMailbox.email_domain.name}`}.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label>Display Name</Label>
              <Input
                placeholder="John Doe"
                value={editDisplayName}
                onChange={(e) => setEditDisplayName(e.target.value)}
              />
            </div>
            <div className="space-y-2">
              <Label>Signature</Label>
              <Textarea
                placeholder="Your email signature..."
                value={editSignature}
                onChange={(e) => setEditSignature(e.target.value)}
                rows={5}
                className="resize-y"
              />
              <p className="text-xs text-muted-foreground">
                This signature will be automatically appended to emails sent from this mailbox.
              </p>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditMailbox(null)}>
              Cancel
            </Button>
            <Button onClick={handleSaveEdit} disabled={isSaving}>
              {isSaving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Save
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Members management dialog */}
      <Dialog open={!!membersMailbox} onOpenChange={(open) => { if (!open) { setMembersMailbox(null); setAddMemberSearch(""); setSearchResults([]); } }}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Manage Members</DialogTitle>
            <DialogDescription>
              Control who has access to{" "}
              {membersMailbox && (membersMailbox.address === "*"
                ? `catchall@${membersMailbox.email_domain.name}`
                : `${membersMailbox.address}@${membersMailbox.email_domain.name}`)}.
            </DialogDescription>
          </DialogHeader>

          {isMembersLoading ? (
            <div className="flex justify-center py-8">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <div className="space-y-4">
              {/* Current members */}
              {members.length === 0 ? (
                <p className="text-sm text-muted-foreground text-center py-4">
                  No additional members. Only you have access.
                </p>
              ) : (
                <div className="space-y-2 max-h-60 overflow-y-auto">
                  {members.map((member) => (
                    <div key={`${member.type}-${member.id}`} className="flex items-center justify-between gap-2 py-1.5 px-2 rounded-md border">
                      <div className="flex items-center gap-2 min-w-0">
                        <Badge variant="outline" className="shrink-0 text-xs">
                          {member.type}
                        </Badge>
                        <div className="min-w-0">
                          <p className="text-sm font-medium truncate">{member.name}</p>
                          {member.email && (
                            <p className="text-xs text-muted-foreground truncate">{member.email}</p>
                          )}
                        </div>
                      </div>
                      <div className="flex items-center gap-1.5 shrink-0">
                        <Select
                          value={member.role}
                          onValueChange={(role) => handleUpdateMemberRole(member.id, role)}
                        >
                          <SelectTrigger className="h-7 w-24 text-xs">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="viewer">Viewer</SelectItem>
                            <SelectItem value="member">Member</SelectItem>
                            <SelectItem value="owner">Owner</SelectItem>
                          </SelectContent>
                        </Select>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-7 w-7 p-0"
                          onClick={() => handleRemoveMember(member.id)}
                          disabled={removingMemberId === member.id}
                          title="Remove member"
                        >
                          {removingMemberId === member.id ? (
                            <Loader2 className="h-3.5 w-3.5 animate-spin" />
                          ) : (
                            <X className="h-3.5 w-3.5 text-destructive" />
                          )}
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              )}

              {/* Add member section */}
              <div className="border-t pt-4 space-y-3">
                <p className="text-sm font-medium">Add Member</p>
                <div className="flex gap-2">
                  <Select value={addMemberType} onValueChange={(v) => { setAddMemberType(v as "user" | "group"); setAddMemberSearch(""); setSearchResults([]); }}>
                    <SelectTrigger className="w-24 h-9">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="user">User</SelectItem>
                      <SelectItem value="group">Group</SelectItem>
                    </SelectContent>
                  </Select>
                  <Select value={addMemberRole} onValueChange={setAddMemberRole}>
                    <SelectTrigger className="w-28 h-9">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="viewer">Viewer</SelectItem>
                      <SelectItem value="member">Member</SelectItem>
                      <SelectItem value="owner">Owner</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="relative">
                  <Input
                    placeholder={`Search ${addMemberType === "user" ? "users" : "groups"}...`}
                    value={addMemberSearch}
                    onChange={(e) => setAddMemberSearch(e.target.value)}
                    className="h-9"
                  />
                  {isSearching && (
                    <Loader2 className="absolute right-2 top-2.5 h-4 w-4 animate-spin text-muted-foreground" />
                  )}
                </div>
                {searchResults.length > 0 && (
                  <div className="border rounded-md max-h-40 overflow-y-auto">
                    {searchResults.map((result) => (
                      <button
                        key={result.id}
                        type="button"
                        className="w-full flex items-center justify-between px-3 py-2 text-sm hover:bg-muted/50 transition-colors"
                        onClick={() => handleAddMember(result.id)}
                        disabled={isAddingMember}
                      >
                        <div className="text-left min-w-0">
                          <p className="font-medium truncate">{result.name}</p>
                          {result.email && (
                            <p className="text-xs text-muted-foreground truncate">{result.email}</p>
                          )}
                        </div>
                        {isAddingMember ? (
                          <Loader2 className="h-3.5 w-3.5 animate-spin shrink-0" />
                        ) : (
                          <Plus className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                        )}
                      </button>
                    ))}
                  </div>
                )}
              </div>
            </div>
          )}

          <DialogFooter>
            <Button variant="outline" onClick={() => { setMembersMailbox(null); setAddMemberSearch(""); setSearchResults([]); }}>
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
