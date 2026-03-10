"use client";

import { useState, useEffect } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { errorLogger } from "@/lib/error-logger";
import { getErrorMessage } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { CollapsibleCard } from "@/components/ui/collapsible-card";
import {
  Link as LinkIcon,
  Unlink,
} from "lucide-react";

interface SSOProvider {
  id: string;
  name: string;
  icon: string;
  connected: boolean;
  nickname?: string;
}

export function SessionsSection() {
  const [ssoProviders, setSsoProviders] = useState<SSOProvider[]>([]);

  const fetchProviders = async () => {
    try {
      const res = await api.get("/auth/sso/providers");
      setSsoProviders(res.data.providers || []);
    } catch (error) {
      errorLogger.report(
        error instanceof Error ? error : new Error("Failed to fetch SSO providers"),
        { source: "sessions-section" }
      );
    }
  };

  useEffect(() => {
    fetchProviders();
  }, []);

  const handleLinkSSO = (provider: string) => {
    window.location.href = `/api/auth/sso/${provider}?link=true`;
  };

  const handleUnlinkSSO = async (provider: string) => {
    try {
      await api.delete(`/auth/sso/${provider}/unlink`);
      fetchProviders();
      toast.success(`${provider} account unlinked`);
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to unlink account"));
    }
  };

  return (
    <CollapsibleCard
      title="Connected Accounts"
      description="Link your account with external providers for easy sign-in."
      icon={<LinkIcon className="h-5 w-5" />}
      defaultOpen={false}
    >
      <div className="space-y-4">
        {ssoProviders.map((provider) => (
          <div
            key={provider.id}
            className="flex items-center justify-between py-2"
          >
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-full bg-muted flex items-center justify-center text-lg">
                {provider.icon || provider.name[0]}
              </div>
              <div>
                <p className="font-medium capitalize">{provider.name}</p>
                {provider.connected && provider.nickname && (
                  <p className="text-sm text-muted-foreground">
                    {provider.nickname}
                  </p>
                )}
              </div>
            </div>
            {provider.connected ? (
              <Button
                variant="outline"
                size="sm"
                onClick={() => handleUnlinkSSO(provider.id)}
              >
                <Unlink className="mr-2 h-4 w-4" />
                Disconnect
              </Button>
            ) : (
              <Button
                variant="outline"
                size="sm"
                onClick={() => handleLinkSSO(provider.id)}
              >
                <LinkIcon className="mr-2 h-4 w-4" />
                Connect
              </Button>
            )}
          </div>
        ))}
      </div>
    </CollapsibleCard>
  );
}
