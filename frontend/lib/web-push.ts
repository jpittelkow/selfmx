/**
 * Web Push notification utilities.
 * Manages browser push subscription for PWA notifications.
 */

import { registerServiceWorker } from './service-worker';

export type PushSubscriptionPayload = {
  endpoint: string;
  keys: {
    p256dh: string;
    auth: string;
  };
};

/**
 * Check if the browser supports Web Push (service worker + PushManager).
 */
export function isWebPushSupported(): boolean {
  return (
    typeof navigator !== 'undefined' &&
    'serviceWorker' in navigator &&
    'PushManager' in window &&
    'Notification' in window
  );
}

/**
 * Get the current notification permission status.
 */
export function getPermissionStatus(): NotificationPermission | 'unsupported' {
  if (!isWebPushSupported()) {
    return 'unsupported';
  }
  return Notification.permission;
}

/**
 * Request notification permission from the user.
 * @returns The permission status after the user responds
 */
export async function requestPermission(): Promise<NotificationPermission> {
  if (!isWebPushSupported()) {
    throw new Error('Web Push is not supported in this browser');
  }
  const result = await Notification.requestPermission();
  return result;
}

/**
 * Get the current push subscription if one exists.
 */
export async function getSubscription(): Promise<PushSubscriptionPayload | null> {
  if (!isWebPushSupported()) {
    return null;
  }
  try {
    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
      return null;
    }
    return subscriptionToPayload(subscription);
  } catch {
    return null;
  }
}

/**
 * Subscribe to push notifications using the VAPID public key.
 * Registers the service worker if needed, requests permission, and subscribes.
 * @param vapidPublicKey - Base64-encoded VAPID public key from the server
 * @returns The subscription payload to send to the backend, or null if failed
 */
export async function subscribe(vapidPublicKey: string): Promise<PushSubscriptionPayload | null> {
  if (!isWebPushSupported()) {
    throw new Error('Web Push is not supported in this browser');
  }
  if (!vapidPublicKey) {
    throw new Error('VAPID public key is required');
  }

  // Ensure service worker is registered
  const swResult = await registerServiceWorker();
  const registration = swResult?.registration ?? (await navigator.serviceWorker.ready);

  // Request permission if not already granted
  if (Notification.permission === 'default') {
    const permission = await requestPermission();
    if (permission !== 'granted') {
      return null;
    }
  }
  if (Notification.permission !== 'granted') {
    return null;
  }

  const key = urlBase64ToUint8Array(vapidPublicKey);
  const subscription = await registration.pushManager.subscribe({
    userVisibleOnly: true,
    // Type assertion needed due to Node.js/browser ArrayBuffer type mismatch during build
    applicationServerKey: key as unknown as ArrayBuffer,
  });
  return subscriptionToPayload(subscription);
}

/**
 * Unsubscribe from push notifications.
 * Returns the endpoint of the removed subscription so the caller can
 * tell the backend which device to remove.
 */
export async function unsubscribe(): Promise<string | null> {
  if (!isWebPushSupported()) {
    return null;
  }
  try {
    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription();
    if (subscription) {
      const endpoint = subscription.endpoint;
      await subscription.unsubscribe();
      return endpoint;
    }
    return null;
  } catch {
    return null;
  }
}

function subscriptionToPayload(subscription: PushSubscription): PushSubscriptionPayload {
  const json = subscription.toJSON();
  const keys = json.keys;
  if (!keys?.p256dh || !keys?.auth) {
    throw new Error('Invalid subscription: missing keys');
  }
  return {
    endpoint: json.endpoint ?? subscription.endpoint,
    keys: {
      p256dh: keys.p256dh,
      auth: keys.auth,
    },
  };
}

function urlBase64ToUint8Array(base64String: string): Uint8Array {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}
