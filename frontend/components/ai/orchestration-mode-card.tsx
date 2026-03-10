"use client";

import type { LLMMode } from "./ai-types";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Info, Settings2 } from "lucide-react";

interface OrchestrationModeCardProps {
  mode: LLMMode;
  onModeChange: (mode: LLMMode) => void;
}

export function OrchestrationModeCard({
  mode,
  onModeChange,
}: OrchestrationModeCardProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Settings2 className="h-5 w-5" />
          Orchestration Mode
        </CardTitle>
        <CardDescription>
          Choose how AI providers work together to generate responses.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Tabs value={mode} onValueChange={(v) => onModeChange(v as LLMMode)}>
          <TabsList className="grid w-full grid-cols-3">
            <TabsTrigger value="single">Single</TabsTrigger>
            <TabsTrigger value="aggregation">Aggregation</TabsTrigger>
            <TabsTrigger value="council">Council</TabsTrigger>
          </TabsList>
          <TabsContent value="single" className="mt-4">
            <Alert>
              <Info className="h-4 w-4" />
              <AlertTitle>Single Mode</AlertTitle>
              <AlertDescription className="space-y-2">
                <p>
                  Queries are sent to your primary provider only. This is the fastest
                  and most cost-effective option.
                </p>
                <p className="text-xs text-muted-foreground">
                  <strong>Best for:</strong> Standard tasks, cost-conscious usage, when you trust
                  one provider for your needs. Uses 1 API call per request.
                </p>
              </AlertDescription>
            </Alert>
          </TabsContent>
          <TabsContent value="aggregation" className="mt-4">
            <Alert>
              <Info className="h-4 w-4" />
              <AlertTitle>Aggregation Mode</AlertTitle>
              <AlertDescription className="space-y-2">
                <p>
                  All enabled providers are queried in parallel, then the primary provider
                  reads all responses and synthesizes them into one comprehensive answer.
                </p>
                <p className="text-xs text-muted-foreground">
                  <strong>Best for:</strong> Complex questions where different AI models may offer
                  unique insights. The primary provider decides what to include. Uses N+1 API calls.
                </p>
              </AlertDescription>
            </Alert>
          </TabsContent>
          <TabsContent value="council" className="mt-4">
            <Alert>
              <Info className="h-4 w-4" />
              <AlertTitle>Council Mode</AlertTitle>
              <AlertDescription className="space-y-2">
                <p>
                  All providers respond independently, then a consensus algorithm compares their
                  answers. The final response includes only points where providers agree (70%+
                  threshold), plus a confidence score and any dissenting views.
                </p>
                <p className="text-xs text-muted-foreground">
                  <strong>Best for:</strong> Critical decisions, fact verification, reducing AI
                  hallucinations. No single provider has final say&mdash;it&apos;s democratic. Requires
                  at least 2 enabled providers.
                </p>
              </AlertDescription>
            </Alert>
          </TabsContent>
        </Tabs>
      </CardContent>
    </Card>
  );
}
