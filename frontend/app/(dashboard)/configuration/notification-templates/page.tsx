"use client";

import { useState, useEffect, useMemo } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { formatDate } from "@/lib/utils";
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
import { Badge } from "@/components/ui/badge";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { AlertTriangle, ChevronRight } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { HelpLink } from "@/components/help/help-link";
import {
  getNotificationType,
  getNotificationCategory,
  getCategoryLabel,
  getAllCategories,
  CHANNEL_GROUP_LABELS,
  type NotificationCategory,
} from "@/lib/notification-types";

interface NotificationTemplateSummary {
  id: number;
  type: string;
  channel_group: string;
  title: string;
  is_system: boolean;
  is_active: boolean;
  updated_at: string;
}

const channelGroupLabel = CHANNEL_GROUP_LABELS;

export default function NotificationTemplatesListPage() {
  const router = useRouter();
  const [isLoading, setIsLoading] = useState(true);
  const [templates, setTemplates] = useState<NotificationTemplateSummary[]>([]);
  const [novuEnabled, setNovuEnabled] = useState(false);

  const groupedTemplates = useMemo(() => {
    return templates.reduce<
      Record<string, NotificationTemplateSummary[]>
    >((acc, t) => {
      const cat = getNotificationCategory(t.type);
      if (!acc[cat]) acc[cat] = [];
      acc[cat].push(t);
      return acc;
    }, {});
  }, [templates]);

  useEffect(() => {
    fetchTemplates();
    checkNovuStatus();
  }, []);

  const checkNovuStatus = async () => {
    try {
      const res = await api.get("/novu-settings");
      const settings = res.data?.settings ?? {};
      setNovuEnabled(settings.enabled === true && !!settings.api_key);
    } catch {
      // Silently ignore — user may not have settings.view permission
    }
  };

  const fetchTemplates = async () => {
    setIsLoading(true);
    try {
      const response = await api.get("/notification-templates");
      const data = response.data?.data ?? response.data;
      setTemplates(Array.isArray(data) ? data : []);
    } catch (error: unknown) {
      const message =
        error && typeof error === "object" && "response" in error
          ? (error as { response?: { data?: { message?: string } } }).response
              ?.data?.message
          : "Failed to load notification templates";
      toast.error(message || "Failed to load notification templates");
    } finally {
      setIsLoading(false);
    }
  };

  if (isLoading) {
    return <SettingsPageSkeleton />;
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Notification Templates</h1>
        <p className="text-muted-foreground mt-2">
          Customize per-type notification messages for push, in-app, chat, and
          email channels.{" "}
          <HelpLink articleId="notification-templates" />
        </p>
      </div>

      {novuEnabled && (
        <Alert variant="warning">
          <AlertTriangle className="h-4 w-4" />
          <AlertTitle>Novu is active</AlertTitle>
          <AlertDescription>
            These templates only apply when Novu is disabled. While Novu is active, notification content is managed through your Novu dashboard.
          </AlertDescription>
        </Alert>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Templates</CardTitle>
          <CardDescription>
            Click a template to edit its title and body
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Type</TableHead>
                <TableHead>Channel</TableHead>
                <TableHead>Title</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Last Updated</TableHead>
                <TableHead className="w-10" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {templates.length === 0 ? (
                <TableRow>
                  <TableCell
                    colSpan={6}
                    className="text-center text-muted-foreground py-8"
                  >
                    No notification templates found
                  </TableCell>
                </TableRow>
              ) : (
                getAllCategories()
                  .filter(({ value }) => groupedTemplates[value]?.length)
                  .flatMap(({ value: category }) => {
                    const categoryTemplates = groupedTemplates[category];
                    return [
                    <TableRow
                      key={`cat-${category}`}
                      className="bg-muted/30 hover:bg-muted/30"
                    >
                      <TableCell
                        colSpan={6}
                        className="py-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground"
                      >
                        {getCategoryLabel(category as NotificationCategory)}
                      </TableCell>
                    </TableRow>,
                    ...categoryTemplates.map((template) => {
                        const typeMeta = getNotificationType(template.type);
                        const TypeIcon = typeMeta.icon;
                        return (
                          <TableRow
                            key={`${template.type}-${template.channel_group}`}
                            className="cursor-pointer"
                            onClick={() =>
                              router.push(
                                `/configuration/notification-templates/${template.id}`
                              )
                            }
                          >
                            <TableCell className="font-medium">
                              <div className="flex items-center gap-2">
                                <TypeIcon className="h-4 w-4 text-muted-foreground shrink-0" />
                                {typeMeta.label}
                                {template.is_system && (
                                  <Badge
                                    variant="secondary"
                                    className="text-xs"
                                  >
                                    System
                                  </Badge>
                                )}
                              </div>
                            </TableCell>
                            <TableCell>
                              <Badge variant="outline">
                                {channelGroupLabel[template.channel_group] ??
                                  template.channel_group}
                              </Badge>
                            </TableCell>
                            <TableCell className="text-muted-foreground max-w-md truncate">
                              {template.title || "—"}
                            </TableCell>
                            <TableCell>
                              <Badge
                                variant={
                                  template.is_active ? "default" : "secondary"
                                }
                              >
                                {template.is_active ? "Active" : "Inactive"}
                              </Badge>
                            </TableCell>
                            <TableCell className="text-muted-foreground">
                              {formatDate(template.updated_at)}
                            </TableCell>
                            <TableCell>
                              <ChevronRight className="h-4 w-4 text-muted-foreground" />
                            </TableCell>
                          </TableRow>
                        );
                      }),
                    ];
                  })
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  );
}
