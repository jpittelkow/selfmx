"use client";

import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { SaveButton } from "@/components/ui/save-button";

interface SettingsFormCardProps {
  title: string;
  description?: string;
  children: React.ReactNode;
  footer?: React.ReactNode;
  isDirty?: boolean;
  isSaving?: boolean;
  onSubmit?: () => void;
}

export function SettingsFormCard({
  title,
  description,
  children,
  footer,
  isDirty,
  isSaving,
  onSubmit,
}: SettingsFormCardProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        {description && <CardDescription>{description}</CardDescription>}
      </CardHeader>
      <CardContent className="space-y-4">{children}</CardContent>
      {footer !== undefined ? (
        footer && <CardFooter>{footer}</CardFooter>
      ) : onSubmit ? (
        <CardFooter>
          <SaveButton
            isDirty={isDirty ?? false}
            isSaving={isSaving ?? false}
            onClick={onSubmit}
          />
        </CardFooter>
      ) : null}
    </Card>
  );
}
