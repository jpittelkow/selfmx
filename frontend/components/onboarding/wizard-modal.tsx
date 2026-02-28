"use client";

import { useCallback, useEffect, useMemo } from "react";
import { ChevronLeft, ChevronRight, X } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { useWizard } from "@/components/onboarding/wizard-provider";
import { useAppConfig } from "@/lib/app-config";
import { useAuth, isAdminUser } from "@/lib/auth";

// Step components
import { WelcomeStep } from "@/components/onboarding/steps/welcome-step";
import { ProfileStep } from "@/components/onboarding/steps/profile-step";
import { SecurityStep } from "@/components/onboarding/steps/security-step";
import { NotificationsStep } from "@/components/onboarding/steps/notifications-step";
import { ThemeStep } from "@/components/onboarding/steps/theme-step";
import { TourStep } from "@/components/onboarding/steps/tour-step";
import { CompletionStep } from "@/components/onboarding/steps/completion-step";

interface Step {
  id: string;
  title: string;
  component: React.ComponentType<{ onComplete?: () => void }>;
  optional?: boolean;
}

const STEPS: Step[] = [
  { id: "welcome", title: "Welcome", component: WelcomeStep },
  { id: "profile", title: "Profile", component: ProfileStep, optional: true },
  { id: "security", title: "Security", component: SecurityStep, optional: true },
  {
    id: "notifications",
    title: "Notifications",
    component: NotificationsStep,
    optional: true,
  },
  { id: "theme", title: "Theme", component: ThemeStep },
  { id: "tour", title: "Tour", component: TourStep },
  { id: "completion", title: "Complete", component: CompletionStep },
];

export function WizardModal() {
  const {
    showWizard,
    setShowWizard,
    currentStep,
    setCurrentStep,
    completeStep,
    completeWizard,
    dismissWizard,
  } = useWizard();
  const { features } = useAppConfig();
  const { user } = useAuth();
  const isAdmin = user ? isAdminUser(user) : false;

  const steps = useMemo(() => {
    const twoFactorMode = features?.twoFactorMode ?? "optional";
    const passkeyMode = features?.passkeyMode ?? "disabled";
    const securityDisabled =
      twoFactorMode === "disabled" && passkeyMode === "disabled";

    return STEPS.filter((s) => {
      if (s.id === "security" && securityDisabled) return false;
      if (s.id === "theme" && !isAdmin) return false;
      return true;
    });
  }, [features?.twoFactorMode, features?.passkeyMode, isAdmin]);

  useEffect(() => {
    if (currentStep >= steps.length && steps.length > 0) {
      setCurrentStep(steps.length - 1);
    }
  }, [currentStep, steps.length, setCurrentStep]);

  const totalSteps = steps.length;
  const isFirstStep = currentStep === 0;
  const isLastStep = currentStep === totalSteps - 1;
  const currentStepData = steps[currentStep];

  const StepComponent = currentStepData?.component;

  const handleNext = useCallback(async () => {
    if (currentStepData) {
      await completeStep(currentStepData.id);
    }
    if (isLastStep) {
      await completeWizard();
    } else {
      setCurrentStep(currentStep + 1);
    }
  }, [
    currentStep,
    currentStepData,
    isLastStep,
    completeStep,
    completeWizard,
    setCurrentStep,
  ]);

  const handlePrevious = useCallback(() => {
    if (!isFirstStep) {
      setCurrentStep(currentStep - 1);
    }
  }, [currentStep, isFirstStep, setCurrentStep]);

  const handleSkip = useCallback(async () => {
    await dismissWizard();
  }, [dismissWizard]);

  const handleComplete = useCallback(async () => {
    await completeWizard();
  }, [completeWizard]);

  const handleOpenChange = useCallback(
    (open: boolean) => {
      if (!open) {
        // User closed the modal - treat as dismiss
        dismissWizard();
      }
    },
    [dismissWizard]
  );

  // Progress indicators
  const progressDots = useMemo(
    () =>
      steps.map((step, index) => (
        <button
          key={step.id}
          onClick={() => setCurrentStep(index)}
          className={cn(
            "w-2 h-2 rounded-full transition-all",
            index === currentStep
              ? "bg-primary w-4"
              : index < currentStep
              ? "bg-primary/50"
              : "bg-muted-foreground/30"
          )}
          aria-label={`Go to step ${index + 1}: ${step.title}`}
        />
      )),
    [currentStep, setCurrentStep, steps]
  );

  if (!showWizard) {
    return null;
  }

  return (
    <Dialog open={showWizard} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0 gap-0" hideClose>
        <DialogDescription className="sr-only">
          Onboarding wizard: step {currentStep + 1} of {totalSteps}
        </DialogDescription>
        {/* Header */}
        <DialogHeader className="p-4 pb-0">
          <div className="flex items-center justify-between">
            <DialogTitle className="text-sm font-medium text-muted-foreground">
              Step {currentStep + 1} of {totalSteps}
            </DialogTitle>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8 -mr-2"
              onClick={handleSkip}
            >
              <X className="h-4 w-4" />
              <span className="sr-only">Skip wizard</span>
            </Button>
          </div>
        </DialogHeader>

        {/* Step Content */}
        <div className="p-6 pt-4 min-h-[280px] max-h-[400px] overflow-y-auto flex items-center justify-center">
          {StepComponent && (
            <StepComponent
              onComplete={isLastStep ? handleComplete : undefined}
            />
          )}
        </div>

        {/* Footer */}
        <div className="p-4 pt-0 space-y-4">
          {/* Progress dots */}
          <div className="flex items-center justify-center gap-1.5">
            {progressDots}
          </div>

          {/* Navigation buttons - hidden on last step (completion has its own CTA) */}
          {!isLastStep && (
            <div className="flex items-center justify-between gap-2">
              <Button
                variant="ghost"
                size="sm"
                onClick={handlePrevious}
                disabled={isFirstStep}
                className={cn(isFirstStep && "invisible")}
              >
                <ChevronLeft className="h-4 w-4 mr-1" />
                Back
              </Button>

              {isFirstStep && (
                <Button variant="ghost" size="sm" onClick={handleSkip}>
                  Skip
                </Button>
              )}

              <Button size="sm" onClick={handleNext}>
                Next
                <ChevronRight className="h-4 w-4 ml-1" />
              </Button>
            </div>
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}
