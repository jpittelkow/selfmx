import { api } from "@/lib/api";
import type { Email } from "@/lib/mail-types";

export interface EmailSearchResponse {
  data: Email[];
  meta?: {
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
  };
}

export async function searchEmails(
  query: string,
  page = 1,
  perPage = 25
): Promise<EmailSearchResponse> {
  const params = new URLSearchParams();
  params.set("q", query);
  params.set("page", String(page));
  params.set("per_page", String(perPage));

  const res = await api.get<EmailSearchResponse>(
    `/email/messages/search?${params.toString()}`
  );
  return res.data;
}
