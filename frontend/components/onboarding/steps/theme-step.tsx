"use client";

import { Palette } from "lucide-react";
import {
  WizardStep,
  WizardStepTitle,
  WizardStepDescription,
  WizardStepContent,
} from "@/components/onboarding/wizard-step";
import { ThemePicker } from "@/components/theme-picker";

export function ThemeStep() {
  return (
    <WizardStep>
      <div className="h-16 w-16 rounded-full bg-primary/10 flex items-center justify-center">
        <Palette className="h-8 w-8 text-primary" />
      </div>

      <WizardStepTitle>Choose your theme</WizardStepTitle>

      <WizardStepDescription>
        Select your preferred appearance and color palette. You can change these
        anytime.
      </WizardStepDescription>

      <WizardStepContent>
        <ThemePicker compact />
      </WizardStepContent>
    </WizardStep>
  );
}
