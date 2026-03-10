"use client";

import { useState } from "react";
import { ChevronDown, ExternalLink } from "lucide-react";
import { cn } from "@/lib/utils";

interface SetupGuide {
  steps: string[];
  url: string;
  urlLabel: string;
  tips?: string[];
}

const SETUP_GUIDES: Record<string, SetupGuide> = {
  mailgun: {
    steps: [
      "Log in to your Mailgun dashboard",
      'Go to API Security (or Settings → API Keys)',
      "Copy your Private API Key",
      "Select your region: US (api.mailgun.net) or EU (api.eu.mailgun.net)",
      'For webhooks: go to Sending → Webhooks, copy the Webhook Signing Key',
    ],
    url: "https://app.mailgun.com/settings/api_security",
    urlLabel: "Mailgun API Security",
    tips: [
      "The webhook signing key is separate from your API key",
      "EU region accounts have a different API endpoint — make sure to select the correct region",
    ],
  },
  ses: {
    steps: [
      "Go to the AWS Console → IAM → Users",
      "Create a new user (or use an existing one)",
      "Attach the AmazonSESFullAccess and AmazonSNSFullAccess policies (SNS is needed for delivery event webhooks)",
      'Go to Security credentials → Create access key',
      "Copy the Access Key ID and Secret Access Key",
      "Select the AWS region where your domain is verified in SES",
      "Use Auto-configure Webhooks to set up delivery tracking — selfmx will create the SNS topic and SES configuration set automatically",
    ],
    url: "https://console.aws.amazon.com/ses/",
    urlLabel: "AWS SES Console",
    tips: [
      "New SES accounts start in sandbox mode — you must request production access to send to unverified addresses",
      "selfmx auto-creates an SNS topic and subscribes your events endpoint when you configure webhooks — no manual SNS setup needed",
    ],
  },
  postmark: {
    steps: [
      "Log in to your Postmark account",
      "Navigate to Servers → select your server",
      'Go to the API Tokens tab',
      "Copy the Server API Token",
    ],
    url: "https://account.postmarkapp.com/servers",
    urlLabel: "Postmark Servers",
    tips: [
      "Each Postmark server has its own API token",
      "Postmark separates transactional and broadcast streams — selfmx uses the default transactional stream",
    ],
  },
  resend: {
    steps: [
      "Log in to your Resend dashboard",
      'Go to API Keys',
      "Create a new API key with full access (or sending only)",
      "Copy the API key — it is only shown once",
      'For webhooks (optional): go to Webhooks and copy the signing secret',
    ],
    url: "https://resend.com/api-keys",
    urlLabel: "Resend API Keys",
    tips: [
      "Resend API keys start with re_",
      "The webhook signing secret is optional but recommended for delivery tracking",
    ],
  },
  mailersend: {
    steps: [
      "Log in to your MailerSend dashboard",
      'Go to API Tokens in the sidebar',
      "Generate a new token with full access",
      "Copy the API token — it is only shown once",
      'For webhooks (optional): go to Settings → Webhooks for the signing secret',
    ],
    url: "https://app.mailersend.com/api-tokens",
    urlLabel: "MailerSend API Tokens",
    tips: [
      "MailerSend tokens start with mlsn.",
      "MailerSend offers a generous 3,000 emails/month free tier",
    ],
  },
  smtp2go: {
    steps: [
      "Log in to your SMTP2GO dashboard",
      'Go to Settings → API Keys',
      "Create a new API key",
      "Copy the API key",
    ],
    url: "https://app.smtp2go.com/settings/apikeys",
    urlLabel: "SMTP2GO API Keys",
    tips: [
      "SMTP2GO API keys start with api-",
      "SMTP2GO also supports standard SMTP if you prefer that for other tools",
    ],
  },
};

interface ProviderSetupGuideProps {
  provider: string;
}

export function ProviderSetupGuide({ provider }: ProviderSetupGuideProps) {
  const [isOpen, setIsOpen] = useState(false);
  const guide = SETUP_GUIDES[provider];
  if (!guide) return null;

  return (
    <div className="rounded-md border bg-muted/30">
      <button
        type="button"
        className="flex w-full items-center justify-between px-3 py-2 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
        onClick={() => setIsOpen(!isOpen)}
      >
        <span>Where do I find these credentials?</span>
        <ChevronDown className={cn("h-4 w-4 transition-transform", isOpen && "rotate-180")} />
      </button>
      {isOpen && (
        <div className="px-3 pb-3 space-y-3">
          <ol className="list-decimal list-inside space-y-1.5 text-sm text-muted-foreground">
            {guide.steps.map((step, i) => (
              <li key={i}>{step}</li>
            ))}
          </ol>
          {guide.tips && guide.tips.length > 0 && (
            <div className="space-y-1">
              {guide.tips.map((tip, i) => (
                <p key={i} className="text-xs text-muted-foreground/80 pl-1">
                  <span className="font-medium">Tip:</span> {tip}
                </p>
              ))}
            </div>
          )}
          <a
            href={guide.url}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1 text-xs text-primary hover:underline"
          >
            {guide.urlLabel}
            <ExternalLink className="h-3 w-3" />
          </a>
        </div>
      )}
    </div>
  );
}
