import { useState, useEffect, useCallback } from "react";
import { loadStripe, type Stripe } from "@stripe/stripe-js";
import { api } from "@/lib/api";
import { getErrorMessage } from "@/lib/utils";

// ---------------------------------------------------------------------------
// Stripe.js singleton loader
// ---------------------------------------------------------------------------

let stripePromise: Promise<Stripe | null> | null = null;
let cachedKey: string | null = null;

/**
 * Lazy-load and cache Stripe.js with the given publishable key.
 * Returns the same promise on subsequent calls (singleton).
 * Automatically resets if the key changes (e.g. switching test/live mode).
 */
export function getStripe(publishableKey: string): Promise<Stripe | null> {
  if (!stripePromise || cachedKey !== publishableKey) {
    cachedKey = publishableKey;
    stripePromise = loadStripe(publishableKey);
  }
  return stripePromise;
}

/** Reset the cached Stripe instance (e.g. when keys change). */
export function resetStripe() {
  stripePromise = null;
  cachedKey = null;
}

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface StripeSettings {
  enabled?: boolean;
  mode?: string;
  secret_key?: string;
  publishable_key?: string;
  webhook_secret?: string;
  platform_account_id?: string;
  platform_client_id?: string;
  application_fee_percent?: number;
  currency?: string;
  deployment_role?: 'platform' | 'fork';
}

export interface ConnectStatus {
  connected: boolean;
  account_id?: string;
  status?: string;
  details_submitted?: boolean;
  charges_enabled?: boolean;
  payouts_enabled?: boolean;
}

export interface Payment {
  id: number;
  user_id: number;
  stripe_payment_intent_id: string;
  amount: number;
  currency: string;
  status: string;
  description: string | null;
  application_fee_amount: number | null;
  created_at: string;
  paid_at: string | null;
  refunded_at: string | null;
  user?: { id: number; name: string; email: string };
}

export interface PaginatedPayments {
  data: Payment[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

// ---------------------------------------------------------------------------
// useStripeSettings — fetch settings + connect status
// ---------------------------------------------------------------------------

export function useStripeSettings() {
  const [settings, setSettings] = useState<StripeSettings>({});
  const [connectStatus, setConnectStatus] = useState<ConnectStatus>({ connected: false });
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetch = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const [settingsResult, connectResult] = await Promise.allSettled([
        api.get("/stripe/settings"),
        api.get("/stripe/connect/status"),
      ]);
      if (settingsResult.status === "fulfilled" && settingsResult.value?.data?.settings) {
        setSettings(settingsResult.value.data.settings);
      } else if (settingsResult.status === "rejected") {
        setError(getErrorMessage(settingsResult.reason, "Failed to load Stripe settings"));
      }
      if (connectResult.status === "fulfilled" && connectResult.value?.data) {
        setConnectStatus(connectResult.value.data);
      }
    } catch (err: unknown) {
      setError(getErrorMessage(err, "Failed to load Stripe settings"));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    fetch();
  }, [fetch]);

  return { settings, connectStatus, isLoading, error, refetch: fetch };
}

// ---------------------------------------------------------------------------
// usePayments — fetch paginated payments
// ---------------------------------------------------------------------------

export function usePayments(admin = false) {
  const [data, setData] = useState<PaginatedPayments | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);

  const fetch = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const endpoint = admin ? "/payments/admin" : "/payments";
      const res = await api.get(endpoint, { params: { page } });
      setData(res.data);
    } catch (err: unknown) {
      setError(getErrorMessage(err, "Failed to load payments"));
    } finally {
      setIsLoading(false);
    }
  }, [admin, page]);

  useEffect(() => {
    fetch();
  }, [fetch]);

  return { data, isLoading, error, page, setPage, refetch: fetch };
}
