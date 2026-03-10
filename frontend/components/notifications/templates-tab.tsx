"use client";

import Link from "next/link";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { FileText } from "lucide-react";

export function TemplatesTab() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Notification Templates</CardTitle>
        <CardDescription>Notification templates are managed on the dedicated templates configuration page.</CardDescription>
      </CardHeader>
      <CardContent>
        <Link href="/configuration/notification-templates">
          <Button variant="outline">
            <FileText className="mr-2 h-4 w-4" />
            Go to Notification Templates
          </Button>
        </Link>
      </CardContent>
    </Card>
  );
}
