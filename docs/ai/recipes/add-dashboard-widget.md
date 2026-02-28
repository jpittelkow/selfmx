# Recipe: Add Dashboard Widget

Step-by-step guide to add a new static widget to the dashboard.

> **Note**: This project uses static, developer-defined widgets. Widgets are React components added directly to the dashboard page—no user configuration or widget selection system. This approach prioritizes simplicity and AI-friendly patterns.

**Reference implementations:** See `frontend/components/dashboard/widgets/` for sample widgets: `welcome-widget.tsx` (greeting banner with date), `stats-widget.tsx` (metric cards using `AuditStatsCard`), `quick-actions-widget.tsx` (2x2 icon tile grid).

## Files to Create/Modify

| File | Action | Purpose |
|------|--------|---------|
| `frontend/components/dashboard/widgets/{name}-widget.tsx` | Create | Widget component |
| `frontend/components/dashboard/widgets/index.ts` | Modify | Export new widget |
| `frontend/app/(dashboard)/dashboard/page.tsx` | Modify | Add widget to layout |
| `backend/routes/api.php` | Modify | Add data endpoint (if needed) |
| `backend/app/Http/Controllers/Api/DashboardController.php` | Modify | Add data method |

## Step 1: Create the Widget Component

```tsx
// frontend/components/dashboard/widgets/example-widget.tsx
'use client';

import { useState, useEffect } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { Loader2, RefreshCw, TrendingUp, AlertCircle } from 'lucide-react';

interface ExampleData {
  total: number;
  change: number;
  changePercent: number;
  items: Array<{
    id: string;
    name: string;
    value: number;
  }>;
}

export function ExampleWidget() {
  const [data, setData] = useState<ExampleData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchData();
  }, []);

  const fetchData = async () => {
    try {
      setLoading(true);
      setError(null);
      const response = await api.get('/dashboard/example');
      setData(response.data);
    } catch (err) {
      setError('Failed to load data');
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Example</CardTitle>
        </CardHeader>
        <CardContent className="flex items-center justify-center h-32">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </CardContent>
      </Card>
    );
  }

  if (error) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Example</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col items-center justify-center h-32 space-y-2">
          <AlertCircle className="h-6 w-6 text-destructive" />
          <p className="text-sm text-muted-foreground">{error}</p>
          <Button variant="outline" size="sm" onClick={fetchData}>
            <RefreshCw className="h-4 w-4 mr-2" />
            Retry
          </Button>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle className="text-sm font-medium">Example Metric</CardTitle>
        <TrendingUp className="h-4 w-4 text-muted-foreground" />
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-bold">{data?.total.toLocaleString()}</div>
        <p className="text-xs text-muted-foreground">
          {data?.change >= 0 ? '+' : ''}
          {data?.changePercent.toFixed(1)}% from last period
        </p>

        {/* Optional: Show list of items */}
        {data?.items && data.items.length > 0 && (
          <div className="mt-4 space-y-2">
            {data.items.slice(0, 5).map((item) => (
              <div key={item.id} className="flex items-center justify-between text-sm">
                <span className="text-muted-foreground">{item.name}</span>
                <span className="font-medium">{item.value}</span>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
```

## Step 2: Add to Dashboard Page

> **Note:** The app shell provides container padding (`max-w-7xl mx-auto px-4 py-6 sm:px-6 lg:px-8`) and a `bg-muted/30` content well. Pages should NOT add their own `container`, `p-4`, or outer padding.

```tsx
// frontend/app/(dashboard)/dashboard/page.tsx
import { ExampleWidget } from '@/components/dashboard/example-widget';

export default function DashboardPage() {
  return (
    <div className="space-y-6">
      {/* Full-width welcome banner */}
      <WelcomeWidget />

      {/* Metric cards + actions in a grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <StatsWidget />  {/* Renders individual AuditStatsCard per metric */}
        <QuickActionsWidget />
        <ExampleWidget />
      </div>

      {/* Full-width sections below */}
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

## Step 3: Add Backend Endpoint

```php
// backend/routes/api.php

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('dashboard')->group(function () {
        Route::get('/example', [DashboardController::class, 'example']);
    });
});
```

## Step 4: Implement Controller Method

```php
// backend/app/Http/Controllers/Api/DashboardController.php

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Example;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * Get example widget data.
     */
    public function example(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // Cache for 5 minutes to reduce database load
        $data = Cache::remember("dashboard.example.{$userId}", 300, function () use ($userId) {
            return $this->calculateExampleData($userId);
        });

        return response()->json($data);
    }

    protected function calculateExampleData(int $userId): array
    {
        // Current period (e.g., this month)
        $currentTotal = Example::where('user_id', $userId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        // Previous period for comparison
        $previousTotal = Example::where('user_id', $userId)
            ->whereBetween('created_at', [
                now()->subMonth()->startOfMonth(),
                now()->subMonth()->endOfMonth(),
            ])
            ->count();

        // Calculate change
        $change = $currentTotal - $previousTotal;
        $changePercent = $previousTotal > 0
            ? (($currentTotal - $previousTotal) / $previousTotal) * 100
            : 0;

        // Get recent items
        $items = Example::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get(['id', 'name', 'value']);

        return [
            'total' => $currentTotal,
            'change' => $change,
            'changePercent' => $changePercent,
            'items' => $items->map(fn($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'value' => $item->value,
            ])->toArray(),
        ];
    }
}
```

## Widget Variations

### Stat Card (Metric Card)

Use `AuditStatsCard` for consistent metric card styling with large numbers, icons, and optional variant coloring:

```tsx
import { AuditStatsCard } from "@/components/audit/audit-stats-card";
import { Users } from "lucide-react";

<AuditStatsCard
  title="Total Users"
  value={42}
  description="Active accounts"
  icon={Users}
  variant="default"  // "default" | "warning" | "error"
/>
```

### Chart Widget

Use **shadcn chart** (`ChartContainer` + Recharts) when available for consistent theming and tooltips. See `frontend/components/ui/chart.tsx` and the audit widget charts.

```tsx
import { ChartContainer } from '@/components/ui/chart';
import { BarChart, Bar, XAxis, YAxis } from 'recharts';

const config = { value: { label: 'Count', color: 'hsl(217 91% 60%)' } };

export function ChartWidget({ data }) {
  return (
    <Card className="col-span-2">
      <CardHeader>
        <CardTitle>Activity Chart</CardTitle>
      </CardHeader>
      <CardContent>
        <ChartContainer config={config} className="min-h-[200px] w-full">
          <BarChart data={data} accessibilityLayer>
            <XAxis dataKey="name" />
            <YAxis />
            <Bar dataKey="value" fill="var(--color-value)" />
          </BarChart>
        </ChartContainer>
      </CardContent>
    </Card>
  );
}
```

Alternatively, use raw `ResponsiveContainer` + Recharts if you prefer (no ChartConfig/theming).

### List Widget

```tsx
export function ListWidget({ title, items }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="space-y-4">
          {items.map((item) => (
            <div key={item.id} className="flex items-center">
              <div className="ml-4 space-y-1">
                <p className="text-sm font-medium">{item.title}</p>
                <p className="text-sm text-muted-foreground">{item.subtitle}</p>
              </div>
              <div className="ml-auto font-medium">{item.value}</div>
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
```

### Widget with Actions (Icon Tile Grid)

```tsx
export function ActionWidget() {
  const actions = [
    { label: "Audit Logs", href: "/configuration/audit", icon: ClipboardList },
    { label: "Users", href: "/configuration/users", icon: Users },
    { label: "Settings", href: "/configuration/system", icon: Settings },
    { label: "Security", href: "/configuration/security", icon: Shield },
  ];

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-sm font-medium">Quick Actions</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="grid grid-cols-2 gap-2">
          {actions.map((action) => (
            <Link key={action.href} href={action.href}
              className="flex flex-col items-center gap-2 rounded-lg border p-3 text-center transition-colors hover:bg-muted min-h-[72px] justify-center">
              <action.icon className="h-5 w-5 text-muted-foreground" />
              <span className="text-xs font-medium">{action.label}</span>
            </Link>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
```

## Checklist

- [ ] Widget component created in `frontend/components/dashboard/`
- [ ] Widget added to dashboard page
- [ ] Loading state with spinner
- [ ] Error state with retry button
- [ ] Backend endpoint added (if fetching data)
- [ ] Data caching implemented (recommended)
- [ ] Responsive grid layout
- [ ] Proper TypeScript types defined

## Layout Tips

```tsx
// Full width
<div className="grid gap-4">
  <FullWidthWidget />
</div>

// Two columns on medium+
<div className="grid gap-4 md:grid-cols-2">
  <Widget1 />
  <Widget2 />
</div>

// Four columns on large, two on medium
<div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
  <StatWidget1 />
  <StatWidget2 />
  <StatWidget3 />
  <StatWidget4 />
</div>

// Mixed sizes
<div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
  <Widget />
  <LargeWidget className="md:col-span-2" />
</div>
```

## React Query Pattern (Recommended)

For better caching, refetching, and loading states, use React Query:

```tsx
import { useQuery } from "@tanstack/react-query";
import { api } from "@/lib/api";

export function StatsWidget() {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ["dashboard", "stats"],
    queryFn: () => api.get("/dashboard/stats").then((r) => r.data),
    staleTime: 5 * 60 * 1000, // 5 minutes
  });

  if (isLoading) return <WidgetSkeleton />;
  if (error) return <WidgetError onRetry={refetch} />;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-sm font-medium">Stats</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-bold">{data?.total}</div>
      </CardContent>
    </Card>
  );
}
```

## Permission-Based Widget Visibility

Show widgets only to users with specific permissions:

```tsx
import { usePermission } from "@/lib/use-permission";

export default function DashboardPage() {
  const { hasPermission } = usePermission();

  return (
    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
      <WelcomeWidget />
      <StatsWidget />
      {hasPermission("admin") && <SystemHealthWidget />}
      {hasPermission("audit.view") && <RecentActivityWidget />}
    </div>
  );
}
```

## See also

- **Dashboard Improvements Roadmap** – [docs/plans/dashboard-improvements-roadmap.md](../../plans/dashboard-improvements-roadmap.md)
- **Audit dashboard widget** – Real-world example: stats cards, severity donut + activity trends charts (shadcn), recent-warnings list, “View all” link. Uses `GET /audit-logs/stats`. See `frontend/components/audit/audit-dashboard-widget.tsx`, `audit-severity-chart.tsx`, `audit-trends-chart.tsx`, and [Audit Dashboard Analytics](../../journal/2026-01-29-audit-dashboard-analytics.md).
