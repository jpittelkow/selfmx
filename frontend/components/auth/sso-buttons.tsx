"use client";

import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { ProviderIcon } from "@/components/provider-icons";
import { api } from "@/lib/api";
import { errorLogger } from "@/lib/error-logger";

interface SSOProvider {
  id: string;
  name: string;
  icon: string;
  color: string;
}

interface SSOButtonsProps {
  onLoad?: (hasProviders: boolean) => void;
}

export function SSOButtons({ onLoad }: SSOButtonsProps) {
  const [providers, setProviders] = useState<SSOProvider[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [redirectingProviderId, setRedirectingProviderId] = useState<string | null>(null);

  useEffect(() => {
    const fetchProviders = async () => {
      try {
        const response = await api.get("/auth/sso/providers");
        setProviders(response.data.providers);
        onLoad?.(response.data.providers.length > 0);
      } catch (error) {
        errorLogger.report(
          error instanceof Error ? error : new Error("Failed to fetch SSO providers"),
          { source: "sso-buttons" }
        );
        onLoad?.(false);
      } finally {
        setIsLoading(false);
      }
    };

    fetchProviders();
  }, []);

  // Don't show placeholders while loading—form is visible; SSO buttons appear when ready.
  if (isLoading || providers.length === 0) {
    return null;
  }

  const handleSSO = (providerId: string) => {
    setRedirectingProviderId(providerId);
    const apiUrl = process.env.NEXT_PUBLIC_API_URL || "";
    window.location.href = `${apiUrl}/api/auth/sso/${providerId}`;
  };

  return (
    <div className="space-y-2">
      {providers.map((provider) => {
        const isRedirecting = redirectingProviderId === provider.id;
        return (
          <Button
            key={provider.id}
            type="button"
            variant="outline"
            className="w-full min-h-12 transition-opacity"
            onClick={() => handleSSO(provider.id)}
            disabled={!!redirectingProviderId}
            aria-label={`Continue with ${provider.name}`}
          >
            {isRedirecting ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden />
            ) : (
              <ProviderIcon provider={provider.icon} size="sm" className="mr-2" style="branded" />
            )}
            <span>{isRedirecting ? "Redirecting..." : `Continue with ${provider.name}`}</span>
          </Button>
        );
      })}
    </div>
  );
}

