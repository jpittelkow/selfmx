import { Skeleton } from "@/components/ui/skeleton";

export function EmailDetailSkeleton() {
  return (
    <div className="flex flex-col h-full">
      {/* Subject bar */}
      <div className="px-6 py-4 border-b">
        <Skeleton className="h-6 w-2/3" />
      </div>

      {/* Email message skeleton */}
      <div className="px-6 py-4 space-y-4">
        {/* Sender info */}
        <div className="flex items-start justify-between gap-4">
          <div className="space-y-1.5">
            <Skeleton className="h-4 w-36" />
            <Skeleton className="h-3 w-48" />
          </div>
          <div className="flex items-center gap-2">
            <Skeleton className="h-3 w-24" />
            <Skeleton className="h-8 w-8 rounded" />
            <Skeleton className="h-8 w-8 rounded" />
          </div>
        </div>

        {/* Body */}
        <div className="space-y-2 pt-2">
          <Skeleton className="h-4 w-full" />
          <Skeleton className="h-4 w-full" />
          <Skeleton className="h-4 w-5/6" />
          <Skeleton className="h-4 w-full" />
          <Skeleton className="h-4 w-3/4" />
          <Skeleton className="h-4 w-2/3" />
        </div>
      </div>
    </div>
  );
}
