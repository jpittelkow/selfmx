import { cn } from "@/lib/utils";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

interface PriorityIndicatorProps {
  level: "high" | "medium" | "low";
  reasons?: string[];
  className?: string;
}

export function PriorityIndicator({ level, reasons, className }: PriorityIndicatorProps) {
  if (level === "low") return null;

  const config = {
    high: {
      color: "bg-orange-500",
      label: "High priority",
    },
    medium: {
      color: "bg-yellow-500",
      label: "Medium priority",
    },
  };

  const c = config[level];

  if (reasons && reasons.length > 0) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <span
            className={cn("inline-block h-2 w-2 rounded-full shrink-0", c.color, className)}
            aria-label={c.label}
          />
        </TooltipTrigger>
        <TooltipContent side="right" className="text-xs max-w-48">
          <p className="font-medium mb-1">{c.label}</p>
          <ul className="space-y-0.5">
            {reasons.map((r, i) => (
              <li key={i}>- {r}</li>
            ))}
          </ul>
        </TooltipContent>
      </Tooltip>
    );
  }

  return (
    <span
      className={cn("inline-block h-2 w-2 rounded-full shrink-0", c.color, className)}
      aria-label={c.label}
      title={c.label}
    />
  );
}
