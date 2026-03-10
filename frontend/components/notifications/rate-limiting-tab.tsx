"use client";

import { useState, useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { FormField } from "@/components/ui/form-field";
import { SaveButton } from "@/components/ui/save-button";

// ── Props ────────────────────────────────────────────────────────────────

interface RateLimitingTabProps {
  settings: Record<string, unknown>;
  onSaved: () => Promise<void>;
}

// ── Helpers ──────────────────────────────────────────────────────────────

function extractErrorMessage(err: unknown): string | null {
  if (err && typeof err === "object" && "response" in err) {
    return (err as { response?: { data?: { message?: string } } }).response?.data?.message ?? null;
  }
  return null;
}

// ── Schema ───────────────────────────────────────────────────────────────

const rateLimitSchema = z.object({
  rate_limit_enabled: z.boolean().default(false),
  rate_limit_max: z.coerce.number().int().min(1).max(1000).default(10),
  rate_limit_window_minutes: z.coerce.number().int().min(1).max(1440).default(60),
  queue_enabled: z.boolean().default(true),
});

// ── Exported component ───────────────────────────────────────────────────

export function RateLimitingTab({ settings, onSaved }: RateLimitingTabProps) {
  const {
    register,
    handleSubmit,
    formState: { errors, isDirty },
    watch,
    setValue,
    reset,
  } = useForm({
    resolver: zodResolver(rateLimitSchema),
    defaultValues: {
      rate_limit_enabled: settings.rate_limit_enabled === true,
      rate_limit_max: Number(settings.rate_limit_max) || 10,
      rate_limit_window_minutes: Number(settings.rate_limit_window_minutes) || 60,
      queue_enabled: settings.queue_enabled !== false,
    },
  });

  const serverKey = JSON.stringify([
    settings.rate_limit_enabled,
    settings.rate_limit_max,
    settings.rate_limit_window_minutes,
    settings.queue_enabled,
  ]);
  useEffect(() => {
    if (isDirty) return;
    reset({
      rate_limit_enabled: settings.rate_limit_enabled === true,
      rate_limit_max: Number(settings.rate_limit_max) || 10,
      rate_limit_window_minutes: Number(settings.rate_limit_window_minutes) || 60,
      queue_enabled: settings.queue_enabled !== false,
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [serverKey]);

  const [isSaving, setIsSaving] = useState(false);
  const rateLimitEnabled = watch("rate_limit_enabled");

  const onSubmit = async (data: z.infer<typeof rateLimitSchema>) => {
    setIsSaving(true);
    try {
      await api.put("/notification-settings", data);
      toast.success("Rate limiting settings saved");
      reset(data);
      await onSaved();
    } catch (err: unknown) {
      const msg = extractErrorMessage(err);
      toast.error(msg ?? "Failed to save rate limiting settings");
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Rate Limiting & Queue</CardTitle>
        <CardDescription>
          Control notification delivery rate per user per channel, and toggle async queue dispatch.
        </CardDescription>
      </CardHeader>
      <form onSubmit={handleSubmit(onSubmit)}>
        <CardContent className="space-y-6">
          <div className="flex items-center justify-between gap-4">
            <div>
              <Label>Async queue dispatch</Label>
              <p className="text-sm text-muted-foreground">
                Dispatch notifications via background queue instead of inline. Enables retry with exponential backoff for webhook channels.
              </p>
            </div>
            <Switch
              checked={watch("queue_enabled")}
              onCheckedChange={(checked: boolean) => setValue("queue_enabled", checked, { shouldDirty: true })}
            />
          </div>

          <div className="flex items-center justify-between gap-4">
            <div>
              <Label>Enable rate limiting</Label>
              <p className="text-sm text-muted-foreground">
                Limit how many notifications a user receives per channel within a time window.
              </p>
            </div>
            <Switch
              checked={rateLimitEnabled}
              onCheckedChange={(checked: boolean) => setValue("rate_limit_enabled", checked, { shouldDirty: true })}
            />
          </div>

          {rateLimitEnabled && (
            <div className="grid gap-4 sm:grid-cols-2 pl-4 border-l-2 border-muted">
              <FormField id="rate_limit_max" label="Max notifications per window" error={errors.rate_limit_max?.message as string | undefined}>
                <Input
                  id="rate_limit_max"
                  type="number"
                  min={1}
                  max={1000}
                  {...register("rate_limit_max")}
                  className="min-h-[44px]"
                />
              </FormField>
              <FormField id="rate_limit_window_minutes" label="Window (minutes)" error={errors.rate_limit_window_minutes?.message as string | undefined}>
                <Input
                  id="rate_limit_window_minutes"
                  type="number"
                  min={1}
                  max={1440}
                  {...register("rate_limit_window_minutes")}
                  className="min-h-[44px]"
                />
              </FormField>
            </div>
          )}
        </CardContent>
        <CardFooter>
          <SaveButton isDirty={isDirty} isSaving={isSaving}>
            Save
          </SaveButton>
        </CardFooter>
      </form>
    </Card>
  );
}
