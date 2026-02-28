"use client";

import {
  WelcomeWidget,
  StatsWidget,
  QuickActionsWidget,
} from "@/components/dashboard/widgets";
import { OfflineBadge } from "@/components/offline-badge";
import { UsageDashboardWidget } from "@/components/usage/usage-dashboard-widget";
import { useAuth, isAdminUser } from "@/lib/auth";

export default function DashboardPage() {
  const { user } = useAuth();
  const canViewUsage = user
    ? isAdminUser(user) || (user.permissions?.includes("usage.view") ?? false)
    : false;

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-2 flex-wrap">
        <OfflineBadge />
      </div>
      <WelcomeWidget />
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <StatsWidget />
        <QuickActionsWidget />
      </div>
      {canViewUsage && (
        <div>
          <h2 className="text-lg font-semibold mb-3">Usage & Costs</h2>
          <UsageDashboardWidget />
        </div>
      )}
    </div>
  );
}
