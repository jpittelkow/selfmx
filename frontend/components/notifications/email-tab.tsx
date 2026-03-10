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
import { Mail } from "lucide-react";

export function EmailTab() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Email Configuration</CardTitle>
        <CardDescription>Email delivery settings are managed on the dedicated Email configuration page.</CardDescription>
      </CardHeader>
      <CardContent>
        <Link href="/configuration/email">
          <Button variant="outline">
            <Mail className="mr-2 h-4 w-4" />
            Go to Email Configuration
          </Button>
        </Link>
      </CardContent>
    </Card>
  );
}
