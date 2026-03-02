"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { ContactAvatar } from "@/components/contacts/contact-avatar";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { ArrowRight, Loader2 } from "lucide-react";
import { api } from "@/lib/api";
import { toast } from "sonner";

interface Contact {
  id: number;
  email_address: string;
  display_name: string | null;
  email_count: number;
}

interface ContactMergeDialogProps {
  contacts: Contact[];
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onMerged: () => void;
}

export function ContactMergeDialog({ contacts, open, onOpenChange, onMerged }: ContactMergeDialogProps) {
  const [primaryIndex, setPrimaryIndex] = useState(0);
  const [isMerging, setIsMerging] = useState(false);

  if (contacts.length !== 2) return null;

  const primary = contacts[primaryIndex];
  const secondary = contacts[primaryIndex === 0 ? 1 : 0];

  const handleMerge = async () => {
    setIsMerging(true);
    try {
      await api.post("/contacts/merge", {
        primary_id: primary.id,
        secondary_id: secondary.id,
      });
      toast.success("Contacts merged");
      onMerged();
      onOpenChange(false);
    } catch {
      toast.error("Failed to merge contacts");
    } finally {
      setIsMerging(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Merge Contacts</DialogTitle>
          <DialogDescription>
            The secondary contact will be merged into the primary. Click a contact to set it as primary.
          </DialogDescription>
        </DialogHeader>

        <div className="flex items-center gap-4 py-4">
          {contacts.map((contact, i) => (
            <button
              key={contact.id}
              onClick={() => setPrimaryIndex(i)}
              className={`flex-1 p-3 rounded-lg border-2 text-left transition-colors ${
                i === primaryIndex
                  ? "border-primary bg-primary/5"
                  : "border-muted hover:border-muted-foreground/30"
              }`}
            >
              <div className="flex items-center gap-2 mb-2">
                <ContactAvatar name={contact.display_name} email={contact.email_address} size="sm" />
                <span className="text-xs font-medium">
                  {i === primaryIndex ? "Primary" : "Secondary"}
                </span>
              </div>
              <p className="text-sm font-medium truncate">
                {contact.display_name || contact.email_address}
              </p>
              <p className="text-xs text-muted-foreground truncate">{contact.email_address}</p>
              <p className="text-xs text-muted-foreground mt-1">
                {contact.email_count} email{contact.email_count !== 1 ? "s" : ""}
              </p>
            </button>
          ))}
        </div>

        <div className="flex items-center gap-2 text-sm text-muted-foreground bg-muted/50 rounded-md p-3">
          <ArrowRight className="h-4 w-4 shrink-0" />
          <span>
            <strong>{secondary.email_address}</strong> will be merged into{" "}
            <strong>{primary.email_address}</strong>. Email counts will be combined.
          </span>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button onClick={handleMerge} disabled={isMerging}>
            {isMerging && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
            Merge Contacts
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
