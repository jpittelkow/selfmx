/**
 * Offline request queue: stores failed POST/PUT/DELETE requests in IndexedDB
 * and retries them when the app is back online.
 */

const DB_NAME = "selfmx-request-queue";
const DB_VERSION = 1;
const STORE_NAME = "requests";

export type QueuedRequest = {
  id: string;
  method: "POST" | "PUT" | "PATCH" | "DELETE";
  url: string;
  body: string | null;
  createdAt: number;
};

function openDB(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    if (typeof indexedDB === "undefined") {
      reject(new Error("IndexedDB is not available"));
      return;
    }
    const request = indexedDB.open(DB_NAME, DB_VERSION);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve(request.result);
    request.onupgradeneeded = (event) => {
      const db = (event.target as IDBOpenDBRequest).result;
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME, { keyPath: "id" });
      }
    };
  });
}

function generateId(): string {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 11)}`;
}

/**
 * Add a failed request to the queue for retry when online.
 */
export function addToRequestQueue(
  method: "POST" | "PUT" | "PATCH" | "DELETE",
  url: string,
  body?: unknown
): Promise<void> {
  return openDB().then((db) => {
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, "readwrite");
      const store = tx.objectStore(STORE_NAME);
      const record: QueuedRequest = {
        id: generateId(),
        method,
        url,
        body: body != null ? JSON.stringify(body) : null,
        createdAt: Date.now(),
      };
      const request = store.add(record);
      request.onsuccess = () => {
        db.close();
        resolve();
      };
      request.onerror = () => {
        db.close();
        reject(request.error);
      };
    });
  });
}

/**
 * Get all pending requests from the queue.
 */
export function getRequestQueue(): Promise<QueuedRequest[]> {
  return openDB().then((db) => {
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, "readonly");
      const store = tx.objectStore(STORE_NAME);
      const request = store.getAll();
      request.onsuccess = () => {
        db.close();
        resolve(request.result ?? []);
      };
      request.onerror = () => {
        db.close();
        reject(request.error);
      };
    });
  });
}

/**
 * Remove a request from the queue after successful retry.
 */
function removeFromRequestQueue(id: string): Promise<void> {
  return openDB().then((db) => {
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, "readwrite");
      const store = tx.objectStore(STORE_NAME);
      const request = store.delete(id);
      request.onsuccess = () => {
        db.close();
        resolve();
      };
      request.onerror = () => {
        db.close();
        reject(request.error);
      };
    });
  });
}

export type ApiClient = {
  get: (url: string, config?: { params?: Record<string, unknown> }) => Promise<unknown>;
  post: (url: string, data?: unknown) => Promise<unknown>;
  put: (url: string, data?: unknown) => Promise<unknown>;
  patch: (url: string, data?: unknown) => Promise<unknown>;
  delete: (url: string) => Promise<unknown>;
  request: (config: { method: string; url: string; data?: unknown; baseURL?: string }) => Promise<unknown>;
};

const MAX_QUEUE_AGE_MS = 24 * 60 * 60 * 1000; // 24 hours

/**
 * Process the queue: retry each request with the given API client.
 * Uses baseURL: '' so the stored full path (e.g. /api/user/settings) is used as-is.
 * Removes each request on success; discards stale (>24h) and non-retryable (4xx) items.
 */
export function processRequestQueue(api: ApiClient): Promise<{ processed: number; failed: number }> {
  return getRequestQueue().then(async (items) => {
    let processed = 0;
    let failed = 0;
    const now = Date.now();
    for (const item of items) {
      // Discard stale items (expired sessions, CSRF tokens, etc.)
      if (item.createdAt && now - item.createdAt > MAX_QUEUE_AGE_MS) {
        await removeFromRequestQueue(item.id);
        continue;
      }
      try {
        const body = item.body ? JSON.parse(item.body) : undefined;
        await api.request({
          method: item.method,
          url: item.url,
          data: body,
          baseURL: "",
        });
        await removeFromRequestQueue(item.id);
        processed++;
      } catch (error: unknown) {
        // Remove non-retryable client errors (auth expired, validation failed)
        const status = (error as { response?: { status?: number } })?.response?.status;
        if (status && status >= 400 && status < 500) {
          await removeFromRequestQueue(item.id);
        }
        failed++;
      }
    }
    return { processed, failed };
  });
}

const SYNC_TAG = "retry-failed-requests";

/**
 * Register Background Sync so the service worker retries the queue when back online.
 * Only works in Chrome/Edge; other browsers rely on the page's online listener.
 */
export function registerBackgroundSync(): Promise<void> {
  if (typeof navigator === "undefined" || !navigator.serviceWorker?.ready) {
    return Promise.resolve();
  }
  return navigator.serviceWorker.ready.then((registration) => {
    if ("sync" in registration) {
      return (registration as ServiceWorkerRegistration & { sync: { register: (tag: string) => Promise<void> } })
        .sync.register(SYNC_TAG)
        .catch(() => {});
    }
  });
}

/**
 * Set up online listener to process the request queue when back online.
 * Call once when the app mounts (e.g. from ServiceWorkerSetup).
 */
export function setupOfflineRetry(api: ApiClient): () => void {
  if (typeof window === "undefined") return () => {};
  const handler = () => {
    processRequestQueue(api).catch(() => {});
  };
  window.addEventListener("online", handler);
  return () => window.removeEventListener("online", handler);
}
