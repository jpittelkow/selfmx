"use client";

import { create } from "zustand";
import { api } from "./api";
import { detectBrowserTimezone } from "./timezones";
import { setUserTimezone } from "./utils";

export interface User {
  id: number;
  name: string;
  email: string;
  avatar: string | null;
  is_admin: boolean;
  email_verified_at: string | null;
  groups?: { id: number; name: string; slug: string }[];
  permissions?: string[];
}

/** Extended user type for admin views with additional fields. */
export interface AdminUser extends User {
  disabled_at: string | null;
  created_at: string;
}

/**
 * Fire-and-forget: send the browser's timezone to the backend.
 * Only sets it if the user hasn't explicitly chosen one.
 * Also immediately sets the browser timezone as a temporary frontend
 * value so date formatting works before fetchUser() completes.
 */
function sendDetectedTimezone(): void {
  const tz = detectBrowserTimezone();
  setUserTimezone(tz);
  api.post("/user/settings/detect-timezone", { timezone: tz }).catch(() => {
    // Silently ignore -- this is best-effort
  });
}

/** Whether the user is an admin (in admin group). Prefer over user.is_admin for resilience. */
export function isAdminUser(user: User | null): boolean {
  if (!user) return false;
  const g = user.groups;
  if (g?.length) return g.some((gr) => (typeof gr === "string" ? gr : gr.slug) === "admin");
  return Boolean(user.is_admin);
}

export interface LoginResult {
  requires_2fa?: boolean;
  user?: User;
  message?: string;
}

interface AuthState {
  user: User | null;
  isLoading: boolean;
  isInitialized: boolean;
  error: string | null;
  fetchUser: () => Promise<void>;
  initialize: () => Promise<void>;
  login: (email: string, password: string, remember?: boolean) => Promise<LoginResult>;
  register: (name: string, email: string, password: string, passwordConfirmation: string) => Promise<void>;
  logout: () => Promise<void>;
  verify2FA: (code: string, remember?: boolean, isRecoveryCode?: boolean) => Promise<void>;
}

export const useAuth = create<AuthState>((set, get) => ({
  user: null,
  isLoading: true,
  isInitialized: false,
  error: null,

  fetchUser: async () => {
    try {
      const response = await api.get("/auth/user");
      const data = response.data as {
        user: User;
        groups?: string[];
        permissions?: string[];
        timezone?: string;
      };
      const user = data.user
        ? { ...data.user, permissions: data.permissions ?? [] }
        : null;
      if (data.timezone) {
        setUserTimezone(data.timezone);
      }
      set({ user, isLoading: false, error: null });
    } catch (error) {
      set({ user: null, isLoading: false, error: null });
    }
  },

  initialize: async () => {
    // Prevent multiple initializations
    if (get().isInitialized) {
      return;
    }
    set({ isInitialized: true });
    await get().fetchUser();
  },

  login: async (email: string, password: string, remember = false) => {
    // Get CSRF cookie first
    await api.get("/sanctum/csrf-cookie", {
      baseURL: process.env.NEXT_PUBLIC_API_URL || "",
    });

    const response = await api.post("/auth/login", {
      email,
      password,
      remember,
    });

    if (response.data.requires_2fa) {
      return { requires_2fa: true };
    }

    set({ user: response.data.user, isLoading: false, error: null });
    sendDetectedTimezone();
    return response.data;
  },

  register: async (name: string, email: string, password: string, passwordConfirmation: string) => {
    // Get CSRF cookie first
    await api.get("/sanctum/csrf-cookie", {
      baseURL: process.env.NEXT_PUBLIC_API_URL || "",
    });

    const response = await api.post("/auth/register", {
      name,
      email,
      password,
      password_confirmation: passwordConfirmation,
    });

    set({ user: response.data.user, isLoading: false, error: null });
    sendDetectedTimezone();
  },

  logout: async () => {
    await api.post("/auth/logout");
    setUserTimezone(undefined);
    set({ user: null, error: null });
  },

  verify2FA: async (code: string, remember = false, isRecoveryCode = false) => {
    const response = await api.post("/auth/2fa/verify", {
      code,
      remember,
      is_recovery_code: isRecoveryCode,
    });

    set({ user: response.data.user, isLoading: false, error: null });
    sendDetectedTimezone();
  },
}));

// Export a hook for components to initialize auth state after mount
// This prevents race conditions with SSR hydration
export function useInitializeAuth() {
  const { initialize, isInitialized } = useAuth();

  // Use useEffect in the consuming component to call initialize()
  // This ensures it runs after hydration
  return { initialize, isInitialized };
}
