"use client";

import { useState, useEffect, useCallback } from "react";
import { api } from "@/lib/api";

export interface Passkey {
  id: string;
  alias: string;
  created_at: string | null;
  updated_at: string | null;
}

/**
 * Check if the browser supports WebAuthn / passkeys.
 */
export function isPasskeySupported(): boolean {
  if (typeof window === "undefined") return false;
  return (
    window.PublicKeyCredential !== undefined &&
    typeof window.PublicKeyCredential === "function"
  );
}

/**
 * Convert base64url to ArrayBuffer (for WebAuthn options from server).
 */
function base64urlToBuffer(base64url: string): ArrayBuffer {
  const base64 = base64url.replace(/-/g, "+").replace(/_/g, "/");
  const pad = base64.length % 4;
  const padded = pad ? base64 + "=".repeat(4 - pad) : base64;
  const binary = atob(padded);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes.buffer;
}

/**
 * Convert ArrayBuffer to base64url (for sending credential to server).
 */
function bufferToBase64url(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer);
  let binary = "";
  for (let i = 0; i < bytes.length; i++) {
    binary += String.fromCharCode(bytes[i]);
  }
  const base64 = btoa(binary);
  return base64.replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

/**
 * Transform server attestation/assertion options to the format expected by navigator.credentials.
 */
function transformOptions(options: Record<string, unknown>): CredentialRequestOptions | CredentialCreationOptions {
  const transformChallenge = (challenge: unknown) => {
    if (typeof challenge === "string") {
      return base64urlToBuffer(challenge);
    }
    return challenge as ArrayBuffer;
  };

  type AllowCredential = { id: string; type: string; transports?: string[] };
  const transformAllowCredentials = (allowList: unknown[]) => {
    if (!allowList || !Array.isArray(allowList)) return undefined;
    return (allowList as AllowCredential[]).map((item) => ({
      id: base64urlToBuffer(item.id),
      type: item.type || "public-key",
      transports: item.transports,
    }));
  };

  type PubKeyCredParam = { type: string; alg: number };
  const transformPubKeyCredParams = (params: unknown[]) => {
    if (!params || !Array.isArray(params)) return undefined;
    return (params as PubKeyCredParam[]).map((p) => ({
      type: p.type || "public-key",
      alg: p.alg ?? -7,
    }));
  };

  const transformUser = (user: { id: string; name: string; displayName?: string } | undefined) => {
    if (!user) return undefined;
    return {
      id: base64urlToBuffer(user.id),
      name: user.name ?? "",
      displayName: user.displayName ?? user.name ?? "",
    };
  };

  const challenge = options.challenge ?? options.challengeBase64;
  if (options.publicKey) {
    const pk = options.publicKey as Record<string, unknown>;
    return {
      publicKey: {
        rp: pk.rp as PublicKeyCredentialRpEntity,
        user: transformUser(pk.user as { id: string; name: string; displayName?: string }),
        challenge: transformChallenge(pk.challenge ?? challenge),
        pubKeyCredParams: transformPubKeyCredParams((pk.pubKeyCredParams ?? pk.pubKeyCredParams) as unknown[] ?? []),
        timeout: (pk.timeout as number) ?? 60000,
        attestation: (pk.attestation as AttestationConveyancePreference) ?? "none",
        authenticatorSelection: pk.authenticatorSelection as AuthenticatorSelectionCriteria | undefined,
      },
    } as CredentialCreationOptions;
  }

  if (options.allowCredentials !== undefined || options.allow_list !== undefined) {
    const allowList = (options.allowCredentials ?? options.allow_list) as unknown[] ?? [];
    return {
      publicKey: {
        challenge: transformChallenge(challenge),
        timeout: (options.timeout as number) ?? 60000,
        rpId: options.rpId as string | undefined,
        allowCredentials: transformAllowCredentials(allowList),
        userVerification: (options.userVerification as UserVerificationRequirement) ?? "preferred",
      },
    } as CredentialRequestOptions;
  }

  return options as CredentialRequestOptions | CredentialCreationOptions;
}

/**
 * Perform passkey (assertion) login flow: fetch options, get credential, verify with API.
 * Returns { success, user } on success; { success: false, error } on failure.
 * Caller should set auth state (e.g. fetchUser()) and redirect on success.
 */
export async function performPasskeyLogin(remember = false): Promise<
  { success: true; user: unknown } | { success: false; error: string }
> {
  try {
    const optionsRes = await api.post("/auth/passkeys/login/options");
    const options = transformOptions(optionsRes.data) as CredentialRequestOptions;
    const credential = await navigator.credentials.get(options);
    if (!credential || !(credential instanceof PublicKeyCredential)) {
      return { success: false, error: "Failed to get passkey" };
    }
    const response = credential.response as AuthenticatorAssertionResponse;
    const payload = {
      id: credential.id,
      rawId: bufferToBase64url(credential.rawId),
      type: credential.type,
      response: {
        clientDataJSON: bufferToBase64url(response.clientDataJSON),
        authenticatorData: bufferToBase64url(response.authenticatorData),
        signature: bufferToBase64url(response.signature),
        userHandle: response.userHandle
          ? bufferToBase64url(response.userHandle)
          : null,
      },
    };
    const loginRes = await api.post("/auth/passkeys/login", {
      ...payload,
      remember,
    });
    const user = loginRes.data?.user;
    if (!user) {
      return { success: false, error: "Invalid response" };
    }
    return { success: true, user };
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : "Login failed";
    if (
      String(message).toLowerCase().includes("cancel") ||
      (err as { name?: string })?.name === "NotAllowedError"
    ) {
      return { success: false, error: "Cancelled" };
    }
    return { success: false, error: message };
  }
}

export function usePasskeys() {
  const [passkeys, setPasskeys] = useState<Passkey[]>([]);
  const [loading, setLoading] = useState(true);
  const [supported] = useState(isPasskeySupported());

  const fetchPasskeys = useCallback(async () => {
    if (!supported) {
      setLoading(false);
      return;
    }
    try {
      const res = await api.get<{ passkeys: Passkey[] }>("/auth/passkeys");
      setPasskeys(res.data.passkeys ?? []);
    } catch {
      setPasskeys([]);
    } finally {
      setLoading(false);
    }
  }, [supported]);

  useEffect(() => {
    fetchPasskeys();
  }, [fetchPasskeys]);

  const registerPasskey = useCallback(
    async (name: string): Promise<{ success: boolean; error?: string }> => {
      try {
        const optionsRes = await api.post("/auth/passkeys/register/options");
        const options = transformOptions(optionsRes.data) as CredentialCreationOptions;
        const credential = await navigator.credentials.create(options);
        if (!credential || !(credential instanceof PublicKeyCredential)) {
          return { success: false, error: "Failed to create passkey" };
        }
        const response = credential.response as AuthenticatorAttestationResponse;
        const payload = {
          id: credential.id,
          rawId: bufferToBase64url(credential.rawId),
          type: credential.type,
          response: {
            clientDataJSON: bufferToBase64url(response.clientDataJSON),
            attestationObject: bufferToBase64url(response.attestationObject),
          },
        };
        await api.post("/auth/passkeys/register", {
          ...payload,
          name: name || "Passkey",
        });
        await fetchPasskeys();
        return { success: true };
      } catch (err: unknown) {
        const message = err instanceof Error ? err.message : "Registration failed";
        if (String(message).toLowerCase().includes("cancel") || (err as { name?: string })?.name === "NotAllowedError") {
          return { success: false, error: "Cancelled" };
        }
        return { success: false, error: message };
      }
    },
    [fetchPasskeys]
  );

  const deletePasskey = useCallback(
    async (id: string): Promise<boolean> => {
      try {
        await api.delete(`/auth/passkeys/${encodeURIComponent(id)}`);
        await fetchPasskeys();
        return true;
      } catch {
        return false;
      }
    },
    [fetchPasskeys]
  );

  const renamePasskey = useCallback(
    async (id: string, name: string): Promise<boolean> => {
      try {
        await api.put(`/auth/passkeys/${encodeURIComponent(id)}`, { name });
        await fetchPasskeys();
        return true;
      } catch {
        return false;
      }
    },
    [fetchPasskeys]
  );

  return {
    passkeys,
    loading,
    supported,
    fetchPasskeys,
    registerPasskey,
    deletePasskey,
    renamePasskey,
  };
}
