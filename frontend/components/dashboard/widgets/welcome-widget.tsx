"use client";

import { useAuth } from "@/lib/auth";
import { Card, CardContent } from "@/components/ui/card";
import { useAppConfig } from "@/lib/app-config";

export function WelcomeWidget() {
  const { user } = useAuth();
  const { appName } = useAppConfig();
  const firstName = user?.name?.split(" ")[0] || "there";

  const now = new Date();
  const hour = now.getHours();
  const greeting =
    hour < 12 ? "Good morning" : hour < 18 ? "Good afternoon" : "Good evening";

  const dateStr = now.toLocaleDateString("en-US", {
    weekday: "long",
    month: "long",
    day: "numeric",
  });

  return (
    <Card className="col-span-full bg-gradient-to-r from-primary/10 via-primary/5 to-transparent border-primary/20">
      <CardContent className="pt-6">
        <h2 className="text-lg font-heading font-semibold">
          {greeting}, {firstName}
        </h2>
        <p className="text-sm text-muted-foreground mt-1.5">
          {dateStr} &middot; {appName}
        </p>
      </CardContent>
    </Card>
  );
}
