import { ContentSkeleton } from "@/components/ui/content-skeleton";

interface SettingsPageSkeletonProps {
  minHeight?: string;
  variant?: "settings" | "table";
}

export function SettingsPageSkeleton({ minHeight = "400px", variant = "settings" }: SettingsPageSkeletonProps) {
  return (
    <div style={{ minHeight }}>
      <ContentSkeleton variant={variant} />
    </div>
  );
}
