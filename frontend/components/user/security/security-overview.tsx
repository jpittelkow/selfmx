"use client";

import { useState, useEffect } from "react";
import { api } from "@/lib/api";
import { useAppConfig } from "@/lib/app-config";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Shield,
  Fingerprint,
  Key,
  Link as LinkIcon,
  CheckCircle2,
  XCircle,
} from "lucide-react";
import { cn } from "@/lib/utils";

interface SecurityStatus {
  twoFactor: boolean | null;
  passkeyCount: number | null;
  ssoConnections: number | null;
  apiKeyCount: number | null;
}

export function SecurityOverview() {
  const { features } = useAppConfig();
  const [status, setStatus] = useState<SecurityStatus>({
    twoFactor: null,
    passkeyCount: null,
    ssoConnections: null,
    apiKeyCount: null,
  });
  const [loading, setLoading] = useState(true);

  const passkeyMode = features?.passkeyMode ?? "disabled";
  const passkeysEnabled = passkeyMode !== "disabled";
  const graphqlEnabled = features?.graphqlEnabled ?? false;

  useEffect(() => {
    const fetchAll = async () => {
      const results: Partial<SecurityStatus> = {};

      const calls = [
        api
          .get("/auth/2fa/status")
          .then((res) => {
            results.twoFactor = res.data?.enabled ?? false;
          })
          .catch(() => {
            results.twoFactor = false;
          }),
        api
          .get("/auth/sso/providers")
          .then((res) => {
            const providers = res.data?.providers || [];
            results.ssoConnections = providers.filter(
              (p: { connected: boolean }) => p.connected
            ).length;
          })
          .catch(() => {
            results.ssoConnections = 0;
          }),
      ];

      if (passkeysEnabled) {
        calls.push(
          api
            .get("/auth/passkeys")
            .then((res) => {
              results.passkeyCount = (res.data?.passkeys || []).length;
            })
            .catch(() => {
              results.passkeyCount = 0;
            })
        );
      }

      if (graphqlEnabled) {
        calls.push(
          api
            .get("/user/api-keys")
            .then((res) => {
              results.apiKeyCount = (res.data?.keys || []).filter(
                (k: { revoked_at: string | null }) => !k.revoked_at
              ).length;
            })
            .catch(() => {
              results.apiKeyCount = 0;
            })
        );
      }

      await Promise.allSettled(calls);
      setStatus((prev) => ({ ...prev, ...results }));
      setLoading(false);
    };

    fetchAll();
  }, [passkeysEnabled, graphqlEnabled]);

  const items = [
    {
      label: "Two-Factor Auth",
      icon: Shield,
      value: status.twoFactor === null ? null : status.twoFactor ? "Enabled" : "Disabled",
      ok: status.twoFactor === true,
    },
    ...(passkeysEnabled
      ? [
          {
            label: "Passkeys",
            icon: Fingerprint,
            value:
              status.passkeyCount === null
                ? null
                : `${status.passkeyCount} registered`,
            ok: (status.passkeyCount ?? 0) > 0,
          },
        ]
      : []),
    {
      label: "SSO Connections",
      icon: LinkIcon,
      value:
        status.ssoConnections === null
          ? null
          : `${status.ssoConnections} linked`,
      ok: (status.ssoConnections ?? 0) > 0,
    },
    ...(graphqlEnabled
      ? [
          {
            label: "API Keys",
            icon: Key,
            value:
              status.apiKeyCount === null
                ? null
                : `${status.apiKeyCount} active`,
            ok: null as boolean | null,
          },
        ]
      : []),
  ];

  return (
    <Card>
      <CardContent className="p-6">
        <div
          className={cn(
            "grid gap-4",
            items.length <= 3
              ? "grid-cols-1 sm:grid-cols-3"
              : "grid-cols-2 md:grid-cols-4"
          )}
        >
          {items.map((item) => (
            <div key={item.label} className="flex items-center gap-3">
              <div
                className={cn(
                  "flex h-10 w-10 shrink-0 items-center justify-center rounded-full",
                  item.ok === true
                    ? "bg-green-500/10 text-green-600 dark:text-green-400"
                    : "bg-muted text-muted-foreground"
                )}
              >
                <item.icon className="h-5 w-5" />
              </div>
              <div className="min-w-0">
                <p className="text-xs text-muted-foreground">{item.label}</p>
                {loading || item.value === null ? (
                  <Skeleton className="h-4 w-16 mt-1" />
                ) : (
                  <div className="flex items-center gap-1.5">
                    {item.ok !== null &&
                      (item.ok ? (
                        <CheckCircle2 className="h-3.5 w-3.5 text-green-600 dark:text-green-400 shrink-0" />
                      ) : (
                        <XCircle className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                      ))}
                    <p className="text-sm font-medium truncate">{item.value}</p>
                  </div>
                )}
              </div>
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
