"use client";

import { Suspense } from "react";
import { useSearchParams } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { Logo } from "@/components/logo";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import Link from "next/link";
import { Share2 } from "lucide-react";
import { usePageTitle } from "@/lib/use-page-title";
import { useAppConfig } from "@/lib/app-config";

/**
 * Share Target page: receives shared content when the PWA is chosen as a share target.
 * Query params (GET): title, text, url (from manifest share_target).
 */
function SharePageContent() {
  const searchParams = useSearchParams();
  const { user, isLoading } = useAuth();
  const { appName } = useAppConfig();
  const displayName = appName || "this app";

  // Set page title with app name from config
  usePageTitle("Shared Content");
  const title = searchParams.get("title") ?? "";
  const text = searchParams.get("text") ?? "";
  const rawUrl = searchParams.get("url") ?? "";
  // Only allow http/https in link href to prevent javascript: or data: XSS
  const safeUrl =
    rawUrl.startsWith("http://") || rawUrl.startsWith("https://") ? rawUrl : "";
  const hasContent = Boolean(title || text || rawUrl);

  return (
    <main className="min-h-screen flex flex-col items-center p-6">
      <div className="w-full max-w-lg space-y-6">
        <div className="flex justify-center pt-8">
          <Logo variant="full" size="lg" />
        </div>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Share2 className="h-5 w-5" />
              Shared with {displayName}
            </CardTitle>
            <CardDescription>
              Content shared to this app is shown below. You can open the dashboard to use it.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {hasContent ? (
              <div className="space-y-3 text-sm">
                {title && (
                  <div>
                    <span className="font-medium text-muted-foreground">Title: </span>
                    <span>{title}</span>
                  </div>
                )}
                {text && (
                  <div>
                    <span className="font-medium text-muted-foreground">Text: </span>
                    <p className="mt-1 whitespace-pre-wrap break-words">{text}</p>
                  </div>
                )}
                {rawUrl && (
                  <div>
                    <span className="font-medium text-muted-foreground">URL: </span>
                    {safeUrl ? (
                      <a
                        href={safeUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-primary hover:underline break-all"
                      >
                        {rawUrl}
                      </a>
                    ) : (
                      <span className="break-all">{rawUrl}</span>
                    )}
                  </div>
                )}
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">
                No shared content in this request. Share a link or text from another app and choose {displayName} as the target.
              </p>
            )}

            <div className="flex gap-2 pt-2">
              {!isLoading && user ? (
                <Link href="/mail">
                  <Button>Go to Inbox</Button>
                </Link>
              ) : !isLoading ? (
                <Link href="/login">
                  <Button>Sign in</Button>
                </Link>
              ) : null}
              <Link href="/">
                <Button variant="outline">Home</Button>
              </Link>
            </div>
          </CardContent>
        </Card>
      </div>
    </main>
  );
}

export default function SharePage() {
  return (
    <Suspense fallback={
      <main className="min-h-screen flex flex-col items-center justify-center p-6">
        <div className="animate-pulse text-muted-foreground">Loading…</div>
      </main>
    }>
      <SharePageContent />
    </Suspense>
  );
}
