"use client";

import { useState } from "react";
import Image from "next/image";
import { useAppConfig } from "@/lib/app-config";
import { useTheme } from "@/components/theme-provider";
import { cn } from "@/lib/utils";
import { APP_CONFIG } from "@/config/app";

export interface LogoProps {
  /** Display variant */
  variant?: "full" | "icon" | "text";
  /** Size preset */
  size?: "sm" | "md" | "lg";
  /** Additional CSS classes */
  className?: string;
}

const sizeConfig = {
  sm: {
    icon: "h-6 w-6",
    text: "text-sm",
    full: "h-6",
    gap: "gap-1.5",
  },
  md: {
    icon: "h-8 w-8",
    text: "text-lg",
    full: "h-8",
    gap: "gap-2",
  },
  lg: {
    icon: "h-10 w-10",
    text: "text-xl",
    full: "h-10",
    gap: "gap-2.5",
  },
};

/**
 * Logo component with multiple display variants and automatic fallbacks.
 * 
 * - `full`: Shows logo image (replaces app name) or icon + app name if no logo
 * - `icon`: Shows favicon image (falls back to first letter in styled container)
 * - `text`: Shows app name as text only
 */
export function Logo({ variant = "full", size = "md", className }: LogoProps) {
  const [logoError, setLogoError] = useState(false);
  const [logoDarkError, setLogoDarkError] = useState(false);
  const [faviconError, setFaviconError] = useState(false);
  const { appName, logoUrl, logoDarkUrl, faviconUrl } = useAppConfig();
  const { resolvedTheme } = useTheme();
  const sizes = sizeConfig[size];
  
  // Use dark logo in dark mode if available, fall back to regular logo
  const isDark = resolvedTheme === "dark";
  const currentLogo = isDark && logoDarkUrl && !logoDarkError
    ? logoDarkUrl
    : logoUrl;

  // Text-only variant
  if (variant === "text") {
    return (
      <span className={cn("font-semibold", sizes.text, className)} suppressHydrationWarning>
        {appName}
      </span>
    );
  }

  // Icon variant (for collapsed sidebar)
  if (variant === "icon") {
    // If favicon exists, show it
    if (faviconUrl && !faviconError) {
      return (
        <Image
          src={faviconUrl}
          alt={appName || ''}
          width={size === "sm" ? 24 : size === "md" ? 32 : 40}
          height={size === "sm" ? 24 : size === "md" ? 32 : 40}
          className={cn("object-contain", className)}
          onError={() => setFaviconError(true)}
          unoptimized
        />
      );
    }

    // Fallback: Emoji icon
    return (
      <div
        className={cn(
          sizes.icon,
          "flex items-center justify-center",
          size === "sm" && "text-lg",
          size === "md" && "text-xl",
          size === "lg" && "text-2xl",
          className
        )}
        suppressHydrationWarning
      >
        {APP_CONFIG.emoji}
      </div>
    );
  }

  // Full variant (logo replaces app name, or icon + name fallback)
  // Order: logo from settings → app name from settings (app_name always has a default)
  const hasLogo = currentLogo && !logoError;

  // If logo exists, show only the logo (replaces app name)
  if (hasLogo) {
    return (
      <div className={cn("relative flex flex-shrink-0 items-center", sizes.full, "w-auto max-w-[200px]", className)}>
        <Image
          src={currentLogo}
          alt={appName || ''}
          width={200}
          height={size === "sm" ? 24 : size === "md" ? 32 : 40}
          className="object-contain"
          onError={() => {
            // If dark logo fails, mark it as errored so we fall back to regular logo
            if (isDark && currentLogo === logoDarkUrl) {
              setLogoDarkError(true);
            } else {
              setLogoError(true);
            }
          }}
          unoptimized
          key={currentLogo} // Re-render when logo URL changes (theme switch)
        />
      </div>
    );
  }

  // No logo: show emoji + app name
  return (
    <div className={cn("flex items-center", sizes.gap, className)}>
      <div
        className={cn(
          sizes.icon,
          "flex items-center justify-center flex-shrink-0",
          size === "sm" && "text-lg",
          size === "md" && "text-xl",
          size === "lg" && "text-2xl"
        )}
        suppressHydrationWarning
      >
        {APP_CONFIG.emoji}
      </div>
      <span className={cn("font-semibold", sizes.text)} suppressHydrationWarning>{appName}</span>
    </div>
  );
}
