"use client";

import { useState } from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { useAppConfig } from "@/lib/app-config";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { ArrowLeft, Mail } from "lucide-react";
import { Logo } from "@/components/logo";
import { FormField } from "@/components/ui/form-field";
import { LoadingButton } from "@/components/ui/loading-button";
import { AuthStateCard } from "@/components/auth/auth-state-card";

const forgotPasswordSchema = z.object({
  email: z.string().email("Please enter a valid email address"),
});

type ForgotPasswordForm = z.infer<typeof forgotPasswordSchema>;

export default function ForgotPasswordPage() {
  const { features, isLoading: isConfigLoading } = useAppConfig();
  const [isLoading, setIsLoading] = useState(false);
  const [isEmailSent, setIsEmailSent] = useState(false);
  const [submittedEmail, setSubmittedEmail] = useState("");

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<ForgotPasswordForm>({
    resolver: zodResolver(forgotPasswordSchema),
  });

  const onSubmit = async (data: ForgotPasswordForm) => {
    setIsLoading(true);
    try {
      await api.post("/auth/forgot-password", data);
      setSubmittedEmail(data.email);
      setIsEmailSent(true);
      toast.success("Password reset link sent!");
    } catch {
      // Don't reveal if email exists or not for security
      setSubmittedEmail(data.email);
      setIsEmailSent(true);
    } finally {
      setIsLoading(false);
    }
  };

  if (!isConfigLoading && !features?.passwordResetAvailable) {
    return (
      <div className="bg-muted flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
        <AuthStateCard
          variant="warning"
          title="Password Reset Unavailable"
          description="Password reset is not available. Email may not be configured, or it may have been disabled by your administrator."
          footer={
            <Link href="/login" className="w-full">
              <Button variant="outline" className="w-full">
                <ArrowLeft className="mr-2 h-4 w-4" />
                Back to Sign In
              </Button>
            </Link>
          }
        >
          <p className="text-sm text-muted-foreground text-center">
            Please contact your administrator for assistance.
          </p>
        </AuthStateCard>
      </div>
    );
  }

  if (isEmailSent) {
    return (
      <AuthStateCard
        variant="success"
        title="Check Your Email"
        description={
          <>
            We've sent a password reset link to{" "}
            <span className="font-medium text-foreground">{submittedEmail}</span>
          </>
        }
        footer={
          <Link href="/login" className="w-full">
            <Button variant="outline" className="w-full">
              <ArrowLeft className="mr-2 h-4 w-4" />
              Back to Sign In
            </Button>
          </Link>
        }
      >
        <div className="space-y-4">
          <Alert>
            <Mail className="h-4 w-4" />
            <AlertDescription>
              If an account exists with this email, you'll receive a password
              reset link shortly. The link will expire in 60 minutes.
            </AlertDescription>
          </Alert>
          <p className="text-sm text-muted-foreground text-center">
            Didn't receive the email? Check your spam folder or{" "}
            <button
              onClick={() => setIsEmailSent(false)}
              className="text-primary hover:underline"
            >
              try again
            </button>
          </p>
        </div>
      </AuthStateCard>
    );
  }

  return (
    <div className="bg-muted flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
      <div className="flex w-full max-w-sm flex-col gap-6">
        <div className="flex items-center gap-2 self-center">
          <Logo variant="full" size="md" />
        </div>
        <Card>
          <CardHeader className="text-center">
            <CardTitle className="text-xl">Forgot Password</CardTitle>
            <CardDescription>
              Enter your email address and we&apos;ll send you a link to reset your
              password.
            </CardDescription>
          </CardHeader>
          <form onSubmit={handleSubmit(onSubmit)}>
            <CardContent className="space-y-4">
              <FormField
                id="email"
                label="Email Address"
                error={errors.email?.message}
              >
                <Input
                  id="email"
                  type="email"
                  placeholder="you@example.com"
                  {...register("email")}
                  disabled={isLoading}
                />
              </FormField>
            </CardContent>
            <CardFooter className="flex flex-col gap-4">
              <LoadingButton
                type="submit"
                className="w-full"
                isLoading={isLoading}
                loadingText="Sending..."
              >
                Send Reset Link
              </LoadingButton>
              <Link href="/login" className="w-full">
                <Button variant="ghost" className="w-full">
                  <ArrowLeft className="mr-2 h-4 w-4" />
                  Back to Sign In
                </Button>
              </Link>
            </CardFooter>
          </form>
        </Card>
      </div>
    </div>
  );
}
