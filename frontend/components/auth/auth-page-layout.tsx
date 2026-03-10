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
  usePageTitle(title);

  return (
    <div className="flex min-h-svh">
      {/* Decorative left panel — desktop only */}
      <div className="hidden lg:flex lg:flex-1 lg:flex-col lg:items-center lg:justify-center bg-gradient-to-br from-primary/20 via-primary/10 to-background border-r border-border">
        <Logo variant="full" size="lg" />
      </div>

      {/* Form panel */}
      <div className="flex flex-1 flex-col items-center justify-center gap-6 p-6 md:p-10 bg-muted">
        {/* Logo on mobile (above form) */}
        <div className="flex items-center gap-2 lg:hidden">
          <Logo variant="full" size="md" />
        </div>

        <div className={cn("flex w-full max-w-sm flex-col gap-6", className)}>
          <Card className="animate-in fade-in slide-in-from-bottom-4 duration-500">
            <CardHeader className="text-center">
              <CardTitle className="sr-only">{title}</CardTitle>
              {description && (
                <CardDescription className="sr-only">{description}</CardDescription>
              )}
              <p className="text-xl font-semibold">{title}</p>
              {description && (
                <p className="text-sm text-muted-foreground">{description}</p>
              )}
            </CardHeader>
            <CardContent className="space-y-6">
              {children}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
