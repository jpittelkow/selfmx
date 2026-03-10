"use client";

import type { UseFormWatch, UseFormSetValue } from "react-hook-form";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { SettingsSwitchRow } from "@/components/ui/settings-switch-row";
import { SaveButton } from "@/components/ui/save-button";
import { TOOLTIP_CONTENT } from "@/lib/tooltip-content";
import type { SSOForm } from "./types";

interface SSOGlobalOptionsCardProps {
  watch: UseFormWatch<SSOForm>;
  setValue: UseFormSetValue<SSOForm>;
  isDirty: boolean;
  isSaving: boolean;
  onSave: () => void;
}

export function SSOGlobalOptionsCard({
  watch,
  setValue,
  isDirty,
  isSaving,
  onSave,
}: SSOGlobalOptionsCardProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Global options</CardTitle>
        <CardDescription>
          Master switch and behavior for SSO login and account linking.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <SettingsSwitchRow
          label="Enable SSO"
          description="Allow users to sign in with SSO providers"
          checked={watch("enabled")}
          onCheckedChange={(checked) => setValue("enabled", checked, { shouldDirty: true })}
        />
        <SettingsSwitchRow
          label="Allow account linking"
          description="Let users link multiple SSO providers to one account"
          tooltip={TOOLTIP_CONTENT.sso.allow_linking}
          checked={watch("allow_linking")}
          onCheckedChange={(checked) => setValue("allow_linking", checked, { shouldDirty: true })}
        />
        <SettingsSwitchRow
          label="Auto-register"
          description="Create accounts for new SSO logins"
          tooltip={TOOLTIP_CONTENT.sso.auto_register}
          checked={watch("auto_register")}
          onCheckedChange={(checked) => setValue("auto_register", checked, { shouldDirty: true })}
        />
        <SettingsSwitchRow
          label="Trust provider email"
          description="Treat SSO provider emails as verified"
          tooltip={TOOLTIP_CONTENT.sso.trust_provider_email}
          checked={watch("trust_provider_email")}
          onCheckedChange={(checked) => setValue("trust_provider_email", checked, { shouldDirty: true })}
        />
      </CardContent>
      <CardFooter className="flex flex-col gap-4 sm:flex-row sm:justify-between">
        <p className="text-sm text-muted-foreground">
          Master switch and SSO behavior options.
        </p>
        <SaveButton
          type="button"
          isDirty={isDirty}
          isSaving={isSaving}
          onClick={onSave}
        />
      </CardFooter>
    </Card>
  );
}
