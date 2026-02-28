"use client";

import { useState, Suspense } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { getErrorMessage } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { PasswordInput } from "@/components/ui/password-input";
import { PasswordStrength } from "@/components/ui/password-strength";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { ArrowLeft, Loader2, AlertTriangle } from "lucide-react";
import { Logo } from "@/components/logo";
import { FormField } from "@/components/ui/form-field";
import { LoadingButton } from "@/components/ui/loading-button";
import { AuthStateCard } from "@/components/auth/auth-state-card";

const resetPasswordSchema = z
  .object({
    password: z.string().min(8, "Password must be at least 8 characters"),
    password_confirmation: z.string(),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: "Passwords don't match",
    path: ["password_confirmation"],
  });

type ResetPasswordForm = z.infer<typeof resetPasswordSchema>;

function ResetPasswordContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [isLoading, setIsLoading] = useState(false);
  const [isSuccess, setIsSuccess] = useState(false);

  const token = searchParams.get("token");
  const email = searchParams.get("email");

  const {
    register,
    watch,
    handleSubmit,
    formState: { errors },
  } = useForm<ResetPasswordForm>({
    resolver: zodResolver(resetPasswordSchema),
  });

  const passwordValue = watch("password");

  const onSubmit = async (data: ResetPasswordForm) => {
    if (!token || !email) {
      toast.error("Invalid reset link");
      return;
    }

    setIsLoading(true);
    try {
      await api.post("/auth/reset-password", {
        token,
        email,
        password: data.password,
        password_confirmation: data.password_confirmation,
      });
      setIsSuccess(true);
      toast.success("Password reset successfully!");
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to reset password"));
    } finally {
      setIsLoading(false);
    }
  };

  // Invalid or missing token
  if (!token || !email) {
    return (
      <AuthStateCard
        variant="error"
        icon={AlertTriangle}
        title="Invalid Reset Link"
        description="This password reset link is invalid or has expired."
        footer={
          <div className="flex flex-col gap-2 w-full">
            <Link href="/forgot-password" className="w-full">
              <Button className="w-full">Request New Link</Button>
            </Link>
            <Link href="/login" className="w-full">
              <Button variant="ghost" className="w-full">
                <ArrowLeft className="mr-2 h-4 w-4" />
                Back to Sign In
              </Button>
            </Link>
          </div>
        }
      >
        <Alert variant="destructive">
          <AlertTriangle className="h-4 w-4" />
          <AlertTitle>Error</AlertTitle>
          <AlertDescription>
            Please request a new password reset link. Reset links expire
            after 60 minutes for security reasons.
          </AlertDescription>
        </Alert>
      </AuthStateCard>
    );
  }

  // Success state
  if (isSuccess) {
    return (
      <AuthStateCard
        variant="success"
        title="Password Reset"
        description="Your password has been reset successfully."
        footer={
          <Link href="/login" className="w-full">
            <Button className="w-full">Sign In</Button>
          </Link>
        }
      >
        <p className="text-sm text-muted-foreground text-center">
          You can now sign in with your new password.
        </p>
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
            <CardTitle className="text-xl">Reset Password</CardTitle>
            <CardDescription>
              Enter your new password for{" "}
              <span className="font-medium text-foreground">{email}</span>
            </CardDescription>
          </CardHeader>
          <form onSubmit={handleSubmit(onSubmit)}>
            <CardContent className="space-y-4">
              <FormField
                id="password"
                label="New Password"
                error={errors.password?.message}
              >
                <PasswordInput
                  id="password"
                  placeholder="••••••••"
                  {...register("password")}
                  disabled={isLoading}
                />
              </FormField>
              <PasswordStrength password={passwordValue ?? ""} showRequirements className="mt-1" />

              <FormField
                id="password_confirmation"
                label="Confirm New Password"
                error={errors.password_confirmation?.message}
              >
                <PasswordInput
                  id="password_confirmation"
                  placeholder="••••••••"
                  {...register("password_confirmation")}
                  disabled={isLoading}
                />
              </FormField>
            </CardContent>
            <CardFooter className="flex flex-col gap-4">
              <LoadingButton
                type="submit"
                className="w-full"
                isLoading={isLoading}
                loadingText="Resetting..."
              >
                Reset Password
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

export default function ResetPasswordPage() {
  return (
    <Suspense
      fallback={
        <div className="bg-muted flex min-h-svh items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      }
    >
      <ResetPasswordContent />
    </Suspense>
  );
}
