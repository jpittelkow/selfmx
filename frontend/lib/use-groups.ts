"use client";

import { useState, useEffect, useCallback } from "react";
import { api } from "@/lib/api";
import { errorLogger } from "@/lib/error-logger";

export interface Group {
  id: number;
  name: string;
  slug: string;
  description?: string | null;
  is_system?: boolean;
  is_default?: boolean;
  members_count?: number;
  created_at?: string;
  updated_at?: string;
}

interface GroupsResponse {
  data?: Group[];
}

/**
 * Fetch all groups (admin). Use for group pickers, filters, and dropdowns.
 * Single source for GET /groups response shape.
 */
export function useGroups(): {
  groups: Group[];
  isLoading: boolean;
  error: Error | null;
  refetch: () => Promise<void>;
} {
  const [groups, setGroups] = useState<Group[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);

  const fetchGroups = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await api.get<GroupsResponse>("/groups");
      const list = response.data?.data ?? response.data;
      setGroups(Array.isArray(list) ? list : []);
    } catch (err) {
      const e = err instanceof Error ? err : new Error(String(err));
      setError(e);
      errorLogger.report(e, { context: "useGroups.fetchGroups" });
      setGroups([]);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchGroups();
  }, [fetchGroups]);

  return { groups, isLoading, error, refetch: fetchGroups };
}
