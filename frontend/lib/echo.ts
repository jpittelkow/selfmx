"use client";

import type Echo from "laravel-echo";

let echoInstance: Echo<"pusher"> | null = null;

function getAuthEndpoint(): string {
  const base = process.env.NEXT_PUBLIC_API_URL || "";
  return base ? `${base.replace(/\/api\/?$/, "")}/broadcasting/auth` : "/broadcasting/auth";
}

/**
 * Returns a configured Laravel Echo instance for real-time notifications, or null
 * when Reverb is not configured (missing NEXT_PUBLIC_REVERB_APP_KEY).
 * Use only in browser (e.g. inside useEffect).
 *
 * Pusher.js (used as the transport protocol for Reverb) and Laravel Echo are
 * lazy-imported to avoid module-level side effects.
 */
export async function getEcho(): Promise<Echo<"pusher"> | null> {
  if (typeof window === "undefined") return null;

  const key = process.env.NEXT_PUBLIC_REVERB_APP_KEY;
  if (!key || key === "") return null;

  if (echoInstance) return echoInstance;

  const [{ default: Echo }, { default: Pusher }] = await Promise.all([
    import("laravel-echo"),
    import("pusher-js"),
  ]);

  // Re-check after async imports in case another call already created the instance
  if (echoInstance) return echoInstance;

  (window as { Pusher?: typeof Pusher }).Pusher = Pusher;

  const wsHost = process.env.NEXT_PUBLIC_REVERB_HOST || window.location.hostname;
  const scheme = process.env.NEXT_PUBLIC_REVERB_SCHEME || (window.location.protocol === "https:" ? "https" : "http");
  const wsPort = process.env.NEXT_PUBLIC_REVERB_PORT || window.location.port || (scheme === "https" ? "443" : "80");

  echoInstance = new Echo({
    broadcaster: "pusher",
    key,
    cluster: "mt1", // Not used with Reverb, but Pusher.js requires a non-empty value
    wsHost,
    wsPort: parseInt(wsPort),
    wssPort: parseInt(wsPort),
    forceTLS: scheme === "https",
    enabledTransports: ["ws", "wss"],
    authEndpoint: getAuthEndpoint(),
    authorizer: (channel: { name: string }) => ({
      authorize: (
        socketId: string,
        callback: (error: Error | null, authData: { auth: string; channel_data?: string; shared_secret?: string } | null) => void
      ) => {
        fetch(getAuthEndpoint(), {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
          body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
          credentials: "include",
        })
          .then((r) => {
            if (!r.ok) throw new Error("Auth failed");
            return r.json();
          })
          .then((data) => callback(null, data as { auth: string }))
          .catch((err) =>
            callback(err instanceof Error ? err : new Error("Auth failed"), null)
          );
      },
    }),
  });

  return echoInstance;
}

/**
 * Disconnect and clear the Echo instance (e.g. on logout).
 */
export function disconnectEcho(): void {
  if (echoInstance) {
    try {
      echoInstance.disconnect();
    } catch {
      // ignore
    }
    echoInstance = null;
  }
}
