"use client";

import { useTheme } from "@/components/theme-provider";
import { themes, type ThemeDefinition, type ThemePreviewColors } from "@/lib/themes";
import { cn } from "@/lib/utils";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { Check, Sun, Moon, Monitor } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";

type Mode = "light" | "dark" | "system";

const modes: { value: Mode; icon: typeof Sun; label: string }[] = [
  { value: "light", icon: Sun, label: "Light" },
  { value: "dark", icon: Moon, label: "Dark" },
  { value: "system", icon: Monitor, label: "System" },
];

function MiniPreview({ colors, label }: { colors: ThemePreviewColors; label: string }) {
  return (
    <div className="flex-1 flex flex-col gap-0.5">
      <span className="text-[9px] text-muted-foreground leading-none">{label}</span>
      <div
        className="rounded-sm border border-black/10 p-1.5 flex flex-col gap-1"
        style={{ backgroundColor: colors.background }}
      >
        <div className="flex gap-0.5">
          <div
            className="h-3 flex-1 rounded-sm"
            style={{ backgroundColor: colors.primary }}
          />
          <div
            className="h-3 flex-1 rounded-sm"
            style={{ backgroundColor: colors.secondary }}
          />
        </div>
        <div
          className="h-1 w-3/4 rounded-sm"
          style={{ backgroundColor: colors.foreground, opacity: 0.6 }}
        />
      </div>
    </div>
  );
}

interface ThemeSwatchProps {
  theme: ThemeDefinition;
  isSelected: boolean;
  onClick: () => void;
}

function ThemeSwatch({
  theme,
  isSelected,
  onClick,
}: ThemeSwatchProps) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <button
          type="button"
          onClick={onClick}
          className={cn(
            "relative flex flex-col items-start gap-2 rounded-lg border-2 p-3 transition-all hover:shadow-md",
            "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
            isSelected
              ? "border-primary bg-primary/5 shadow-sm"
              : "border-border hover:border-primary/50"
          )}
          aria-label={`${theme.name} theme`}
          aria-pressed={isSelected}
        >
          {/* Light + Dark side by side */}
          <div className="flex gap-1.5 w-full">
            <MiniPreview colors={theme.preview.light} label="Light" />
            <MiniPreview colors={theme.preview.dark} label="Dark" />
          </div>

          <span className="text-xs font-semibold">{theme.name}</span>

          {isSelected && (
            <div className="absolute top-1.5 right-1.5 h-4 w-4 rounded-full bg-primary flex items-center justify-center">
              <Check className="h-3 w-3 text-primary-foreground" />
            </div>
          )}
        </button>
      </TooltipTrigger>
      <TooltipContent>
        <p>{theme.description}</p>
      </TooltipContent>
    </Tooltip>
  );
}

interface ThemePickerProps {
  compact?: boolean;
  className?: string;
}

export function ThemePicker({ compact, className }: ThemePickerProps) {
  const { theme, setTheme, colorTheme, setColorTheme } = useTheme();
  const { user } = useAuth();

  const handleModeChange = (newMode: Mode) => {
    setTheme(newMode);
    if (user) {
      api.put("/user/settings", { theme: newMode }).catch(() => {});
    }
  };

  return (
    <div className={cn("space-y-4", className)}>
      {/* Light / Dark / System mode toggle */}
      <div className="space-y-2">
        <Label className="text-sm font-medium">Mode</Label>
        <div className="flex gap-1">
          {modes.map(({ value, icon: Icon, label }) => (
            <Button
              key={value}
              type="button"
              variant={theme === value ? "default" : "outline"}
              size="sm"
              className="flex items-center gap-1.5"
              onClick={() => handleModeChange(value)}
              aria-pressed={theme === value}
            >
              <Icon className="h-3.5 w-3.5" />
              {label}
            </Button>
          ))}
        </div>
      </div>

      {/* Color theme grid — each swatch shows both light and dark previews */}
      <div className="space-y-2">
        <Label className="text-sm font-medium">Color Theme</Label>
        <div
          className={cn(
            "grid",
            compact
              ? "grid-cols-3 gap-2"
              : "grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3"
          )}
          role="group"
          aria-label="Color theme"
        >
          {themes.map((t) => (
            <ThemeSwatch
              key={t.id}
              theme={t}
              isSelected={colorTheme === t.id}
              onClick={() => setColorTheme(t.id)}
            />
          ))}
        </div>
      </div>
    </div>
  );
}
