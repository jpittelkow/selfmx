"use client";

import { useState, useEffect } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { Check, Loader2, XCircle } from "lucide-react";
import { useAuth } from "@/lib/auth";
import { useAppConfig } from "@/lib/app-config";
import { useEmailAvailability } from "@/lib/use-email-availability";
import { Input } from "@/components/ui/input";
import { PasswordInput } from "@/components/ui/password-input";
import { PasswordStrength } from "@/components/ui/password-strength";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { SSOButtons } from "@/components/auth/sso-buttons";
import { AuthPageLayout } from "@/components/auth/auth-page-layout";
import { AuthDivider } from "@/components/auth/auth-divider";
import { FormField } from "@/components/ui/form-field";
import { LoadingButton } from "@/components/ui/loading-button";
import { cn } from "@/lib/utils";

const registerSchema = z.object({
  name: z.string().min(2, "Name must be at least 2 characters"),
  email: z.string().email("Invalid email address"),
  password: z.string().min(8, "Password must be at least 8 characters"),
  password_confirmation: z.string(),
}).refine((data) => data.password === data.password_confirmation, {
  message: "Passwords don't match",
  path: ["password_confirmation"],
});

type RegisterForm = z.infer<typeof registerSchema>;

export default function RegisterPage() {
  const router = useRouter();
  const { register: registerUser } = useAuth();
  const { features } = useAppConfig();
  const [isLoading, setIsLoading] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [hasSSOProviders, setHasSSOProviders] = useState(false);
  const { isChecking, isAvailable, error: availabilityError, checkEmail } =
    useEmailAvailability();

  const {
    register,
    watch,
    handleSubmit,
    setError,
    clearErrors,
    formState: { errors },
  } = useForm<RegisterForm>({
    resolver: zodResolver(registerSchema),
    mode: "onBlur",
  });

  const emailValue = watch("email");
  const passwordValue = watch("password");

  useEffect(() => {
    if (emailValue?.trim()) {
      checkEmail(emailValue);
    }
  }, [emailValue, checkEmail]);

  useEffect(() => {
    if (isAvailable === false && !isChecking) {
      setError("email", { message: "This email is already registered" });
    } else if (isAvailable === true || isAvailable === undefined) {
      clearErrors("email");
    }
  }, [isAvailable, isChecking, setError, clearErrors]);

  const onSubmit = async (data: RegisterForm) => {
    setIsLoading(true);
    setFormError(null);
    try {
      await registerUser(data.name, data.email, data.password, data.password_confirmation);
      toast.success("Account created successfully!");
      router.push("/mail");
    } catch (error: unknown) {
      const message = error instanceof Error ? error.message : "Registration failed";
      setFormError(message);
    } finally {
      setIsLoading(false);
    }
  };

  const emailError = errors.email?.message ?? availabilityError ?? undefined;

  return (
    <AuthPageLayout
      title="Create Account"
      description="Get started with your free account"
    >
      <SSOButtons onLoad={setHasSSOProviders} />

      {hasSSOProviders && <AuthDivider />}

      {features?.emailVerificationAvailable && (
        <Alert variant="default" className="mb-4">
          <AlertDescription>
            After registration, you'll need to verify your email address before
            your account is activated.
          </AlertDescription>
        </Alert>
      )}

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        {formError && (
          <Alert variant="destructive">
            <AlertDescription>{formError}</AlertDescription>
          </Alert>
        )}
        <FormField
          id="name"
          label="Name"
          error={errors.name?.message}
        >
          <Input
            id="name"
            type="text"
            placeholder="John Doe"
            className="h-10"
            autoFocus
            {...register("name")}
            disabled={isLoading}
            aria-invalid={!!errors.name?.message}
            aria-describedby={errors.name?.message ? "name-error" : undefined}
          />
        </FormField>

        <FormField
          id="email"
          label="Email"
          error={emailError}
        >
          <div className="relative">
            <Input
              id="email"
              type="email"
              placeholder="you@example.com"
              className={cn(
                "h-10",
                (isChecking || isAvailable !== undefined) && "pr-10"
              )}
              {...register("email")}
              disabled={isLoading}
              aria-invalid={!!emailError}
              aria-describedby={
                emailError
                  ? "email-error"
                  : isAvailable === true
                    ? "email-available"
                    : isAvailable === false
                      ? "email-taken"
                      : undefined
              }
            />
            <div className="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none flex items-center">
              {isChecking && (
                <Loader2 className="h-4 w-4 text-muted-foreground animate-spin" aria-hidden />
              )}
              {!isChecking && isAvailable === true && (
                <Check className="h-4 w-4 text-green-600 dark:text-green-500" aria-hidden id="email-available" />
              )}
              {!isChecking && isAvailable === false && (
                <XCircle className="h-4 w-4 text-destructive" aria-hidden id="email-taken" />
              )}
            </div>
          </div>
        </FormField>

        <FormField
          id="password"
          label="Password"
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
          label="Confirm Password"
          error={errors.password_confirmation?.message}
        >
          <PasswordInput
            id="password_confirmation"
            placeholder="••••••••"
            {...register("password_confirmation")}
            disabled={isLoading}
          />
        </FormField>

        <LoadingButton
          type="submit"
          className="w-full"
          isLoading={isLoading}
          loadingText="Creating account..."
        >
          Create Account
        </LoadingButton>
      </form>

      <p className="text-center text-sm text-muted-foreground">
        Already have an account?{" "}
        <Link href="/login" className="text-primary hover:underline">
          Sign in
        </Link>
      </p>
    </AuthPageLayout>
  );
}
