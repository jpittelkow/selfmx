# Dashboard Widget Pattern

Dashboard uses static, developer-defined widgets. Widgets are self-contained React components added directly to the dashboard page -- no database storage or user configuration.

## Dashboard Layout

The dashboard page uses a structured layout:

1. **Welcome banner** — Full-width gradient card with time-based greeting and date
2. **Metric cards + Quick Actions** — 3-column grid with `AuditStatsCard` metric cards and icon tile grid
3. **Usage section** (admin only) — Full-width area chart with time range toggles

```tsx
// frontend/app/(dashboard)/dashboard/page.tsx
export default function DashboardPage() {
  const { user } = useAuth();
  const canViewUsage = user
    ? isAdminUser(user) || (user.permissions?.includes("usage.view") ?? false)
    : false;

  return (
    <div className="space-y-6">
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
```

## Widget Types

### Metric Card (StatsWidget)

Stats render as individual `AuditStatsCard` components — each card shows a large value, title, and icon. The `StatsWidget` renders multiple cards that sit alongside other widgets in the grid (not wrapped in its own container).

```tsx
import { AuditStatsCard } from "@/components/audit/audit-stats-card";
import { Users, HardDrive } from "lucide-react";

<AuditStatsCard title="Total Users" value={42} icon={Users} />
<AuditStatsCard title="Storage Used" value="1.2 GB" icon={HardDrive} />
```

### Welcome Banner (WelcomeWidget)

Full-width gradient card with time-based greeting, date, and app name. Uses `col-span-full` and `bg-gradient-to-r from-primary/10`.

### Quick Actions (QuickActionsWidget)

2x2 icon tile grid with hover states. Each tile is a `Link` with an icon and label.

```tsx
<div className="grid grid-cols-2 gap-2">
  <Link href="/configuration/audit" className="flex flex-col items-center gap-2 rounded-lg border p-3 transition-colors hover:bg-muted">
    <ClipboardList className="h-5 w-5 text-muted-foreground" />
    <span className="text-xs font-medium">Audit Logs</span>
  </Link>
</div>
```

### Chart Widget (UsageDashboardWidget)

Full-width area chart with proper axes, grid, tooltip, and time range toggle buttons (7d/14d/30d). Uses `ResponsiveContainer` + Recharts at 200px height.

**Key files:** `frontend/app/(dashboard)/dashboard/page.tsx`, `frontend/components/dashboard/`, `frontend/components/dashboard/widgets/`

**Related:** [Recipe: Add Dashboard Widget](../recipes/add-dashboard-widget.md), [Anti-patterns: Widgets](../anti-patterns/widgets.md)
