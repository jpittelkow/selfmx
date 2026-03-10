import Link from "next/link";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ClipboardList, Users, Settings, Shield } from "lucide-react";

const actions = [
  { label: "Audit Logs", href: "/configuration/audit", icon: ClipboardList },
  { label: "Users", href: "/configuration/users", icon: Users },
  { label: "Settings", href: "/configuration/system", icon: Settings },
  { label: "Security", href: "/configuration/security", icon: Shield },
];

export function QuickActionsWidget() {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-sm font-medium">Quick Actions</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="grid grid-cols-2 gap-2">
          {actions.map((action) => (
            <Link
              key={action.href}
              href={action.href}
              className="flex flex-col items-center gap-2 rounded-lg border p-3 text-center transition-colors hover:bg-muted hover:border-primary/30 min-h-[72px] justify-center"
            >
              <action.icon className="h-5 w-5 text-muted-foreground transition-colors" />
              <span className="text-xs font-medium">{action.label}</span>
            </Link>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
