# Responsive & Mobile Anti-Patterns

### Don't: Use Desktop-First CSS

```tsx
// ❌ WRONG: Desktop-first (mobile breaks)
<div className="flex-row md:flex-col">      // Mobile gets row, tablet gets column
<div className="w-1/3 md:w-full">           // Mobile gets 1/3 width, too narrow!
<div className="text-xl md:text-base">      // Mobile gets larger text than tablet

// ✅ CORRECT: Mobile-first (base mobile, add larger)
<div className="flex flex-col md:flex-row">  // Mobile stacks, tablet+ side-by-side
<div className="w-full md:w-1/2 lg:w-1/3">   // Mobile full, progressively narrower
<div className="text-base md:text-lg lg:text-xl">  // Progressive enhancement
```

### Don't: Ignore Touch Targets

```tsx
// ❌ WRONG: Touch targets too small
<button className="p-1 text-xs">Click me</button>
<Button size="sm" className="h-6">Tiny</Button>

// ✅ CORRECT: Minimum 44px touch targets
<button className="p-3 min-h-[44px]">Click me</button>
<Button className="min-h-[44px]">Proper size</Button>

// Icon buttons need explicit sizing
<Button variant="ghost" size="icon" className="h-11 w-11">
  <Icon className="h-5 w-5" />
</Button>
```

### Don't: Use Fixed Widths Without Responsive Fallbacks

```tsx
// ❌ WRONG: Fixed width breaks mobile
<div className="w-[800px]">Content</div>
<div className="w-1/2">Always half width</div>

// ✅ CORRECT: Full width on mobile, constrained on larger
<div className="w-full max-w-[800px]">Content</div>
<div className="w-full md:w-1/2">Full on mobile, half on tablet+</div>
```

### Don't: Forget Tables Need Horizontal Scroll

```tsx
// ❌ WRONG: Table overflows and breaks layout
<Table>
  <TableBody>{/* Wide content */}</TableBody>
</Table>

// ✅ CORRECT: Wrap tables in scroll container
<div className="overflow-x-auto">
  <Table className="min-w-[600px]">
    <TableBody>{/* Wide content */}</TableBody>
  </Table>
</div>
```

### Don't: Duplicate Mobile/Desktop Without Hook

```tsx
// ❌ WRONG: Using CSS media queries for complex conditional logic
// or duplicating entire component structures

// ✅ CORRECT: Use useIsMobile hook for different UIs
import { useIsMobile } from "@/lib/use-mobile";

function MyComponent() {
  const isMobile = useIsMobile();
  
  if (isMobile) {
    return <MobileDrawerNav />;
  }
  return <DesktopSidebar />;
}
```

### Don't: Hardcode Breakpoint Values

```tsx
// ❌ WRONG: Hardcoded breakpoint values
useEffect(() => {
  const handleResize = () => {
    setIsMobile(window.innerWidth < 768);  // Magic number!
  };
}, []);

// ✅ CORRECT: Use the useIsMobile hook (consistent breakpoint)
import { useIsMobile } from "@/lib/use-mobile";

function MyComponent() {
  const isMobile = useIsMobile();  // Uses 768px breakpoint consistently
}
```

### Don't: Forget Landscape Orientation

```tsx
// ❌ WRONG: Only testing portrait mode
// Component works at 375px portrait but breaks at 667px landscape

// ✅ CORRECT: Consider both orientations
// Test at 667x375 (iPhone landscape)
// Use min-height constraints where needed
<div className="min-h-screen md:min-h-0">
```

### Don't: Create Hover-Only Interactions

```tsx
// ❌ WRONG: Hover-only (doesn't work on touch)
<div className="opacity-0 hover:opacity-100">
  Hidden until hover
</div>

// ✅ CORRECT: Visible by default or use touch-friendly alternative
<div className="opacity-100 md:opacity-0 md:hover:opacity-100">
  Visible on mobile, hover on desktop
</div>

// Or use explicit touch/click handlers
<button onClick={handleToggle}>
  {showOptions && <Options />}
</button>
```

### Don't: Define Utility Functions Inline in Pages

Common utilities like `formatBytes` and `formatDate` should not be defined in page components. These get duplicated across the codebase.

```tsx
// ❌ WRONG: Utility defined inline in page component
// frontend/app/(dashboard)/configuration/backup/page.tsx
export default function BackupPage() {
  const formatBytes = (bytes: number) => {
    if (bytes === 0) return "0 B";
    const k = 1024;
    const sizes = ["B", "KB", "MB", "GB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
  };
  // ...
}

// Same function duplicated in storage/page.tsx, file-browser.tsx, etc.
```

```tsx
// ✅ CORRECT: Centralize utilities in lib/utils.ts
// frontend/lib/utils.ts
export function formatBytes(bytes: number, decimals = 2): string {
  if (bytes === 0) return "0 B";
  const k = 1024;
  const sizes = ["B", "KB", "MB", "GB", "TB"];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(decimals)) + " " + sizes[i];
}

export function formatDate(date: string | Date, options?: Intl.DateTimeFormatOptions): string {
  return new Date(date).toLocaleDateString(undefined, options ?? {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

// Then import where needed
import { formatBytes, formatDate } from "@/lib/utils";
```

**Centralized in `frontend/lib/utils.ts`:** `formatBytes`, `formatDate`, `formatDateTime`, `formatTimestamp`, `getErrorMessage`. Use these instead of defining inline.

**Before adding any utility, search the codebase** to see if it already exists. If it does, use the existing one. If it doesn't exist but would be useful in multiple places, add it to `frontend/lib/utils.ts`.

### Don't: Skip PWA Safe Area Insets

```tsx
// ❌ WRONG: Header clips behind iPhone notch in standalone mode
<header className="sticky top-0 h-14">

// ✅ CORRECT: Apply env() safe area padding
<header className="sticky top-0 h-14 pt-[env(safe-area-inset-top)]">

// ❌ WRONG: Bottom content hidden behind home indicator
<div className="fixed bottom-0">

// ✅ CORRECT: Respect home indicator
<div className="fixed bottom-0 pb-[env(safe-area-inset-bottom)]">
```

Also confirm `<meta name="viewport" content="..., viewport-fit=cover">` is set — required for `env()` insets to work.

> **PWA Impact**: HIGH — Without this, the header is partially hidden behind the iOS notch in standalone mode.

### Don't: Use Hover-Only Delete/Action Buttons on Touch

```tsx
// ❌ WRONG: Delete button only appears on hover — invisible on touch devices
<div className="relative group">
  <Image src={logo} />
  <button className="absolute top-2 right-2 opacity-0 group-hover:opacity-100">
    <X className="h-4 w-4" />
  </button>
</div>

// ✅ CORRECT: Always-visible action button (or persistent button below the element)
<div className="relative">
  <Image src={logo} />
  <Button
    variant="ghost"
    size="icon"
    className="absolute top-2 right-2"  // Always rendered, not hover-gated
    onClick={handleDelete}
  >
    <X className="h-4 w-4" />
  </Button>
</div>
```

> Touch devices cannot hover. Hover-only interactions are completely broken for PWA users.
