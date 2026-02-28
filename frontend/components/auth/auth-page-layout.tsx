"use client";

import { cn } from "@/lib/utils";
import { Logo } from "@/components/logo";
import { usePageTitle } from "@/lib/use-page-title";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

interface AuthPageLayoutProps {
  title: string;
  description?: string;
  children: React.ReactNode;
  className?: string;
}

export function AuthPageLayout({
  title,
  description,
  children,
  className,
}: AuthPageLayoutProps) {
  // Set page title with app name from config
  usePageTitle(title);

  return (
    <div className="bg-muted flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
      <div className={cn("flex w-full max-w-sm flex-col gap-6", className)}>
        <div className="flex items-center gap-2 self-center">
          <Logo variant="full" size="md" />
        </div>
        <Card>
          <CardHeader className="text-center">
            <CardTitle className="text-xl">{title}</CardTitle>
            {description && (
              <CardDescription>{description}</CardDescription>
            )}
          </CardHeader>
          <CardContent className="space-y-6">
            {children}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
