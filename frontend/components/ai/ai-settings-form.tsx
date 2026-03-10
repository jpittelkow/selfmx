"use client";

import { useState, useEffect, useCallback } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { Input } from "@/components/ui/input";
import { FormField } from "@/components/ui/form-field";
import { SettingsSwitchRow } from "@/components/ui/settings-switch-row";
import { SaveButton } from "@/components/ui/save-button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Cpu } from "lucide-react";
import type { LLMMode } from "@/components/ai/ai-types";
type CouncilStrategy = "majority" | "weighted" | "synthesize";

const SYSTEM_DEFAULTS_INIT = {
  timeout: 120,
  logging_enabled: true,
  council_min_providers: 2,
  council_strategy: "synthesize" as CouncilStrategy,
  aggregation_parallel: true,
  aggregation_include_sources: true,
};

interface AISettingsFormProps {
  mode: LLMMode;
}

export function AISettingsForm({ mode }: AISettingsFormProps) {
  const [systemDefaults, setSystemDefaults] = useState(SYSTEM_DEFAULTS_INIT);
  const [systemDefaultsDirty, setSystemDefaultsDirty] = useState(false);
  const [systemDefaultsSaving, setSystemDefaultsSaving] = useState(false);
  const [loaded, setLoaded] = useState(false);

  const fetchSystemDefaults = useCallback(async () => {
    try {
      const response = await api.get("/llm-settings");
      const s = response.data?.settings ?? {};
      setSystemDefaults({
        timeout: s.timeout != null ? Number(s.timeout) : SYSTEM_DEFAULTS_INIT.timeout,
        logging_enabled: s.logging_enabled ?? SYSTEM_DEFAULTS_INIT.logging_enabled,
        council_min_providers: s.council_min_providers != null ? Number(s.council_min_providers) : SYSTEM_DEFAULTS_INIT.council_min_providers,
        council_strategy: (s.council_strategy as CouncilStrategy) ?? SYSTEM_DEFAULTS_INIT.council_strategy,
        aggregation_parallel: s.aggregation_parallel ?? SYSTEM_DEFAULTS_INIT.aggregation_parallel,
        aggregation_include_sources: s.aggregation_include_sources ?? SYSTEM_DEFAULTS_INIT.aggregation_include_sources,
      });
      setSystemDefaultsDirty(false);
    } catch {
      toast.error("Failed to load system defaults");
    } finally {
      setLoaded(true);
    }
  }, []);

  useEffect(() => {
    if (!loaded) {
      fetchSystemDefaults();
    }
  }, [loaded, fetchSystemDefaults]);

  const saveSystemDefaults = async () => {
    if (!systemDefaultsDirty) return;
    setSystemDefaultsSaving(true);
    try {
      await api.put("/llm-settings", {
        timeout: systemDefaults.timeout,
        logging_enabled: systemDefaults.logging_enabled,
        council_min_providers: systemDefaults.council_min_providers,
        council_strategy: systemDefaults.council_strategy,
        aggregation_parallel: systemDefaults.aggregation_parallel,
        aggregation_include_sources: systemDefaults.aggregation_include_sources,
      });
      toast.success("System defaults saved");
      setSystemDefaultsDirty(false);
      await fetchSystemDefaults();
    } catch (err: unknown) {
      const msg = err && typeof err === "object" && "response" in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : null;
      toast.error(msg ?? "Failed to save system defaults");
    } finally {
      setSystemDefaultsSaving(false);
    }
  };

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        saveSystemDefaults();
      }}
      className="space-y-6"
    >
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Cpu className="h-5 w-5" />
            System Defaults
          </CardTitle>
          <CardDescription>
            System-wide LLM defaults: timeout, logging, and mode-specific options.
            Users inherit these when not overridden.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          <FormField id="sys-timeout" label="Request timeout (seconds)">
            <Input
              id="sys-timeout"
              type="number"
              min={10}
              max={600}
              value={systemDefaults.timeout}
              onChange={(e) => {
                const v = parseInt(e.target.value, 10);
                if (!isNaN(v)) {
                  setSystemDefaults((prev) => ({ ...prev, timeout: Math.max(10, Math.min(600, v)) }));
                  setSystemDefaultsDirty(true);
                }
              }}
              className="min-h-[44px] max-w-xs"
            />
          </FormField>
          <SettingsSwitchRow
            label="Log requests"
            description="Log LLM requests for debugging and cost analysis"
            checked={systemDefaults.logging_enabled}
            onCheckedChange={(checked) => {
              setSystemDefaults((prev) => ({ ...prev, logging_enabled: checked }));
              setSystemDefaultsDirty(true);
            }}
          />

          {mode === "council" && (
            <div className="space-y-4 pt-2 border-t">
              <h4 className="text-sm font-medium">Council mode</h4>
              <div className="grid gap-4 md:grid-cols-2">
                <FormField id="sys-council-min" label="Minimum providers">
                  <Input
                    id="sys-council-min"
                    type="number"
                    min={2}
                    max={6}
                    value={systemDefaults.council_min_providers}
                    onChange={(e) => {
                      const v = parseInt(e.target.value, 10);
                      if (!isNaN(v)) {
                        setSystemDefaults((prev) => ({ ...prev, council_min_providers: Math.max(2, Math.min(6, v)) }));
                        setSystemDefaultsDirty(true);
                      }
                    }}
                    className="min-h-[44px]"
                  />
                </FormField>
                <FormField id="sys-council-strategy" label="Resolution strategy">
                  <Select
                    value={systemDefaults.council_strategy}
                    onValueChange={(v: CouncilStrategy) => {
                      setSystemDefaults((prev) => ({ ...prev, council_strategy: v }));
                      setSystemDefaultsDirty(true);
                    }}
                  >
                    <SelectTrigger className="min-h-[44px]">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="majority">Majority</SelectItem>
                      <SelectItem value="weighted">Weighted</SelectItem>
                      <SelectItem value="synthesize">Synthesize</SelectItem>
                    </SelectContent>
                  </Select>
                </FormField>
              </div>
            </div>
          )}

          {mode === "aggregation" && (
            <div className="space-y-4 pt-2 border-t">
              <h4 className="text-sm font-medium">Aggregation mode</h4>
              <div className="space-y-4">
                <SettingsSwitchRow
                  label="Parallel execution"
                  description="Run provider queries in parallel"
                  checked={systemDefaults.aggregation_parallel}
                  onCheckedChange={(checked) => {
                    setSystemDefaults((prev) => ({ ...prev, aggregation_parallel: checked }));
                    setSystemDefaultsDirty(true);
                  }}
                />
                <SettingsSwitchRow
                  label="Include sources"
                  description="Include individual provider responses"
                  checked={systemDefaults.aggregation_include_sources}
                  onCheckedChange={(checked) => {
                    setSystemDefaults((prev) => ({ ...prev, aggregation_include_sources: checked }));
                    setSystemDefaultsDirty(true);
                  }}
                />
              </div>
            </div>
          )}
        </CardContent>
        <CardFooter className="flex flex-col gap-4 sm:flex-row sm:justify-between">
          <p className="text-sm text-muted-foreground">
            Changes take effect immediately. Empty values fall back to environment variables.
          </p>
          <SaveButton
            isDirty={systemDefaultsDirty}
            isSaving={systemDefaultsSaving}
          />
        </CardFooter>
      </Card>
    </form>
  );
}
