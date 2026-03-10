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
import { Bell } from "lucide-react";

export function NovuTab() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Novu Configuration</CardTitle>
        <CardDescription>Novu notification infrastructure settings are managed on the dedicated Novu configuration page.</CardDescription>
      </CardHeader>
      <CardContent>
        <Link href="/configuration/novu">
          <Button variant="outline">
            <Bell className="mr-2 h-4 w-4" />
            Go to Novu Configuration
          </Button>
        </Link>
      </CardContent>
    </Card>
  );
}
