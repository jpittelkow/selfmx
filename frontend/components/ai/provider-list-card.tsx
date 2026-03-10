"use client";

import type { AIProvider } from "./ai-types";
import { ProviderCard } from "./provider-card";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Brain, Plus } from "lucide-react";

interface ProviderListCardProps {
  providers: AIProvider[];
  testingProviders: Set<number>;
  onAddClick: () => void;
  onEdit: (provider: AIProvider) => void;
  onTest: (providerId: number, providerName: string) => void;
  onSetPrimary: (providerId: number) => void;
  onToggle: (providerId: number, enabled: boolean) => void;
  onDelete: (providerId: number) => void;
}

export function ProviderListCard({
  providers,
  testingProviders,
  onAddClick,
  onEdit,
  onTest,
  onSetPrimary,
  onToggle,
  onDelete,
}: ProviderListCardProps) {
  return (
    <Card>
      <CardHeader>
        <div className="flex items-center justify-between">
          <div>
            <CardTitle className="flex items-center gap-2">
              <Brain className="h-5 w-5" />
              AI Providers
            </CardTitle>
            <CardDescription>
              Manage your configured AI providers.
            </CardDescription>
          </div>
          <Button onClick={onAddClick}>
            <Plus className="mr-2 h-4 w-4" />
            Add Provider
          </Button>
        </div>
      </CardHeader>
      <CardContent>
        {providers.length === 0 ? (
          <div className="text-center py-8">
            <Brain className="mx-auto h-12 w-12 text-muted-foreground/50" />
            <h3 className="mt-4 text-lg font-medium">No providers configured</h3>
            <p className="text-sm text-muted-foreground mt-1">
              Add an AI provider to get started with LLM functionality.
            </p>
          </div>
        ) : (
          <div className="space-y-4">
            {providers.map((provider) => (
              <ProviderCard
                key={provider.id}
                provider={provider}
                isTesting={testingProviders.has(provider.id)}
                onEdit={onEdit}
                onTest={onTest}
                onSetPrimary={onSetPrimary}
                onToggle={onToggle}
                onDelete={onDelete}
              />
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
