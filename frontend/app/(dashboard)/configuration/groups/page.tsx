"use client";

import { useState, useEffect } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { GroupTable, type Group } from "@/components/admin/group-table";
import { GroupDialog } from "@/components/admin/group-dialog";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { EmptyState } from "@/components/ui/empty-state";
import { Plus, UsersRound } from "lucide-react";
import { HelpLink } from "@/components/help/help-link";

interface GroupsResponse {
  data: Group[];
}

export default function GroupsPage() {
  const [groups, setGroups] = useState<Group[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [createDialogOpen, setCreateDialogOpen] = useState(false);

  const fetchGroups = async () => {
    try {
      setIsLoading(true);
      const response = await api.get<GroupsResponse>("/groups");
      setGroups(response.data.data || []);
    } catch (err: unknown) {
      const e = err as Error & { response?: { data?: { message?: string } } };
      toast.error(e.message || e.response?.data?.message || "Failed to load groups");
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchGroups();
  }, []);

  if (isLoading) {
    return <SettingsPageSkeleton />;
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">
            User Groups
          </h1>
          <p className="mt-1 text-muted-foreground">
            Manage user groups and permissions.{" "}
            <HelpLink articleId="groups-management" />
          </p>
        </div>
        <Button
          onClick={() => setCreateDialogOpen(true)}
          className="min-h-[44px] shrink-0"
        >
          <Plus className="mr-2 h-4 w-4" />
          Create Group
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Groups</CardTitle>
          <CardDescription>
            {groups.length} group{groups.length !== 1 ? "s" : ""}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {groups.length === 0 ? (
            <EmptyState
              icon={UsersRound}
              title="No groups yet"
              description="Create groups to organize users and manage permissions."
              action={{ label: "Create Group", onClick: () => setCreateDialogOpen(true) }}
            />
          ) : (
            <GroupTable groups={groups} onGroupsUpdated={fetchGroups} />
          )}
        </CardContent>
      </Card>

      <GroupDialog
        group={null}
        open={createDialogOpen}
        onOpenChange={setCreateDialogOpen}
        onSuccess={() => {
          fetchGroups();
          setCreateDialogOpen(false);
        }}
      />
    </div>
  );
}
