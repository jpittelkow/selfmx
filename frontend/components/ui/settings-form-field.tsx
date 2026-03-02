import { Label } from "@/components/ui/label";
import { HelpTooltip } from "@/components/ui/help-tooltip";
import { cn } from "@/lib/utils";

interface SettingsFormFieldProps {
  label: string;
  description?: string;
  tooltip?: string;
  error?: string;
  required?: boolean;
  className?: string;
  children: React.ReactNode;
}

export function SettingsFormField({
  label,
  description,
  tooltip,
  error,
  required,
  className,
  children,
}: SettingsFormFieldProps) {
  return (
    <div className={cn("space-y-2", className)}>
      <div className="flex items-center gap-1.5">
        <Label>
          {label}
          {required && <span className="text-destructive ml-0.5">*</span>}
        </Label>
        {tooltip && <HelpTooltip content={tooltip} />}
      </div>
      {children}
      {description && !error && (
        <p className="text-xs text-muted-foreground">{description}</p>
      )}
      {error && (
        <p className="text-xs text-destructive">{error}</p>
      )}
    </div>
  );
}
