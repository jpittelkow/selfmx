import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { getInitials, getAvatarColor, cn } from "@/lib/utils";

interface SenderAvatarProps {
  name: string | null;
  email: string;
  size?: "sm" | "md" | "lg";
  className?: string;
}

const sizeClasses = {
  sm: "h-8 w-8 text-xs",
  md: "h-10 w-10 text-sm",
  lg: "h-12 w-12 text-base",
};

export function SenderAvatar({ name, email, size = "sm", className }: SenderAvatarProps) {
  const displayName = name || email;
  const initials = getInitials(displayName);
  const colorClass = getAvatarColor(email);

  return (
    <Avatar className={cn(sizeClasses[size], className)}>
      <AvatarFallback className={cn(colorClass, "text-white font-medium")}>
        {initials}
      </AvatarFallback>
    </Avatar>
  );
}
