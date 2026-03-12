"use client";

import { useState, useEffect, useCallback } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  Card,
  CardContent,
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
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { EmptyState } from "@/components/ui/empty-state";
import { PenLine, Plus, Pencil, Trash2, Loader2, Star } from "lucide-react";

interface Signature {
  id: number;
  name: string;
  body: string;
  is_default: boolean;
  created_at: string;
}

export default function SignaturesPage() {
  const [signatures, setSignatures] = useState<Signature[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showEditor, setShowEditor] = useState(false);
  const [editingSignature, setEditingSignature] = useState<Signature | null>(null);
  const [isSaving, setIsSaving] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<Signature | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);
  const [settingDefaultId, setSettingDefaultId] = useState<number | null>(null);

  // Editor state
  const [editorName, setEditorName] = useState("");
  const [editorBody, setEditorBody] = useState("");

  const fetchSignatures = useCallback(async () => {
    try {
      const res = await api.get<{ signatures: Signature[] }>("/email/signatures");
      setSignatures(res.data.signatures);
    } catch {
      toast.error("Failed to load signatures");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchSignatures();
  }, [fetchSignatures]);

  const openCreateDialog = () => {
    setEditingSignature(null);
    setEditorName("");
    setEditorBody("");
    setShowEditor(true);
  };

  const openEditDialog = (sig: Signature) => {
    setEditingSignature(sig);
    setEditorName(sig.name);
    setEditorBody(sig.body);
    setShowEditor(true);
  };

  const handleSave = async () => {
    if (!editorName.trim()) {
      toast.error("Signature name is required");
      return;
    }
    if (!editorBody.trim()) {
      toast.error("Signature body is required");
      return;
    }

    setIsSaving(true);
    try {
      const payload = {
        name: editorName,
        body: editorBody,
        // First signature auto-becomes default
        ...(!editingSignature && signatures.length === 0 ? { is_default: true } : {}),
      };

      if (editingSignature) {
        await api.put(`/email/signatures/${editingSignature.id}`, payload);
        toast.success("Signature updated");
      } else {
        await api.post("/email/signatures", payload);
        toast.success("Signature created");
      }

      setShowEditor(false);
      fetchSignatures();
    } catch {
      toast.error("Failed to save signature");
    } finally {
      setIsSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!deleteTarget) return;
    setIsDeleting(true);
    try {
      await api.delete(`/email/signatures/${deleteTarget.id}`);
      toast.success("Signature deleted");
      setDeleteTarget(null);
      fetchSignatures();
    } catch {
      toast.error("Failed to delete signature");
    } finally {
      setIsDeleting(false);
    }
  };

  const handleSetDefault = async (sig: Signature) => {
    setSettingDefaultId(sig.id);
    try {
      await api.put(`/email/signatures/${sig.id}/default`);
      toast.success(`"${sig.name}" is now your default signature`);
      fetchSignatures();
    } catch {
      toast.error("Failed to set default");
    } finally {
      setSettingDefaultId(null);
    }
  };

  if (isLoading) return <SettingsPageSkeleton />;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">Signatures</h2>
          <p className="text-muted-foreground">
            Manage your email signatures. Assign defaults per mailbox or use your default globally.
          </p>
        </div>
        <Button onClick={openCreateDialog}>
          <Plus className="h-4 w-4 mr-2" />
          Add Signature
        </Button>
      </div>

      {signatures.length === 0 ? (
        <Card>
          <CardContent className="py-12">
            <EmptyState
              icon={PenLine}
              title="No signatures"
              description="Create a signature to automatically append to your outgoing emails."
            />
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-3">
          {signatures.map((sig) => (
            <Card key={sig.id}>
              <CardContent className="py-4">
                <div className="flex items-start gap-3">
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 mb-1">
                      <span className="font-medium">{sig.name}</span>
                      {sig.is_default && <Badge variant="secondary">Default</Badge>}
                    </div>
                    <p className="text-sm text-muted-foreground whitespace-pre-wrap line-clamp-3">
                      {sig.body}
                    </p>
                  </div>
                  <div className="flex items-center gap-1 shrink-0">
                    {!sig.is_default && (
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => handleSetDefault(sig)}
                        disabled={settingDefaultId === sig.id}
                        title="Set as default"
                      >
                        {settingDefaultId === sig.id ? (
                          <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                          <Star className="h-4 w-4" />
                        )}
                      </Button>
                    )}
                    <Button variant="ghost" size="icon" onClick={() => openEditDialog(sig)}>
                      <Pencil className="h-4 w-4" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      onClick={() => setDeleteTarget(sig)}
                    >
                      <Trash2 className="h-4 w-4 text-destructive" />
                    </Button>
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Signature Editor Dialog */}
      <Dialog open={showEditor} onOpenChange={setShowEditor}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>{editingSignature ? "Edit Signature" : "Create Signature"}</DialogTitle>
            <DialogDescription>
              {editingSignature
                ? "Update your email signature."
                : "Create a new email signature for your outgoing messages."}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="sig-name">Name</Label>
              <Input
                id="sig-name"
                value={editorName}
                onChange={(e) => setEditorName(e.target.value)}
                placeholder="e.g., Work, Personal"
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="sig-body">Signature</Label>
              <Textarea
                id="sig-body"
                value={editorBody}
                onChange={(e) => setEditorBody(e.target.value)}
                placeholder={"Best regards,\nJohn Doe\nCompany Inc."}
                rows={6}
                className="resize-y"
              />
              <p className="text-xs text-muted-foreground">
                Plain text. Line breaks will be preserved.
              </p>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setShowEditor(false)}>Cancel</Button>
            <Button onClick={handleSave} disabled={isSaving}>
              {isSaving && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
              {editingSignature ? "Save Changes" : "Create Signature"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation */}
      <AlertDialog open={!!deleteTarget} onOpenChange={(open) => !open && setDeleteTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete signature?</AlertDialogTitle>
            <AlertDialogDescription>
              This will permanently delete &quot;{deleteTarget?.name}&quot;. Mailboxes using this as
              their default will fall back to your user default.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={handleDelete} disabled={isDeleting}>
              {isDeleting && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
