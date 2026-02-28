"use client";

import { useState, useEffect, useCallback } from "react";
import Link from "next/link";
import { api } from "@/lib/api";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { AuditStatsCard } from "@/components/audit/audit-stats-card";
import { DollarSign, Brain, TrendingUp, ExternalLink } from "lucide-react";
import { formatCurrency } from "@/lib/utils";
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

interface UsageWidgetStats {
  summary: {
    total_estimated_cost: number;
    by_integration: Record<string, number>;
  };
  daily: Array<{
    date: string;
    llm: number;
    email: number;
    sms: number;
    storage: number;
    broadcasting: number;
  }>;
}

type TimeRange = "7d" | "14d" | "30d";

function dateRangeForPeriod(range: TimeRange) {
  const to = new Date();
  const from = new Date();
  const days = range === "7d" ? 7 : range === "14d" ? 14 : 30;
  from.setDate(to.getDate() - days);
  return {
    date_from: from.toISOString().slice(0, 10),
    date_to: to.toISOString().slice(0, 10),
  };
}

const timeRangeLabels: Record<TimeRange, string> = {
  "7d": "7 days",
  "14d": "14 days",
  "30d": "30 days",
};

export function UsageDashboardWidget() {
  const [stats, setStats] = useState<UsageWidgetStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [timeRange, setTimeRange] = useState<TimeRange>("30d");

  const fetchStats = useCallback(() => {
    setLoading(true);
    setError(null);
    const { date_from, date_to } = dateRangeForPeriod(timeRange);
    api
      .get<UsageWidgetStats>("/usage/stats", { params: { date_from, date_to } })
      .then((r) => {
        setStats(r.data);
        setError(null);
      })
      .catch((e) =>
        setError(e instanceof Error ? e.message : "Failed to load usage stats")
      )
      .finally(() => setLoading(false));
  }, [timeRange]);

  useEffect(() => {
    fetchStats();
  }, [fetchStats]);

  if (loading) {
    return (
      <div className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Skeleton className="h-[120px] rounded-lg" />
          <Skeleton className="h-[120px] rounded-lg" />
        </div>
        <Skeleton className="h-[240px] rounded-lg" />
      </div>
    );
  }

  if (error) {
    return (
      <Card className="border-destructive/50">
        <CardContent className="flex flex-col items-center justify-center gap-3 py-8">
          <p className="text-sm text-muted-foreground">{error}</p>
          <Button variant="outline" size="sm" onClick={fetchStats}>
            Retry
          </Button>
        </CardContent>
      </Card>
    );
  }

  if (!stats) return null;

  const totalCost = stats.summary.total_estimated_cost;
  const topIntegration = Object.entries(stats.summary.by_integration).reduce(
    (max, [key, val]) => (val > max.val ? { key, val } : max),
    { key: "none", val: 0 }
  );

  const chartData = stats.daily.map((d) => ({
    date: d.date,
    total: d.llm + d.email + d.sms + d.storage + d.broadcasting,
  }));

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <AuditStatsCard
          title="Monthly cost"
          value={formatCurrency(totalCost)}
          description="Current period to date"
          icon={DollarSign}
          variant={totalCost > 100 ? "warning" : "default"}
        />
        {topIntegration.val > 0 && (
          <AuditStatsCard
            title={`Top: ${topIntegration.key.toUpperCase()}`}
            value={formatCurrency(topIntegration.val)}
            description="Largest cost this period"
            icon={Brain}
          />
        )}
      </div>

      {chartData.length > 1 && (
        <Card>
          <CardHeader className="pb-2">
            <div className="flex items-center justify-between">
              <CardTitle className="flex items-center gap-2 text-sm font-medium">
                <TrendingUp className="h-4 w-4" />
                Daily spend trend
              </CardTitle>
              <div className="flex gap-1">
                {(Object.keys(timeRangeLabels) as TimeRange[]).map((range) => (
                  <Button
                    key={range}
                    variant={timeRange === range ? "secondary" : "ghost"}
                    size="sm"
                    className="h-7 text-xs px-2"
                    onClick={() => setTimeRange(range)}
                  >
                    {timeRangeLabels[range]}
                  </Button>
                ))}
              </div>
            </div>
          </CardHeader>
          <CardContent className="pb-4">
            <div className="h-[200px] w-full">
              <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={chartData}>
                  <CartesianGrid
                    strokeDasharray="3 3"
                    vertical={false}
                    stroke="hsl(var(--border))"
                  />
                  <XAxis
                    dataKey="date"
                    tickLine={false}
                    axisLine={false}
                    fontSize={12}
                    tick={{ fill: "hsl(var(--muted-foreground))" }}
                    tickFormatter={(value: string) => {
                      const d = new Date(value + "T00:00:00");
                      return d.toLocaleDateString("en-US", {
                        month: "short",
                        day: "numeric",
                      });
                    }}
                    interval="preserveStartEnd"
                  />
                  <YAxis
                    tickLine={false}
                    axisLine={false}
                    fontSize={12}
                    tick={{ fill: "hsl(var(--muted-foreground))" }}
                    tickFormatter={(value: number) => `$${value.toFixed(0)}`}
                    width={45}
                  />
                  <Tooltip
                    content={({ active, payload, label }) => {
                      if (!active || !payload?.length) return null;
                      const d = new Date(label + "T00:00:00");
                      return (
                        <div className="rounded-lg border bg-background p-2 shadow-md">
                          <p className="text-xs text-muted-foreground">
                            {d.toLocaleDateString("en-US", {
                              month: "long",
                              day: "numeric",
                            })}
                          </p>
                          <p className="text-sm font-semibold">
                            {formatCurrency(payload[0].value as number)}
                          </p>
                        </div>
                      );
                    }}
                  />
                  <Area
                    type="monotone"
                    dataKey="total"
                    stroke="hsl(var(--primary))"
                    fill="hsl(var(--primary))"
                    fillOpacity={0.1}
                    strokeWidth={2}
                  />
                </AreaChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>
      )}

      <div className="flex justify-end">
        <Button variant="outline" size="sm" asChild>
          <Link href="/configuration/usage">
            View usage details
            <ExternalLink className="ml-2 h-4 w-4" />
          </Link>
        </Button>
      </div>
    </div>
  );
}
