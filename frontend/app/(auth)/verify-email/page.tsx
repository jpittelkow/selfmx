"use client";

import { useState, useEffect, useRef, Suspense } from "react";
import { useSearchParams } from "next/navigation";
import Link from "next/link";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { getErrorMessage } from "@/lib/utils";
import { useAuth } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Loader2, XCircle, Mail, ArrowRight } from "lucide-react";
import { LoadingButton } from "@/components/ui/loading-button";
import { AuthStateCard } from "@/components/auth/auth-state-card";

function VerifyEmailContent() {
  const searchParams = useSearchParams();
  const { user, fetchUser } = useAuth();
  const [status, setStatus] = useState<"loading" | "success" | "error" | "pending">("loading");
  const [errorMessage, setErrorMessage] = useState("");
  const [isResending, setIsResending] = useState(false);

  const id = searchParams.get("id");
  const hash = searchParams.get("hash");
  const verificationAttempted = useRef(false);

  useEffect(() => {
    if (id && hash) {
      if (verificationAttempted.current) return;
      verificationAttempted.current = true;

      (async () => {
        try {
          await api.post("/auth/verify-email", { id, hash });
          setStatus("success");
          await fetchUser();
          toast.success("Email verified successfully!");
        } catch (error: unknown) {
          setStatus("error");
          setErrorMessage(getErrorMessage(error, "Verification failed"));
        }
      })();
    } else if (user) {
      setStatus("pending");
    } else {
      setStatus("error");
      setErrorMessage("Invalid verification link");
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id, hash]);

  const handleResendVerification = async () => {
    setIsResending(true);
    try {
      await api.post("/auth/resend-verification");
      toast.success("Verification email sent!");
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to resend verification email"));
    } finally {
      setIsResending(false);
    }
  };

  // Loading state
  if (status === "loading") {
    return (
      <AuthStateCard
        variant="loading"
        title="Verifying Email"
        description="Please wait while we verify your email address..."
      />
    );
  }

  // Success state
  if (status === "success") {
    return (
      <AuthStateCard
        variant="success"
        title="Email Verified"
        description="Your email address has been verified successfully."
        footer={
          <Link href="/mail" className="w-full">
            <Button className="w-full">
              Go to Inbox
              <ArrowRight className="ml-2 h-4 w-4" />
            </Button>
          </Link>
        }
      >
        <p className="text-sm text-muted-foreground text-center">
          You now have full access to all features of your account.
        </p>
      </AuthStateCard>
    );
  }

  // Pending verification state (user is logged in but not verified)
  if (status === "pending" && user && !user.email_verified_at) {
    return (
      <AuthStateCard
        variant="warning"
        icon={Mail}
        title="Verify Your Email"
        description={
          <>
            We've sent a verification link to{" "}
            <span className="font-medium text-foreground">{user.email}</span>
          </>
        }
        footer={
          <div className="flex flex-col gap-2 w-full">
            <LoadingButton
              onClick={handleResendVerification}
              isLoading={isResending}
              className="w-full"
            >
              Resend Verification Email
            </LoadingButton>
            <Link href="/mail" className="w-full">
              <Button variant="ghost" className="w-full">
                Continue to Inbox
              </Button>
            </Link>
          </div>
        }
      >
        <div className="space-y-4">
          <Alert>
            <Mail className="h-4 w-4" />
            <AlertDescription>
              Please check your email and click the verification link to
              activate your account. The link will expire in 24 hours.
            </AlertDescription>
          </Alert>
          <p className="text-sm text-muted-foreground text-center">
            Didn't receive the email? Check your spam folder or click below to
            resend.
          </p>
        </div>
      </AuthStateCard>
    );
  }

  // Error state
  return (
    <AuthStateCard
      variant="error"
      icon={XCircle}
      title="Verification Failed"
      description="We couldn't verify your email address."
      footer={
        <div className="flex flex-col gap-2 w-full">
          {user ? (
            <>
              <LoadingButton
                onClick={handleResendVerification}
                isLoading={isResending}
                className="w-full"
              >
                Request New Verification Email
              </LoadingButton>
              <Link href="/mail" className="w-full">
                <Button variant="ghost" className="w-full">
                  Continue to Inbox
                </Button>
              </Link>
            </>
          ) : (
            <Link href="/login" className="w-full">
              <Button className="w-full">Sign In</Button>
            </Link>
          )}
        </div>
      }
    >
      <Alert variant="destructive">
        <XCircle className="h-4 w-4" />
        <AlertTitle>Error</AlertTitle>
        <AlertDescription>
          {errorMessage || "The verification link is invalid or has expired."}
        </AlertDescription>
      </Alert>
    </AuthStateCard>
  );
}

export default function VerifyEmailPage() {
  return (
    <Suspense
      fallback={
        <div className="bg-muted flex min-h-svh items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      }
    >
      <VerifyEmailContent />
    </Suspense>
  );
}
