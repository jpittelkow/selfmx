import { z } from "zod";

/**
 * Shared Zod validation schemas used across the application.
 * Individual page schemas may extend or compose these.
 */

/** Standard email validation. */
export const emailSchema = z.string().email("Please enter a valid email address");

/** Password schema with minimum length. */
export const passwordSchema = z.string().min(8, "Password must be at least 8 characters");

/** Password with confirmation. Use with .refine() on the parent object. */
export const passwordWithConfirmation = z.object({
  password: passwordSchema,
  password_confirmation: z.string(),
}).refine((data) => data.password === data.password_confirmation, {
  message: "Passwords do not match",
  path: ["password_confirmation"],
});

/** Non-empty trimmed string. */
export const requiredString = z.string().trim().min(1, "This field is required");

/** Optional string that can be empty. */
export const optionalString = z.string().optional();

/** URL with protocol validation. */
export const urlSchema = z.string().url("Please enter a valid URL");

/** Optional URL. */
export const optionalUrl = z.string().url("Please enter a valid URL").optional().or(z.literal(""));

/** Positive integer. */
export const positiveInt = z.number().int().positive();

/** Non-negative integer (0 or more). */
export const nonNegativeInt = z.number().int().nonnegative();

/** Hex color (#000 or #000000). */
export const hexColor = z.string().regex(/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/, "Must be a valid hex color");

/** Cron expression (basic 5-part). */
export const cronExpression = z.string().regex(
  /^([0-9,\-\/]+|\*(?:\/\d+)?)\s+([0-9,\-\/]+|\*(?:\/\d+)?)\s+([0-9,\-\/]+|\*(?:\/\d+)?)\s+([0-9,\-\/]+|\*(?:\/\d+)?)\s+([0-9,\-\/]+|\*(?:\/\d+)?)$/,
  "Must be a valid cron expression"
);

/** API key / token format (non-empty string). */
export const apiKeySchema = z.string().trim().min(1, "API key is required");

/** Webhook URL. */
export const webhookUrlSchema = urlSchema;
