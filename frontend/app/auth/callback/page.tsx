"use client";

import { useEffect, useState, Suspense } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import Link from "next/link";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { useAuth } from "@/lib/auth";
import { AuthStateCard } from "@/components/auth/auth-state-card";
import { Button } from "@/components/ui/button";
import { errorLogger } from "@/lib/error-logger";

/**
 * SSO Callback Page — URL: /auth/callback
 *
 * IMPORTANT: This file is at `app/auth/callback/page.tsx` (NOT inside the (auth) route group).
 * The `(auth)` directory is a Next.js route group — parentheses are stripped from the URL.
 * This file must be at `app/auth/callback/` so the URL is `/auth/callback`, matching the
 * backend redirect URL in SSOController::redirectToFrontend().
 *
 * DO NOT move this file into the (auth) route group — that would change the URL to
 * `/callback` and break all SSO providers with a 404.
 *
 * This page handles the redirect back from the backend after an SSO provider
 * (Google, GitHub, Microsoft, Apple, Discord, GitLab, OIDC) authenticates a user.
 *
 * Flow:
 *   1. User clicks "Continue with {provider}" on the login/register page
 *   2. Browser navigates to /api/auth/sso/{provider} (backend)
 *   3. Backend redirects to the OAuth provider (e.g. Google)
 *   4. Provider authenticates the user and redirects to /api/auth/callback/{provider} (backend)
 *   5. Backend processes the OAuth callback, creates/links user, creates a session
 *   6. Backend redirects to THIS page: /auth/callback?success=true (or ?error=...)
 *   7. This page fetches the user from the session and redirects to /dashboard
 *
 * Query parameters (set by SSOController::redirectToFrontend):
 *   - success=true          — Authentication succeeded; session is active
 *   - registered=true       — A new account was created via SSO
 *   - linked=true           — An existing account was auto-linked to the SSO provider
 *   - error={error_code}    — Authentication failed; see ERROR_MESSAGES below
 *
 * IMPORTANT: This page is provider-agnostic. ALL SSO providers use the same
 * callback URL. When adding a new SSO provider, you do NOT need to modify this page.
 * The backend SSOController handles all provider-specific logic and always redirects
 * here with the same query parameter format.
 *
 * Related files:
 *   - backend/app/Http/Controllers/Api/SSOController.php — redirectToFrontend()
 *   - backend/app/Services/Auth/SSOService.php — handleCallback()
 *   - frontend/components/auth/sso-buttons.tsx — SSO button UI
 *   - frontend/lib/auth.ts — useAuth store (fetchUser)
 *   - docs/ai/recipes/add-sso-provider.md — Recipe for adding new providers
 */

/** Maps backend error codes to user-friendly messages. */
const ERROR_MESSAGES: Record<string, string> = {
  invalid_provider: "The SSO provider is not available or has been disabled.",
  invalid_state: "The authentication request expired or was tampered with. Please try again.",
  missing_state: "The authentication response was missing required security data. Please try again.",
  state_not_found: "Your session expired before completing authentication. Please try again.",
  authentication_failed: "The SSO provider could not authenticate you. Please try again.",
  account_exists:
    "An account with this email already exists. Please log in with your password first, then link the SSO provider from your profile.",
  registration_required:
    "Auto-registration is disabled. Please create an account first, then link your SSO provider.",
};

/**
 * Inner content component that uses useSearchParams().
 * Must be wrapped in <Suspense> — see SSOCallbackPage below.
 */
function SSOCallbackContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { fetchUser } = useAuth();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const success = searchParams.get("success");
    const errorParam = searchParams.get("error");
    const linked = searchParams.get("linked");
    const registered = searchParams.get("registered");

    if (errorParam) {
      const message = ERROR_MESSAGES[errorParam] || `Authentication failed: ${errorParam}`;
      setError(message);
      errorLogger.report(new Error(`SSO callback error: ${errorParam}`), {
        source: "sso-callback",
      });
      return;
    }

    if (success === "true") {
      // User is authenticated via SSO — fetch user data and redirect.
      // Note: fetchUser() catches errors internally and always resolves,
      // so we check user state via the store after it completes.
      fetchUser().then(() => {
        const currentUser = useAuth.getState().user;
        if (!currentUser) {
          setError(
            "Failed to load your account after authentication. Please try logging in again."
          );
          errorLogger.report(
            new Error("fetchUser resolved but user is null after SSO callback"),
            { source: "sso-callback" }
          );
          return;
        }

        if (registered === "true") {
          toast.success("Account created successfully! Welcome!");
        } else if (linked === "true") {
          toast.success("SSO provider linked to your account.");
        } else {
          toast.success("Welcome back!");
        }
        router.replace("/dashboard");
      });
      return;
    }

    // No recognized params — show error
    setError("Invalid callback. Please try logging in again.");
  }, [searchParams, fetchUser, router]);

  if (error) {
    return (
      <AuthStateCard
        variant="error"
        title="Authentication Failed"
        description={error}
        footer={
          <div className="w-full flex justify-center">
            <Button asChild>
              <Link href="/login">Back to Login</Link>
            </Button>
          </div>
        }
      />
    );
  }

  // Loading state while processing SSO callback
  return (
    <AuthStateCard
      variant="loading"
      title="Completing Sign In"
      description="Please wait while we finish authenticating you..."
    />
  );
}

/**
 * SSO Callback Page — wrapped in Suspense for useSearchParams().
 *
 * Next.js App Router requires a <Suspense> boundary around components that
 * call useSearchParams() to avoid de-opting to client-side rendering.
 * See: verify-email/page.tsx and reset-password/page.tsx for the same pattern.
 */
export default function SSOCallbackPage() {
  return (
    <Suspense
      fallback={
        <div className="bg-muted flex min-h-svh items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      }
    >
      <SSOCallbackContent />
    </Suspense>
  );
}
