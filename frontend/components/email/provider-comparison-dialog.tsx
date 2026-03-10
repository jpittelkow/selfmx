"use client";

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Badge } from "@/components/ui/badge";
import { Check, X, Minus } from "lucide-react";
import { cn } from "@/lib/utils";

interface ProviderComparisonDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

interface ProviderInfo {
  id: string;
  name: string;
  bestFor: string;
  freeTier: string;
  pricing: string;
  setupDifficulty: "Very Easy" | "Easy" | "Moderate" | "Complex";
  inbound: boolean;
  webhooks: boolean;
  eventTracking: boolean;
  suppressionLists: boolean;
  dkimApi: boolean | "partial";
  regions: string;
}

const providers: ProviderInfo[] = [
  {
    id: "mailgun",
    name: "Mailgun",
    bestFor: "Developers, high volume",
    freeTier: "100 emails/day",
    pricing: "From $0.80/1K emails",
    setupDifficulty: "Easy",
    inbound: true,
    webhooks: true,
    eventTracking: true,
    suppressionLists: true,
    dkimApi: true,
    regions: "US, EU",
  },
  {
    id: "ses",
    name: "AWS SES",
    bestFor: "Enterprise, AWS users",
    freeTier: "62K/mo from EC2",
    pricing: "$0.10/1K emails",
    setupDifficulty: "Complex",
    inbound: true,
    webhooks: true,
    eventTracking: true,
    suppressionLists: true,
    dkimApi: false,
    regions: "10+ AWS regions",
  },
  {
    id: "postmark",
    name: "Postmark",
    bestFor: "Transactional email",
    freeTier: "100 emails/mo",
    pricing: "From $15/mo (10K)",
    setupDifficulty: "Easy",
    inbound: true,
    webhooks: true,
    eventTracking: true,
    suppressionLists: true,
    dkimApi: false,
    regions: "US",
  },
  {
    id: "resend",
    name: "Resend",
    bestFor: "Modern API, developers",
    freeTier: "100 emails/day",
    pricing: "From $20/mo (50K)",
    setupDifficulty: "Very Easy",
    inbound: false,
    webhooks: true,
    eventTracking: true,
    suppressionLists: false,
    dkimApi: false,
    regions: "US",
  },
  {
    id: "mailersend",
    name: "MailerSend",
    bestFor: "Marketing + transactional",
    freeTier: "3K emails/mo",
    pricing: "From $28/mo (50K)",
    setupDifficulty: "Easy",
    inbound: true,
    webhooks: true,
    eventTracking: true,
    suppressionLists: true,
    dkimApi: false,
    regions: "EU",
  },
  {
    id: "smtp2go",
    name: "SMTP2GO",
    bestFor: "Simple SMTP relay",
    freeTier: "1K emails/mo",
    pricing: "From $10/mo (10K)",
    setupDifficulty: "Easy",
    inbound: true,
    webhooks: true,
    eventTracking: true,
    suppressionLists: false,
    dkimApi: false,
    regions: "US, EU, AU",
  },
];

const difficultyColor: Record<string, string> = {
  "Very Easy": "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400",
  Easy: "bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400",
  Moderate: "bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400",
  Complex: "bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400",
};

function FeatureIcon({ value }: { value: boolean | "partial" }) {
  if (value === true) return <Check className="h-4 w-4 text-green-600 dark:text-green-400" />;
  if (value === "partial") return <Minus className="h-4 w-4 text-yellow-600 dark:text-yellow-400" />;
  return <X className="h-4 w-4 text-muted-foreground/40" />;
}

export function ProviderComparisonDialog({ open, onOpenChange }: ProviderComparisonDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-4xl max-h-[85vh] overflow-hidden flex flex-col">
        <DialogHeader>
          <DialogTitle>Compare Email Providers</DialogTitle>
          <DialogDescription>
            Choose the provider that best fits your needs. You can add multiple provider accounts.
          </DialogDescription>
        </DialogHeader>
        <div className="overflow-auto -mx-6 px-6">
          <table className="w-full text-sm border-collapse min-w-[640px]">
            <thead>
              <tr className="border-b">
                <th className="text-left py-2 pr-3 font-medium text-muted-foreground sticky left-0 bg-background min-w-[100px]">
                  &nbsp;
                </th>
                {providers.map((p) => (
                  <th key={p.id} className="text-center py-2 px-2 font-semibold min-w-[110px]">
                    {p.name}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              <tr className="border-b">
                <td className="py-2.5 pr-3 font-medium text-muted-foreground sticky left-0 bg-background">Best for</td>
                {providers.map((p) => (
                  <td key={p.id} className="py-2.5 px-2 text-center text-xs">{p.bestFor}</td>
                ))}
              </tr>
              <tr className="border-b">
                <td className="py-2.5 pr-3 font-medium text-muted-foreground sticky left-0 bg-background">Free tier</td>
                {providers.map((p) => (
                  <td key={p.id} className="py-2.5 px-2 text-center text-xs">{p.freeTier}</td>
                ))}
              </tr>
              <tr className="border-b">
                <td className="py-2.5 pr-3 font-medium text-muted-foreground sticky left-0 bg-background">Pricing</td>
                {providers.map((p) => (
                  <td key={p.id} className="py-2.5 px-2 text-center text-xs">{p.pricing}</td>
                ))}
              </tr>
              <tr className="border-b">
                <td className="py-2.5 pr-3 font-medium text-muted-foreground sticky left-0 bg-background">Setup</td>
                {providers.map((p) => (
                  <td key={p.id} className="py-2.5 px-2 text-center">
                    <Badge variant="secondary" className={cn("text-xs font-normal", difficultyColor[p.setupDifficulty])}>
                      {p.setupDifficulty}
                    </Badge>
                  </td>
                ))}
              </tr>
              <tr className="border-b">
                <td className="py-2.5 pr-3 font-medium text-muted-foreground sticky left-0 bg-background">Regions</td>
                {providers.map((p) => (
                  <td key={p.id} className="py-2.5 px-2 text-center text-xs">{p.regions}</td>
                ))}
              </tr>
              <tr className="border-b">
                <td className="py-2.5 pr-3 font-medium text-muted-foreground sticky left-0 bg-background">Inbound email</td>
                {providers.map((p) => (
                  <td key={p.id} className="py-2.5 px-2">
                    <div className="flex justify-center"><FeatureIcon value={p.inbound} /></div>
                  </td>
                ))}
              </tr>
              <tr className="border-b">
                <td className="py-2.5 pr-3 font-medium text-muted-foreground sticky left-0 bg-background">Webhooks</td>
                {providers.map((p) => (
                  <td key={p.id} className="py-2.5 px-2">
                    <div className="flex justify-center"><FeatureIcon value={p.webhooks} /></div>
                  </td>
                ))}
              </tr>
              <tr className="border-b">
                <td className="py-2.5 pr-3 font-medium text-muted-foreground sticky left-0 bg-background">Event tracking</td>
                {providers.map((p) => (
                  <td key={p.id} className="py-2.5 px-2">
                    <div className="flex justify-center"><FeatureIcon value={p.eventTracking} /></div>
                  </td>
                ))}
              </tr>
              <tr className="border-b">
                <td className="py-2.5 pr-3 font-medium text-muted-foreground sticky left-0 bg-background">Suppression lists</td>
                {providers.map((p) => (
                  <td key={p.id} className="py-2.5 px-2">
                    <div className="flex justify-center"><FeatureIcon value={p.suppressionLists} /></div>
                  </td>
                ))}
              </tr>
              <tr>
                <td className="py-2.5 pr-3 font-medium text-muted-foreground sticky left-0 bg-background">DKIM via API</td>
                {providers.map((p) => (
                  <td key={p.id} className="py-2.5 px-2">
                    <div className="flex justify-center"><FeatureIcon value={p.dkimApi} /></div>
                  </td>
                ))}
              </tr>
            </tbody>
          </table>
          <div className="mt-4 mb-2 space-y-2 text-xs text-muted-foreground">
            <p>
              <strong>Recommendation:</strong> Mailgun offers the most complete feature set with API-managed DKIM and inbound routing.
              AWS SES is the cheapest at scale but requires more setup. Resend has the simplest developer experience.
            </p>
            <p>
              All providers support sending and receiving email through selfmx. Features like suppression lists and
              DKIM management enhance deliverability and are managed automatically when available.
            </p>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
