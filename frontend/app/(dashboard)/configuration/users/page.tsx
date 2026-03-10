"use client";

import { useState, useEffect, useCallback } from "react";
import { toast } from "sonner";
import { SortingState } from "@tanstack/react-table";
import { api } from "@/lib/api";
import { useAuth, AdminUser } from "@/lib/auth";
import { useDebounce } from "@/lib/use-debounce";
import { getErrorMessage } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { UserTable } from "@/components/admin/user-table";
import { UserDialog } from "@/components/admin/user-dialog";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useGroups } from "@/lib/use-groups";
import { Plus, Search } from "lucide-react";
import { HelpLink } from "@/components/help/help-link";

interface PaginatedResponse {
  data: AdminUser[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export default function UsersPage() {
  const { user: currentUser } = useAuth();
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);
  const perPage = 15;
  const [createDialogOpen, setCreateDialogOpen] = useState(false);
  const [selectedGroup, setSelectedGroup] = useState<string>("");
  const [sorting, setSorting] = useState<SortingState>([
    { id: "created_at", desc: true },
  ]);
  const { groups } = useGroups();
  const debouncedSearch = useDebounce(search, 300);

  const fetchUsers = useCallback(async () => {
    setIsLoading(true);
    try {
      const params = new URLSearchParams({
        page: currentPage.toString(),
        per_page: perPage.toString(),
      });
      if (debouncedSearch) {
        params.append("search", debouncedSearch);
      }
      if (selectedGroup) {
        params.append("group", selectedGroup);
      }
      if (sorting.length > 0) {
        params.append("sort", sorting[0].id);
        params.append("sort_dir", sorting[0].desc ? "desc" : "asc");
      }

      const response = await api.get<PaginatedResponse>(`/users?${params}`);
      setUsers(response.data.data || []);
      setCurrentPage(response.data.current_page || 1);
      setTotalPages(response.data.last_page || 1);
      setTotal(response.data.total || 0);
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to load users"));
    } finally {
      setIsLoading(false);
    }
  }, [currentPage, perPage, debouncedSearch, selectedGroup, sorting]);

  useEffect(() => {
    fetchUsers();
  }, [fetchUsers]);

  // Reset to page 1 when search or group filter changes
  useEffect(() => {
    setCurrentPage(1);
  }, [debouncedSearch, selectedGroup]);

  const handleSearch = (value: string) => {
    setSearch(value);
  };

  const handleGroupChange = (value: string) => {
    setSelectedGroup(value === "all" ? "" : value);
  };

  const handleSortingChange = (newSorting: SortingState) => {
    setSorting(newSorting);
    setCurrentPage(1);
  };

  // Pagination display helpers
  const startItem = (currentPage - 1) * perPage + 1;
  const endItem = Math.min(currentPage * perPage, total);

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">User Management</h1>
          <p className="text-muted-foreground mt-2">
            Manage application users and permissions.{" "}
            <HelpLink articleId="user-management" />
          </p>
        </div>
        <Button onClick={() => setCreateDialogOpen(true)}>
          <Plus className="mr-2 h-4 w-4" />
          Create User
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Users</CardTitle>
          <CardDescription>
            {total} total user{total !== 1 ? "s" : ""}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search users by name or email..."
                value={search}
                onChange={(e) => handleSearch(e.target.value)}
                className="pl-10"
              />
            </div>
            <Select value={selectedGroup || "all"} onValueChange={handleGroupChange}>
              <SelectTrigger className="w-full sm:w-[180px]">
                <SelectValue placeholder="All groups" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All groups</SelectItem>
                {groups.map((group) => (
                  <SelectItem key={group.id} value={group.slug}>
                    {group.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {isLoading ? (
            <div className="space-y-4">
              {[1, 2, 3].map((i) => (
                <div key={i} className="flex items-center gap-4">
                  <Skeleton className="h-12 w-12 rounded-full" />
                  <div className="space-y-2 flex-1">
                    <Skeleton className="h-4 w-[200px]" />
                    <Skeleton className="h-4 w-[150px]" />
                  </div>
                </div>
              ))}
            </div>
          ) : users.length === 0 ? (
            <div className="text-center py-12 text-muted-foreground">
              {search ? "No users found matching your search" : "No users found"}
            </div>
          ) : (
            <>
              <UserTable
                users={users}
                onUserUpdated={fetchUsers}
                currentUserId={currentUser?.id}
                sorting={sorting}
                onSortingChange={handleSortingChange}
              />

              {totalPages > 1 && (
                <div className="flex items-center justify-between mt-4">
                  <div className="text-sm text-muted-foreground">
                    Showing {startItem}–{endItem} of {total} users
                  </div>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                      disabled={currentPage === 1}
                    >
                      Previous
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                      disabled={currentPage === totalPages}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>

      <UserDialog
        user={null}
        open={createDialogOpen}
        onOpenChange={setCreateDialogOpen}
        onSuccess={() => {
          fetchUsers();
          setCreateDialogOpen(false);
        }}
      />
    </div>
  );
}
