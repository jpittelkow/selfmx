import { Skeleton } from "@/components/ui/skeleton";

export function ThreadListSkeleton() {
  return (
    <div className="py-2">
      {/* Date group header skeleton */}
      <div className="px-4 py-1.5 border-b">
        <Skeleton className="h-3 w-16" />
      </div>
      {Array.from({ length: 8 }).map((_, i) => (
        <div key={i} className="flex items-start gap-3 px-4 py-3 border-b border-l-2 border-l-transparent">
          <Skeleton className="h-4 w-4 mt-0.5 shrink-0 rounded" />
          <Skeleton className="h-8 w-8 shrink-0 rounded-full" />
          <div className="flex-1 space-y-1.5">
            <div className="flex items-center justify-between gap-2">
              <Skeleton className="h-4 w-28" />
              <Skeleton className="h-3 w-12" />
            </div>
            <Skeleton className="h-4 w-3/4" />
            <Skeleton className="h-3 w-1/2" />
          </div>
        </div>
      ))}
    </div>
  );
}
