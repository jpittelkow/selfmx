"use client";

import * as React from "react";
import { DEFAULT_THEME_ID, COLOR_THEME_STORAGE_KEY, getThemeById } from "@/lib/themes";

type Theme = "dark" | "light" | "system";

interface ThemeProviderProps {
  children: React.ReactNode;
  defaultTheme?: Theme;
  storageKey?: string;
}

interface ThemeProviderState {
  /** Light/dark/system mode */
  theme: Theme;
  setTheme: (theme: Theme) => void;
  resolvedTheme: "dark" | "light";
  /** Color theme ID (e.g. "default", "ocean") */
  colorTheme: string;
  setColorTheme: (colorTheme: string) => void;
}

const ThemeProviderContext = React.createContext<ThemeProviderState | undefined>(
  undefined
);

export function ThemeProvider({
  children,
  defaultTheme = "light",
  storageKey = "sourdough-theme",
}: ThemeProviderProps) {
  const [theme, setTheme] = React.useState<Theme>(defaultTheme);
  const [resolvedTheme, setResolvedTheme] = React.useState<"dark" | "light">("light");
  const [colorTheme, setColorThemeState] = React.useState<string>(DEFAULT_THEME_ID);
  const [mounted, setMounted] = React.useState(false);

  // Load theme and color theme from localStorage on mount
  React.useEffect(() => {
    const stored = localStorage.getItem(storageKey);
    const valid: Theme[] = ["light", "dark", "system"];
    if (stored && valid.includes(stored as Theme)) {
      setTheme(stored as Theme);
    }

    const storedColorTheme = localStorage.getItem(COLOR_THEME_STORAGE_KEY);
    if (storedColorTheme && getThemeById(storedColorTheme)) {
      setColorThemeState(storedColorTheme);
    }

    setMounted(true);
  }, [storageKey]);

  // Update resolved theme and apply class
  React.useEffect(() => {
    if (!mounted) return;

    const root = window.document.documentElement;
    root.classList.remove("light", "dark");

    let resolved: "dark" | "light" = "light";

    if (theme === "system") {
      resolved = window.matchMedia("(prefers-color-scheme: dark)").matches
        ? "dark"
        : "light";
    } else {
      resolved = theme;
    }

    root.classList.add(resolved);
    setResolvedTheme(resolved);
  }, [theme, mounted]);

  // Apply data-theme attribute
  React.useEffect(() => {
    if (!mounted) return;
    document.documentElement.setAttribute("data-theme", colorTheme);
  }, [colorTheme, mounted]);

  // Listen for system theme changes
  React.useEffect(() => {
    if (!mounted || theme !== "system") return;

    const mediaQuery = window.matchMedia("(prefers-color-scheme: dark)");

    const handleChange = (e: MediaQueryListEvent) => {
      const root = window.document.documentElement;
      root.classList.remove("light", "dark");
      const newTheme = e.matches ? "dark" : "light";
      root.classList.add(newTheme);
      setResolvedTheme(newTheme);
    };

    mediaQuery.addEventListener("change", handleChange);
    return () => mediaQuery.removeEventListener("change", handleChange);
  }, [theme, mounted]);

  const value = React.useMemo(
    () => ({
      theme,
      setTheme: (newTheme: Theme) => {
        localStorage.setItem(storageKey, newTheme);
        setTheme(newTheme);
      },
      resolvedTheme,
      colorTheme,
      setColorTheme: (newColorTheme: string) => {
        localStorage.setItem(COLOR_THEME_STORAGE_KEY, newColorTheme);
        setColorThemeState(newColorTheme);
      },
    }),
    [theme, resolvedTheme, colorTheme, storageKey]
  );

  return (
    <ThemeProviderContext.Provider value={value}>
      {children}
    </ThemeProviderContext.Provider>
  );
}

export function useTheme() {
  const context = React.useContext(ThemeProviderContext);
  if (context === undefined) {
    throw new Error("useTheme must be used within a ThemeProvider");
  }
  return context;
}
