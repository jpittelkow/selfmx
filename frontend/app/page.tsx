"use client";

import { useAuth } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { Logo } from "@/components/logo";
import Link from "next/link";
import { useVersion } from "@/lib/version-provider";
import { usePageTitle } from "@/lib/use-page-title";
import { Users, Bell, Brain } from "lucide-react";

const features = [
  {
    icon: Users,
    title: "User Management",
    description:
      "SSO, 2FA, password reset, email verification — all optional for easy self-hosting.",
  },
  {
    icon: Bell,
    title: "Notifications",
    description:
      "Email, Telegram, Discord, Signal, SMS, and push notifications.",
  },
  {
    icon: Brain,
    title: "AI/LLM Council",
    description:
      "Multiple LLM providers with single, aggregation, and council modes.",
  },
];

export default function Home() {
  const { user, isLoading } = useAuth();
  const { version } = useVersion();

  usePageTitle("Welcome");

  return (
    <main className="min-h-screen flex flex-col items-center justify-center p-8">
      <div className="max-w-2xl text-center space-y-8 flex-1 flex flex-col justify-center">
        <div className="flex justify-center">
          <Logo variant="full" size="lg" />
        </div>
        <p className="text-xl text-muted-foreground">
          A starter for AI to develop other apps — with enterprise-grade user management,
          multi-provider notifications, and AI/LLM orchestration.
        </p>

        <div className="flex gap-4 justify-center">
          {isLoading ? (
            <div className="animate-pulse h-10 w-32 bg-muted rounded-md" />
          ) : user ? (
            <Link href="/mail">
              <Button size="lg">Go to Inbox</Button>
            </Link>
          ) : (
            <>
              <Link href="/login">
                <Button size="lg" variant="default">Sign In</Button>
              </Link>
              <Link href="/register">
                <Button size="lg" variant="outline">Create Account</Button>
              </Link>
            </>
          )}
        </div>

        <div className="pt-8 grid grid-cols-1 md:grid-cols-3 gap-6 text-left">
          {features.map((feature) => (
            <div
              key={feature.title}
              className="group p-6 rounded-xl border bg-card transition-colors hover:border-primary/30 hover:bg-primary/5"
            >
              <div className="mb-3 inline-flex rounded-lg bg-primary/10 p-2.5 text-primary">
                <feature.icon className="h-5 w-5" />
              </div>
              <h3 className="font-semibold mb-1.5">{feature.title}</h3>
              <p className="text-sm text-muted-foreground leading-relaxed">
                {feature.description}
              </p>
            </div>
          ))}
        </div>

        {version && (
          <div className="mt-auto pt-8">
            <p className="text-xs text-muted-foreground">
              v{version}
            </p>
          </div>
        )}
      </div>
    </main>
  );
}
