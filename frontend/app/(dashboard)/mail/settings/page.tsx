"use client";

import Link from "next/link";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { ListFilter, ShieldBan, Upload } from "lucide-react";

const sections = [
  {
    title: "Rules",
    description: "Create rules to automatically label, archive, forward, or mark emails based on conditions like sender, subject, or content.",
    href: "/mail/settings/rules",
    icon: ListFilter,
  },
  {
    title: "Spam Filter",
    description: "Manage your personal allow and block lists to control which senders can reach your inbox.",
    href: "/mail/settings/spam",
    icon: ShieldBan,
  },
  {
    title: "Import Emails",
    description: "Upload mbox or eml files to import emails into your mailboxes. Large files are processed in the background.",
    href: "/mail/settings/import",
    icon: Upload,
  },
];

export default function MailSettingsPage() {
  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-2xl font-bold tracking-tight">Mail Settings</h2>
        <p className="text-muted-foreground">
          Manage your email rules, spam filter, and import settings.
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {sections.map((section) => (
          <Link key={section.href} href={section.href} className="block">
            <Card className="h-full transition-colors hover:bg-muted/50">
              <CardHeader>
                <div className="flex items-center gap-3">
                  <section.icon className="h-5 w-5 text-muted-foreground" />
                  <CardTitle className="text-lg">{section.title}</CardTitle>
                </div>
              </CardHeader>
              <CardContent>
                <CardDescription>{section.description}</CardDescription>
              </CardContent>
            </Card>
          </Link>
        ))}
      </div>
    </div>
  );
}
