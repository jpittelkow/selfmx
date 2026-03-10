export interface AIProvider {
  id: number;
  provider: string;
  model: string;
  api_key_set: boolean;
  is_enabled: boolean;
  is_primary: boolean;
  base_url?: string | null;
  endpoint?: string | null;
  region?: string | null;
  access_key_set?: boolean;
  secret_key_set?: boolean;
}

export interface DiscoveredModel {
  id: string;
  name: string;
  provider: string;
  capabilities?: string[];
}

export interface ProviderTemplate {
  id: string;
  name: string;
  requires_api_key: boolean;
  supports_vision: boolean;
  supports_discovery: boolean;
  requires_endpoint?: boolean;
  requires_aws_credentials?: boolean;
}

export type LLMMode = "single" | "aggregation" | "council";

export const providerTemplates: ProviderTemplate[] = [
  {
    id: "claude",
    name: "Claude (Anthropic)",
    requires_api_key: true,
    supports_vision: true,
    supports_discovery: true,
  },
  {
    id: "openai",
    name: "OpenAI",
    requires_api_key: true,
    supports_vision: true,
    supports_discovery: true,
  },
  {
    id: "gemini",
    name: "Gemini (Google)",
    requires_api_key: true,
    supports_vision: true,
    supports_discovery: true,
  },
  {
    id: "ollama",
    name: "Ollama (Local)",
    requires_api_key: false,
    supports_vision: false,
    supports_discovery: true,
  },
  {
    id: "azure",
    name: "Azure OpenAI",
    requires_api_key: true,
    requires_endpoint: true,
    supports_vision: true,
    supports_discovery: true,
  },
  {
    id: "bedrock",
    name: "AWS Bedrock",
    requires_api_key: false,
    requires_aws_credentials: true,
    supports_vision: true,
    supports_discovery: true,
  },
];
