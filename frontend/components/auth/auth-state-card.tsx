import { LucideIcon, CheckCircle, AlertTriangle, XCircle, Mail, Loader2 } from "lucide-react";
import { Logo } from "@/components/logo";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { cn } from "@/lib/utils";

interface AuthStateCardProps {
  variant: "success" | "error" | "warning" | "loading";
  icon?: LucideIcon;
  title: string;
  description?: string | React.ReactNode;
  children?: React.ReactNode;
  footer?: React.ReactNode;
}

const variantConfig = {
  success: {
    bg: "bg-green-100 dark:bg-green-900/20",
    iconColor: "text-green-600 dark:text-green-400",
    defaultIcon: CheckCircle,
  },
  error: {
    bg: "bg-destructive/10",
    iconColor: "text-destructive",
    defaultIcon: XCircle,
  },
  warning: {
    bg: "bg-yellow-100 dark:bg-yellow-900/20",
    iconColor: "text-yellow-600 dark:text-yellow-400",
    defaultIcon: Mail,
  },
  loading: {
    bg: "bg-primary/10",
    iconColor: "text-primary",
    defaultIcon: Loader2,
  },
};

export function AuthStateCard({
  variant,
  icon: Icon,
  title,
  description,
  children,
  footer,
}: AuthStateCardProps) {
  const config = variantConfig[variant];
  const IconComponent = Icon || config.defaultIcon;
  const isSpinner = variant === "loading" && IconComponent === Loader2;

  return (
    <div className="bg-muted flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
      <div className="flex w-full max-w-sm flex-col gap-6">
        <div className="flex items-center gap-2 self-center">
          <Logo variant="full" size="md" />
        </div>
        <Card>
          <CardHeader className="text-center">
            <div
              className={cn(
                "mx-auto w-12 h-12 rounded-full flex items-center justify-center mb-4",
                config.bg
              )}
            >
              {isSpinner ? (
                <IconComponent className={cn("h-6 w-6 animate-spin", config.iconColor)} />
              ) : (
                <IconComponent className={cn("h-6 w-6", config.iconColor)} />
              )}
            </div>
            <CardTitle className="text-xl">{title}</CardTitle>
            {description && <CardDescription>{description}</CardDescription>}
          </CardHeader>
          {children && <CardContent>{children}</CardContent>}
          {footer && <CardFooter>{footer}</CardFooter>}
        </Card>
      </div>
    </div>
  );
}
