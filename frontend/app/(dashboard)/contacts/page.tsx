"use client";

import { useState, useEffect, useCallback } from "react";
import { api } from "@/lib/api";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { ContactAvatar } from "@/components/contacts/contact-avatar";
import { ContactEditDialog } from "@/components/contacts/contact-edit-dialog";
import { ContactMergeDialog } from "@/components/contacts/contact-merge-dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ContentSkeleton } from "@/components/ui/content-skeleton";
import {
  Search,
  Users,
  Pencil,
  Trash2,
  Merge,
  ChevronLeft,
  ChevronRight,
} from "lucide-react";

interface Contact {
  id: number;
  email_address: string;
  display_name: string | null;
  avatar_url: string | null;
  notes: string | null;
  email_count: number;
  last_emailed_at: string | null;
  created_at: string;
}

export default function ContactsPage() {
  const [contacts, setContacts] = useState<Contact[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [editContact, setEditContact] = useState<Contact | null>(null);
  const [showEditDialog, setShowEditDialog] = useState(false);
  const [selectedForMerge, setSelectedForMerge] = useState<Contact[]>([]);
  const [showMergeDialog, setShowMergeDialog] = useState(false);

  const fetchContacts = useCallback(async () => {
    setIsLoading(true);
    try {
      const params = new URLSearchParams({
        page: currentPage.toString(),
        per_page: "25",
        sort: "email_count",
        dir: "desc",
      });
      if (searchQuery) {
        params.set("q", searchQuery);
      }
      const res = await api.get<{
        data: Contact[];
        meta?: { last_page: number };
      }>(`/contacts?${params}`);
      setContacts(res.data.data);
      setTotalPages(res.data.meta?.last_page || 1);
    } catch {
      toast.error("Failed to load contacts");
    } finally {
      setIsLoading(false);
    }
  }, [currentPage, searchQuery]);

  useEffect(() => {
    fetchContacts();
  }, [fetchContacts]);

  const handleSearch = (value: string) => {
    setSearchQuery(value);
    setCurrentPage(1);
  };

  const handleDelete = async (contact: Contact) => {
    if (!confirm(`Delete contact ${contact.display_name || contact.email_address}?`)) {
      return;
    }
    try {
      await api.delete(`/contacts/${contact.id}`);
      toast.success("Contact deleted");
      fetchContacts();
    } catch {
      toast.error("Failed to delete contact");
    }
  };

  const handleEdit = (contact: Contact) => {
    setEditContact(contact);
    setShowEditDialog(true);
  };

  const toggleMergeSelection = (contact: Contact) => {
    setSelectedForMerge((prev) => {
      const exists = prev.find((c) => c.id === contact.id);
      if (exists) {
        return prev.filter((c) => c.id !== contact.id);
      }
      if (prev.length >= 2) {
        return [prev[1], contact];
      }
      return [...prev, contact];
    });
  };

  const handleBackfill = async () => {
    try {
      await api.post("/contacts/backfill");
      toast.success("Contact backfill started");
      fetchContacts();
    } catch {
      toast.error("Failed to start backfill");
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <h2 className="text-2xl font-bold tracking-tight">Contacts</h2>
        <div className="flex items-center gap-2">
          {selectedForMerge.length === 2 && (
            <Button size="sm" variant="outline" onClick={() => setShowMergeDialog(true)}>
              <Merge className="h-4 w-4 mr-1" />
              Merge Selected
            </Button>
          )}
          <Button size="sm" variant="outline" onClick={handleBackfill}>
            Backfill from Emails
          </Button>
        </div>
      </div>

      <Card>
        <CardHeader className="pb-3">
          <div className="flex items-center gap-2">
            <div className="relative flex-1">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search contacts..."
                value={searchQuery}
                onChange={(e) => handleSearch(e.target.value)}
                className="pl-9 h-9"
              />
            </div>
          </div>
        </CardHeader>
        <CardContent className="p-0">
          {isLoading ? (
            <div className="p-4">
              <ContentSkeleton variant="table" />
            </div>
          ) : contacts.length === 0 ? (
            searchQuery ? (
              <EmptyState
                icon={Search}
                title="No contacts found"
                description="Try adjusting your search query"
              />
            ) : (
              <EmptyState
                icon={Users}
                title="No contacts yet"
                description="They'll appear automatically from emails."
              />
            )
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-8"></TableHead>
                  <TableHead>Contact</TableHead>
                  <TableHead className="hidden md:table-cell">Email</TableHead>
                  <TableHead className="text-right">Emails</TableHead>
                  <TableHead className="hidden md:table-cell">Last Contact</TableHead>
                  <TableHead className="w-24"></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {contacts.map((contact) => {
                  const isSelectedForMerge = selectedForMerge.some((c) => c.id === contact.id);
                  return (
                    <TableRow
                      key={contact.id}
                      className={isSelectedForMerge ? "bg-primary/5" : ""}
                    >
                      <TableCell>
                        <input
                          type="checkbox"
                          checked={isSelectedForMerge}
                          onChange={() => toggleMergeSelection(contact)}
                          className="rounded"
                          title="Select for merge"
                        />
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-2">
                          <ContactAvatar
                            name={contact.display_name}
                            email={contact.email_address}
                            size="sm"
                          />
                          <span className="font-medium text-sm">
                            {contact.display_name || contact.email_address}
                          </span>
                        </div>
                      </TableCell>
                      <TableCell className="hidden md:table-cell text-sm text-muted-foreground">
                        {contact.email_address}
                      </TableCell>
                      <TableCell className="text-right text-sm">{contact.email_count}</TableCell>
                      <TableCell className="hidden md:table-cell text-sm text-muted-foreground">
                        {contact.last_emailed_at
                          ? new Date(contact.last_emailed_at).toLocaleDateString()
                          : "Never"}
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-1 justify-end">
                          <Button
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8"
                            onClick={() => handleEdit(contact)}
                            title="Edit"
                          >
                            <Pencil className="h-4 w-4" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8 text-destructive hover:text-destructive"
                            onClick={() => handleDelete(contact)}
                            title="Delete"
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          )}

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="px-4 py-3 border-t flex items-center justify-between">
              <Button
                variant="ghost"
                size="sm"
                disabled={currentPage <= 1}
                onClick={() => setCurrentPage((p) => p - 1)}
              >
                <ChevronLeft className="h-4 w-4" />
              </Button>
              <span className="text-xs text-muted-foreground">
                Page {currentPage} of {totalPages}
              </span>
              <Button
                variant="ghost"
                size="sm"
                disabled={currentPage >= totalPages}
                onClick={() => setCurrentPage((p) => p + 1)}
              >
                <ChevronRight className="h-4 w-4" />
              </Button>
            </div>
          )}
        </CardContent>
      </Card>

      <ContactEditDialog
        contact={editContact}
        open={showEditDialog}
        onOpenChange={setShowEditDialog}
        onSaved={fetchContacts}
      />

      <ContactMergeDialog
        contacts={selectedForMerge}
        open={showMergeDialog}
        onOpenChange={setShowMergeDialog}
        onMerged={() => {
          setSelectedForMerge([]);
          fetchContacts();
        }}
      />
    </div>
  );
}
