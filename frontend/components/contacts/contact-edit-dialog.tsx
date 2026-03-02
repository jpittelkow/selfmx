"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { ContactAvatar } from "@/components/contacts/contact-avatar";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { Loader2 } from "lucide-react";
import { api } from "@/lib/api";
import { toast } from "sonner";

interface Contact {
  id: number;
  email_address: string;
  display_name: string | null;
  notes: string | null;
  email_count: number;
  last_emailed_at: string | null;
}

interface ContactEditDialogProps {
  contact: Contact | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSaved: () => void;
}

export function ContactEditDialog({ contact, open, onOpenChange, onSaved }: ContactEditDialogProps) {
  const [displayName, setDisplayName] = useState(contact?.display_name ?? "");
  const [notes, setNotes] = useState(contact?.notes ?? "");
  const [isSaving, setIsSaving] = useState(false);

  // Reset form when contact changes
  if (contact && displayName === "" && contact.display_name) {
    setDisplayName(contact.display_name);
  }

  const handleSave = async () => {
    if (!contact) return;
    setIsSaving(true);
    try {
      await api.put(`/contacts/${contact.id}`, {
        display_name: displayName || null,
        notes: notes || null,
      });
      toast.success("Contact updated");
      onSaved();
      onOpenChange(false);
    } catch {
      toast.error("Failed to update contact");
    } finally {
      setIsSaving(false);
    }
  };

  if (!contact) return null;

  return (
    <Dialog open={open} onOpenChange={(o) => {
      if (!o) {
        setDisplayName("");
        setNotes("");
      }
      onOpenChange(o);
    }}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Edit Contact</DialogTitle>
        </DialogHeader>

        <div className="space-y-4">
          <div className="flex items-center gap-3">
            <ContactAvatar name={contact.display_name} email={contact.email_address} size="lg" />
            <div>
              <p className="text-sm font-medium">{contact.email_address}</p>
              <p className="text-xs text-muted-foreground">
                {contact.email_count} email{contact.email_count !== 1 ? "s" : ""}
                {contact.last_emailed_at && (
                  <> &middot; Last: {new Date(contact.last_emailed_at).toLocaleDateString()}</>
                )}
              </p>
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="display-name">Display Name</Label>
            <Input
              id="display-name"
              value={displayName}
              onChange={(e) => setDisplayName(e.target.value)}
              placeholder="e.g. John Doe"
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="notes">Notes</Label>
            <Textarea
              id="notes"
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Optional notes about this contact..."
              rows={3}
            />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button onClick={handleSave} disabled={isSaving}>
            {isSaving && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
            Save
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
