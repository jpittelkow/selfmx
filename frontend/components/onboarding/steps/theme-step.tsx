"use client";

import { Palette } from "lucide-react";
import {
  WizardStep,
  WizardStepTitle,
  WizardStepDescription,
  WizardStepContent,
} from "@/components/onboarding/wizard-step";
import { ThemeToggle } from "@/components/theme-toggle";

export function ThemeStep() {
  return (
    <WizardStep>
      <div className="h-16 w-16 rounded-full bg-primary/10 flex items-center justify-center">
        <Palette className="h-8 w-8 text-primary" />
      </div>

      <WizardStepTitle>Choose your mode</WizardStepTitle>

      <WizardStepDescription>
        Select your preferred display mode. You can change this anytime.
      </WizardStepDescription>

      <WizardStepContent>
        <ThemeToggle />
      </WizardStepContent>
    </WizardStep>
  );
}
