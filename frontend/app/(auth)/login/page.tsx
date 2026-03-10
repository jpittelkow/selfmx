"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { useForm, Controller } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { useAuth } from "@/lib/auth";
import { useAppConfig } from "@/lib/app-config";
import { getErrorMessage } from "@/lib/utils";
import { Input } from "@/components/ui/input";
import { PasswordInput } from "@/components/ui/password-input";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { SSOButtons } from "@/components/auth/sso-buttons";
import { TwoFactorForm } from "@/components/auth/two-factor-form";
import { PasskeyLoginButton } from "@/components/auth/passkey-login-button";
import { AuthPageLayout } from "@/components/auth/auth-page-layout";
import { AuthDivider } from "@/components/auth/auth-divider";
import { isPasskeySupported } from "@/lib/use-passkeys";
import { FormField } from "@/components/ui/form-field";
import { LoadingButton } from "@/components/ui/loading-button";

const loginSchema = z.object({
  email: z.string().email("Invalid email address"),
  password: z.string().min(1, "Password is required"),
  remember: z.boolean().optional(),
});

type LoginForm = z.infer<typeof loginSchema>;

export default function LoginPage() {
  const router = useRouter();
  const { login } = useAuth();
  const { features, isLoading: isConfigLoading } = useAppConfig();
  const passkeyMode = features?.passkeyMode ?? "disabled";
  const showPasskeyLogin = passkeyMode !== "disabled" && isPasskeySupported();
  const [isLoading, setIsLoading] = useState(false);
  const [requires2FA, setRequires2FA] = useState(false);
  const [hasSSOProviders, setHasSSOProviders] = useState(false);

  const {
    register,
    control,
    handleSubmit,
    formState: { errors },
  } = useForm<LoginForm>({
    resolver: zodResolver(loginSchema),
    defaultValues: {
      remember: false,
    },
  });

  const onSubmit = async (data: LoginForm) => {
    setIsLoading(true);
    try {
      const result = await login(data.email, data.password, data.remember);

      if (result.requires_2fa) {
        setRequires2FA(true);
        return;
      }

      toast.success("Welcome back!");
      router.push("/mail");
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Login failed"));
    } finally {
      setIsLoading(false);
    }
  };

  if (requires2FA) {
    return (
      <AuthPageLayout
        title="Two-Factor Authentication"
        description="Enter the code from your authenticator app"
      >
        <TwoFactorForm
          onSuccess={() => router.push("/mail")}
          onCancel={() => setRequires2FA(false)}
        />
      </AuthPageLayout>
    );
  }

  return (
    <AuthPageLayout
      title="Welcome back"
      description="Enter your credentials to access your account"
    >
      <SSOButtons onLoad={setHasSSOProviders} />

      {showPasskeyLogin && (
        <>
          {hasSSOProviders && <AuthDivider text="Or continue with passkey" />}
          <PasskeyLoginButton
            onSuccess={() => router.push("/mail")}
            className="w-full"
          />
        </>
      )}

      {(hasSSOProviders || showPasskeyLogin) && <AuthDivider />}

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        <FormField
          id="email"
          label="Email"
          error={errors.email?.message}
        >
          <Input
            id="email"
            type="email"
            placeholder="you@example.com"
            className="h-10"
            autoFocus
            {...register("email")}
            disabled={isLoading}
            aria-invalid={!!errors.email?.message}
            aria-describedby={errors.email?.message ? "email-error" : undefined}
          />
        </FormField>

        <FormField
          id="password"
          label={
            <div className="flex items-center justify-between w-full">
              <Label htmlFor="password">Password</Label>
              {!isConfigLoading && features?.passwordResetAvailable && (
                <Link
                  href="/forgot-password"
                  className="text-sm underline-offset-4 hover:underline"
                >
                  Forgot password?
                </Link>
              )}
            </div>
          }
          error={errors.password?.message}
        >
          <PasswordInput
            id="password"
            placeholder="••••••••"
            {...register("password")}
            disabled={isLoading}
          />
        </FormField>

        <div className="flex items-center space-x-2">
          <Controller
            name="remember"
            control={control}
            render={({ field }) => (
              <Checkbox
                id="remember"
                checked={field.value}
                onCheckedChange={field.onChange}
              />
            )}
          />
          <Label htmlFor="remember" className="text-sm font-normal">
            Remember me
          </Label>
        </div>

        <LoadingButton
          type="submit"
          className="w-full"
          isLoading={isLoading}
          loadingText="Signing in..."
        >
          Sign In
        </LoadingButton>
      </form>

      <p className="text-center text-sm text-muted-foreground">
        Don&apos;t have an account?{" "}
        <Link href="/register" className="underline-offset-4 hover:underline">
          Create one
        </Link>
      </p>
    </AuthPageLayout>
  );
}
