"use client";

import { useState, useEffect } from "react";
import { ColumnDef, SortingState, RowSelectionState } from "@tanstack/react-table";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Checkbox } from "@/components/ui/checkbox";
import { DataTable } from "@/components/ui/data-table";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { isAdminUser, AdminUser } from "@/lib/auth";
import { getErrorMessage, getInitials } from "@/lib/utils";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { MoreHorizontal, Shield, ShieldOff, Key, Trash2, Edit, UserX, UserCheck, Mail } from "lucide-react";
import { UserDialog } from "./user-dialog";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

interface UserTableProps {
  users: AdminUser[];
  onUserUpdated: () => void;
  currentUserId?: number;
  sorting?: SortingState;
  onSortingChange?: (sorting: SortingState) => void;
}

export function UserTable({ users, onUserUpdated, currentUserId, sorting, onSortingChange }: UserTableProps) {
  const [editingUser, setEditingUser] = useState<AdminUser | null>(null);
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [passwordDialogOpen, setPasswordDialogOpen] = useState(false);
  const [userToDelete, setUserToDelete] = useState<AdminUser | null>(null);
  const [userToResetPassword, setUserToResetPassword] = useState<AdminUser | null>(null);
  const [newPassword, setNewPassword] = useState("");
  const [isResetting, setIsResetting] = useState(false);
  const [resendVerificationUserId, setResendVerificationUserId] = useState<number | null>(null);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  const [bulkAction, setBulkAction] = useState<"enable" | "disable" | "delete" | null>(null);

  // Clear selection when data changes (page, sort, filter)
  useEffect(() => {
    setRowSelection({});
  }, [users]);

  const selectedCount = Object.keys(rowSelection).length;
  const selectedUserIds = Object.keys(rowSelection).map(Number);

  const handleEdit = (user: AdminUser) => {
    setEditingUser(user);
    setIsDialogOpen(true);
  };

  const handleDelete = (user: AdminUser) => {
    setUserToDelete(user);
    setDeleteDialogOpen(true);
  };

  const handleResetPassword = (user: AdminUser) => {
    setUserToResetPassword(user);
    setPasswordDialogOpen(true);
  };

  const confirmDelete = async () => {
    if (!userToDelete) return;

    try {
      const { api } = await import("@/lib/api");
      await api.delete(`/users/${userToDelete.id}`);
      const { toast } = await import("sonner");
      toast.success("User deleted successfully");
      setDeleteDialogOpen(false);
      setUserToDelete(null);
      onUserUpdated();
    } catch (error: unknown) {
      const { toast } = await import("sonner");
      toast.error(getErrorMessage(error, "Failed to delete user"));
    }
  };

  const confirmResetPassword = async () => {
    if (!userToResetPassword || !newPassword) return;

    setIsResetting(true);
    try {
      const { api } = await import("@/lib/api");
      await api.post(`/users/${userToResetPassword.id}/reset-password`, {
        password: newPassword,
      });
      const { toast } = await import("sonner");
      toast.success("Password reset successfully");
      setPasswordDialogOpen(false);
      setUserToResetPassword(null);
      setNewPassword("");
    } catch (error: unknown) {
      const { toast } = await import("sonner");
      toast.error(getErrorMessage(error, "Failed to reset password"));
    } finally {
      setIsResetting(false);
    }
  };

  const handleToggleAdmin = async (user: AdminUser) => {
    try {
      const { api } = await import("@/lib/api");
      await api.post(`/users/${user.id}/toggle-admin`);
      const { toast } = await import("sonner");
      toast.success("Admin status updated");
      onUserUpdated();
    } catch (error: unknown) {
      const { toast } = await import("sonner");
      toast.error(getErrorMessage(error, "Failed to update admin status"));
    }
  };

  const handleToggleDisabled = async (user: AdminUser) => {
    try {
      const { api } = await import("@/lib/api");
      await api.post(`/users/${user.id}/disable`);
      const { toast } = await import("sonner");
      toast.success(user.disabled_at ? "User enabled successfully" : "User disabled successfully");
      onUserUpdated();
    } catch (error: unknown) {
      const { toast } = await import("sonner");
      toast.error(getErrorMessage(error, "Failed to update user status"));
    }
  };

  const handleResendVerification = async (user: AdminUser) => {
    setResendVerificationUserId(user.id);
    try {
      const { api } = await import("@/lib/api");
      await api.post(`/users/${user.id}/resend-verification`);
      const { toast } = await import("sonner");
      toast.success("Verification email sent successfully");
      onUserUpdated();
    } catch (error: unknown) {
      const { toast } = await import("sonner");
      toast.error(getErrorMessage(error, "Failed to send verification email"));
    } finally {
      setResendVerificationUserId(null);
    }
  };

  const executeBulkAction = async () => {
    if (!bulkAction || selectedUserIds.length === 0) return;

    const { api } = await import("@/lib/api");
    const { toast } = await import("sonner");
    let successCount = 0;
    let failCount = 0;
    let skippedSelf = false;

    for (const userId of selectedUserIds) {
      // Exclude current user from bulk actions
      if (userId === currentUserId) {
        skippedSelf = true;
        continue;
      }

      const user = users.find((u) => u.id === userId);
      if (!user) continue;

      try {
        if (bulkAction === "delete") {
          await api.delete(`/users/${userId}`);
        } else if (
          (bulkAction === "enable" && user.disabled_at) ||
          (bulkAction === "disable" && !user.disabled_at)
        ) {
          // Only toggle users that need changing
          await api.post(`/users/${userId}/disable`);
        } else {
          // Already in desired state
          successCount++;
          continue;
        }
        successCount++;
      } catch {
        failCount++;
      }
    }

    if (skippedSelf) {
      toast.info("Your own account was excluded from the bulk action");
    }
    if (successCount > 0) {
      const actionLabel = bulkAction === "delete" ? "deleted" : bulkAction === "enable" ? "enabled" : "disabled";
      toast.success(`${successCount} user${successCount !== 1 ? "s" : ""} ${actionLabel}`);
    }
    if (failCount > 0) {
      toast.error(`Failed to update ${failCount} user${failCount !== 1 ? "s" : ""}`);
    }

    setBulkAction(null);
    setRowSelection({});
    onUserUpdated();
  };

  const columns: ColumnDef<AdminUser>[] = [
    {
      id: "select",
      header: ({ table }) => (
        <Checkbox
          checked={
            table.getIsAllPageRowsSelected() ||
            (table.getIsSomePageRowsSelected() && "indeterminate")
          }
          onCheckedChange={(value) => table.toggleAllPageRowsSelected(!!value)}
          aria-label="Select all"
        />
      ),
      cell: ({ row }) => (
        <Checkbox
          checked={row.getIsSelected()}
          onCheckedChange={(value) => row.toggleSelected(!!value)}
          aria-label="Select row"
        />
      ),
      enableSorting: false,
    },
    {
      accessorKey: "name",
      header: "User",
      cell: ({ row }) => {
        const user = row.original;
        return (
          <div className="flex items-center gap-3">
            <Avatar className="h-8 w-8">
              <AvatarImage src={user.avatar ?? undefined} alt={user.name} />
              <AvatarFallback className="text-xs">{getInitials(user.name)}</AvatarFallback>
            </Avatar>
            <div>
              <div className="font-medium">{user.name}</div>
              {isAdminUser(user) && (
                <Badge variant="secondary" className="text-xs mt-1">
                  Admin
                </Badge>
              )}
            </div>
          </div>
        );
      },
    },
    {
      accessorKey: "email",
      header: "Email",
    },
    {
      id: "status",
      header: "Status",
      enableSorting: false,
      cell: ({ row }) => {
        const user = row.original;
        return (
          <div className="flex flex-wrap gap-1">
            {user.disabled_at ? (
              <Badge variant="destructive">Disabled</Badge>
            ) : (
              <Badge variant="outline">Active</Badge>
            )}
            {user.email_verified_at ? (
              <Badge variant="success">Verified</Badge>
            ) : (
              <Badge variant="secondary">Unverified</Badge>
            )}
          </div>
        );
      },
    },
    {
      id: "groups",
      header: "Groups",
      enableSorting: false,
      meta: { className: "hidden lg:table-cell" },
      cell: ({ row }) => {
        const user = row.original;
        return (
          <div className="flex flex-wrap gap-1">
            {user.groups?.map((g) => (
              <Badge key={g.id} variant="secondary" className="text-xs">
                {g.name}
              </Badge>
            ))}
            {(!user.groups || user.groups.length === 0) && (
              <span className="text-muted-foreground text-xs">None</span>
            )}
          </div>
        );
      },
    },
    {
      accessorKey: "created_at",
      header: "Created",
      cell: ({ row }) => new Date(row.original.created_at).toLocaleDateString(),
    },
    {
      id: "actions",
      header: () => <span className="sr-only">Actions</span>,
      enableSorting: false,
      meta: { className: "text-right" },
      cell: ({ row }) => {
        const user = row.original;
        return (
          <div className="flex justify-end">
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-11 w-11 min-h-11 min-w-11"
                >
                  <MoreHorizontal className="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuLabel>Actions</DropdownMenuLabel>
                <DropdownMenuItem onClick={() => handleEdit(user)}>
                  <Edit className="mr-2 h-4 w-4" />
                  Edit
                </DropdownMenuItem>
                {!user.email_verified_at && (
                  <DropdownMenuItem
                    onClick={() => handleResendVerification(user)}
                    disabled={resendVerificationUserId === user.id}
                  >
                    <Mail className="mr-2 h-4 w-4" />
                    {resendVerificationUserId === user.id ? "Sending..." : "Resend Verification Email"}
                  </DropdownMenuItem>
                )}
                <DropdownMenuItem onClick={() => handleResetPassword(user)}>
                  <Key className="mr-2 h-4 w-4" />
                  Reset Password
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem onClick={() => handleToggleDisabled(user)}>
                  {user.disabled_at ? (
                    <>
                      <UserCheck className="mr-2 h-4 w-4" />
                      Enable User
                    </>
                  ) : (
                    <>
                      <UserX className="mr-2 h-4 w-4" />
                      Disable User
                    </>
                  )}
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => handleToggleAdmin(user)}>
                  {isAdminUser(user) ? (
                    <>
                      <ShieldOff className="mr-2 h-4 w-4" />
                      Remove Admin
                    </>
                  ) : (
                    <>
                      <Shield className="mr-2 h-4 w-4" />
                      Make Admin
                    </>
                  )}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  onClick={() => handleDelete(user)}
                  className="text-destructive"
                >
                  <Trash2 className="mr-2 h-4 w-4" />
                  Delete
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        );
      },
    },
  ];

  return (
    <>
      {selectedCount > 0 && (
        <div className="flex items-center gap-2 rounded-md border bg-muted/50 px-4 py-2 mb-4">
          <span className="text-sm font-medium">
            {selectedCount} user{selectedCount !== 1 ? "s" : ""} selected
          </span>
          <div className="ml-auto flex gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setBulkAction("enable")}
            >
              <UserCheck className="mr-1.5 h-3.5 w-3.5" />
              Enable
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setBulkAction("disable")}
            >
              <UserX className="mr-1.5 h-3.5 w-3.5" />
              Disable
            </Button>
            <Button
              variant="destructive"
              size="sm"
              onClick={() => setBulkAction("delete")}
            >
              <Trash2 className="mr-1.5 h-3.5 w-3.5" />
              Delete
            </Button>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setRowSelection({})}
            >
              Clear
            </Button>
          </div>
        </div>
      )}

      <DataTable
        columns={columns}
        data={users}
        sorting={sorting}
        onSortingChange={onSortingChange}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(row) => String(row.id)}
      />

      <UserDialog
        user={editingUser}
        open={isDialogOpen}
        onOpenChange={setIsDialogOpen}
        onSuccess={onUserUpdated}
        currentUserId={currentUserId}
      />

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete User</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete {userToDelete?.name}? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleteDialogOpen(false)}>
              Cancel
            </Button>
            <Button variant="destructive" onClick={confirmDelete}>
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={passwordDialogOpen} onOpenChange={(open) => {
        setPasswordDialogOpen(open);
        if (!open) {
          setNewPassword("");
          setUserToResetPassword(null);
        }
      }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Reset Password</DialogTitle>
            <DialogDescription>
              Enter a new password for {userToResetPassword?.name}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="new-password">New Password</Label>
              <Input
                id="new-password"
                type="password"
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                placeholder="Enter new password"
                minLength={8}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setPasswordDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={confirmResetPassword} disabled={!newPassword || isResetting}>
              Reset Password
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Bulk action confirmation dialog */}
      <Dialog open={bulkAction !== null && selectedCount > 0} onOpenChange={() => setBulkAction(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {bulkAction === "delete" ? "Delete" : bulkAction === "enable" ? "Enable" : "Disable"} {selectedCount} user{selectedCount !== 1 ? "s" : ""}
            </DialogTitle>
            <DialogDescription>
              {bulkAction === "delete"
                ? "This action cannot be undone. These users will be permanently deleted."
                : `Are you sure you want to ${bulkAction} ${selectedCount} user${selectedCount !== 1 ? "s" : ""}?`}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setBulkAction(null)}>
              Cancel
            </Button>
            <Button
              variant={bulkAction === "delete" ? "destructive" : "default"}
              onClick={executeBulkAction}
            >
              {bulkAction === "delete" ? "Delete" : bulkAction === "enable" ? "Enable" : "Disable"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
