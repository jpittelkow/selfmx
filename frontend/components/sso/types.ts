import { z } from "zod";
import type { SSOSetupProviderId } from "@/components/admin/sso-setup-modal";

export const ssoSchema = z.object({
  enabled: z.boolean(),
  allow_linking: z.boolean(),
  auto_register: z.boolean(),
  trust_provider_email: z.boolean(),
  google_enabled: z.boolean(),
  github_enabled: z.boolean(),
  microsoft_enabled: z.boolean(),
  apple_enabled: z.boolean(),
  discord_enabled: z.boolean(),
  gitlab_enabled: z.boolean(),
  oidc_enabled: z.boolean(),
  google_test_passed: z.boolean().optional(),
  github_test_passed: z.boolean().optional(),
  microsoft_test_passed: z.boolean().optional(),
  apple_test_passed: z.boolean().optional(),
  discord_test_passed: z.boolean().optional(),
  gitlab_test_passed: z.boolean().optional(),
  oidc_test_passed: z.boolean().optional(),
  google_client_id: z
    .string()
    .optional()
    .refine((val) => !val || val.endsWith(".apps.googleusercontent.com"), {
      message: "Google Client ID should end with .apps.googleusercontent.com",
    }),
  google_client_secret: z.string().optional(),
  github_client_id: z.string().optional(),
  github_client_secret: z.string().optional(),
  microsoft_client_id: z
    .string()
    .optional()
    .refine(
      (val) =>
        !val ||
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(val),
      { message: "Microsoft Client ID should be a UUID (e.g., 12345678-1234-1234-1234-123456789abc)" }
    ),
  microsoft_client_secret: z.string().optional(),
  apple_client_id: z.string().optional(),
  apple_client_secret: z.string().optional(),
  discord_client_id: z.string().optional(),
  discord_client_secret: z.string().optional(),
  gitlab_client_id: z.string().optional(),
  gitlab_client_secret: z.string().optional(),
  oidc_client_id: z.string().optional(),
  oidc_client_secret: z.string().optional(),
  oidc_issuer_url: z
    .union([z.literal(""), z.string().url("Invalid URL")])
    .optional()
    .refine((val) => !val || val.trim() === "" || val.startsWith("https://"), {
      message: "Issuer URL must use HTTPS",
    }),
  oidc_provider_name: z.string().optional(),
});

export type SSOForm = z.infer<typeof ssoSchema>;

export const defaultValues: SSOForm = {
  enabled: true,
  allow_linking: true,
  auto_register: true,
  trust_provider_email: true,
  google_enabled: true,
  github_enabled: true,
  microsoft_enabled: true,
  apple_enabled: true,
  discord_enabled: true,
  gitlab_enabled: true,
  oidc_enabled: true,
  google_test_passed: false,
  github_test_passed: false,
  microsoft_test_passed: false,
  apple_test_passed: false,
  discord_test_passed: false,
  gitlab_test_passed: false,
  oidc_test_passed: false,
  google_client_id: "",
  google_client_secret: "",
  github_client_id: "",
  github_client_secret: "",
  microsoft_client_id: "",
  microsoft_client_secret: "",
  apple_client_id: "",
  apple_client_secret: "",
  discord_client_id: "",
  discord_client_secret: "",
  gitlab_client_id: "",
  gitlab_client_secret: "",
  oidc_client_id: "",
  oidc_client_secret: "",
  oidc_issuer_url: "",
  oidc_provider_name: "Enterprise SSO",
};

export const providers: Array<{
  id: SSOSetupProviderId;
  label: string;
  clientIdKey: keyof SSOForm;
  clientSecretKey: keyof SSOForm;
  enabledKey: keyof SSOForm;
  testPassedKey: keyof SSOForm;
}> = [
  { id: "google", label: "Google", clientIdKey: "google_client_id", clientSecretKey: "google_client_secret", enabledKey: "google_enabled", testPassedKey: "google_test_passed" },
  { id: "github", label: "GitHub", clientIdKey: "github_client_id", clientSecretKey: "github_client_secret", enabledKey: "github_enabled", testPassedKey: "github_test_passed" },
  { id: "microsoft", label: "Microsoft", clientIdKey: "microsoft_client_id", clientSecretKey: "microsoft_client_secret", enabledKey: "microsoft_enabled", testPassedKey: "microsoft_test_passed" },
  { id: "apple", label: "Apple", clientIdKey: "apple_client_id", clientSecretKey: "apple_client_secret", enabledKey: "apple_enabled", testPassedKey: "apple_test_passed" },
  { id: "discord", label: "Discord", clientIdKey: "discord_client_id", clientSecretKey: "discord_client_secret", enabledKey: "discord_enabled", testPassedKey: "discord_test_passed" },
  { id: "gitlab", label: "GitLab", clientIdKey: "gitlab_client_id", clientSecretKey: "gitlab_client_secret", enabledKey: "gitlab_enabled", testPassedKey: "gitlab_test_passed" },
];

export function toBool(v: unknown): boolean {
  if (typeof v === "boolean") return v;
  if (v === "true" || v === "1") return true;
  if (v === "false" || v === "0" || v === "" || v == null) return false;
  return Boolean(v);
}

export function getRedirectUri(baseUrl: string, provider: string): string {
  const base = baseUrl.replace(/\/$/, "");
  return `${base}/api/auth/callback/${provider}`;
}

export const GLOBAL_KEYS: (keyof SSOForm)[] = [
  "enabled",
  "allow_linking",
  "auto_register",
  "trust_provider_email",
];

export function getProviderKeys(provider: SSOSetupProviderId): (keyof SSOForm)[] {
  if (provider === "oidc") {
    return ["oidc_enabled", "oidc_client_id", "oidc_client_secret", "oidc_issuer_url", "oidc_provider_name"];
  }
  return [
    `${provider}_enabled` as keyof SSOForm,
    `${provider}_client_id` as keyof SSOForm,
    `${provider}_client_secret` as keyof SSOForm,
  ];
}
