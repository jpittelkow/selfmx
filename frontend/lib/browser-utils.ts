/**
 * Detect if the current platform is macOS/iOS.
 * Uses modern navigator.userAgentData API with fallback to navigator.platform.
 */
export function isMacPlatform(): boolean {
  if (typeof navigator === "undefined") return false;

  // Modern API (Chrome 93+, Edge 93+)
  const uaData = (navigator as Navigator & { userAgentData?: { platform?: string } }).userAgentData;
  if (uaData?.platform) {
    return /mac/i.test(uaData.platform);
  }

  // Fallback to deprecated navigator.platform
  return /Mac|iPod|iPhone|iPad/.test(navigator.platform);
}

/**
 * Detect if the current device is an iOS device (iPhone, iPad, iPod).
 * Handles modern iPads that report as "MacIntel" by checking touch support.
 */
export function isIOSDevice(): boolean {
  if (typeof navigator === "undefined") return false;

  // Check userAgent for explicit iOS indicators
  if (/iPad|iPhone|iPod/.test(navigator.userAgent)) return true;

  // Modern iPads with M-series chips report as macOS — detect via touch
  const uaData = (navigator as Navigator & { userAgentData?: { platform?: string } }).userAgentData;
  if (uaData?.platform) {
    return /mac/i.test(uaData.platform) && navigator.maxTouchPoints > 1;
  }

  // Fallback: navigator.platform for older browsers
  return navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1;
}
