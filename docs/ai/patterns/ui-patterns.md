# UI Component Patterns

Shared UI patterns for configuration, navigation, help, auth, and PWA.

## App Shell & Layout

The app shell (`frontend/components/app-shell.tsx`) wraps all dashboard pages and provides:

- **Sticky header** (`h-14`): Breadcrumbs on the left (desktop), grouped action buttons with vertical `<Separator>` dividers on the right, `backdrop-blur-lg` background
- **Content well**: `bg-muted/30` background behind all page content
- **Container**: `max-w-7xl mx-auto px-4 py-6 sm:px-6 lg:px-8` — pages should NOT add their own `container`, `p-4`, or outer padding
- **Sidebar alignment**: Sidebar header uses `h-14` to match the header height exactly

### Page heading standard

All pages use: `<h1 className="text-2xl font-bold tracking-tight">Page Title</h1>`

Do not use `text-3xl`, `font-semibold`, or other heading variants.

**Key files:** `frontend/components/app-shell.tsx`, `frontend/components/header.tsx`, `frontend/components/sidebar.tsx`

## AppBreadcrumbs

Path-based breadcrumbs auto-generated from the URL. Displayed in the header on desktop; hidden on mobile and on `/dashboard`.

```tsx
import { AppBreadcrumbs } from "@/components/app-breadcrumbs";

// Used inside header.tsx — no per-page setup needed
<AppBreadcrumbs />
```

Segment labels are mapped in `SEGMENT_LABELS`. Unknown segments are auto-formatted (kebab-case → Title Case). Dynamic segments (UUIDs, IDs) are displayed as-is.

**Key file:** `frontend/components/app-breadcrumbs.tsx`

## Header Action Grouping

Header actions are organized into groups separated by vertical `<Separator>` dividers:

1. **Search group**: Search button / inline search (when search enabled)
2. **Utility group**: Help icon + Notification bell
3. **User group**: Theme toggle + User dropdown

```tsx
import { Separator } from "@/components/ui/separator";

<Separator orientation="vertical" className="mx-1 hidden md:block h-5" />
```

**Key file:** `frontend/components/header.tsx`

## CollapsibleCard

Expandable sections for settings and configuration pages.

```tsx
import { CollapsibleCard } from "@/components/ui/collapsible-card";

<CollapsibleCard
  title="Slack"
  description="Send notifications to Slack channels."
  icon={<MessageSquare className="h-4 w-4" />}
  status={{ label: "Configured", variant: "success" }}
  defaultOpen={false}
>
  <div className="space-y-4">{/* form fields */}</div>
</CollapsibleCard>
```

Props: `title`, `description?`, `icon?`, `status?` ({ label, variant }), `defaultOpen?`, `open?`, `onOpenChange?`, `headerActions?`, `children`, `disabled?`, `className?`.

**Key file:** `frontend/components/ui/collapsible-card.tsx`

## ProviderIcon

Shared icon component for SSO, LLM, notification, backup providers.

```tsx
import { ProviderIcon } from "@/components/provider-icons";

<ProviderIcon provider="google" size="sm" style="mono" />
<ProviderIcon provider={provider.icon} size="sm" style="branded" />
```

Props: `provider: string`, `size?: "sm" | "md" | "lg"`, `style?: "mono" | "branded"`, `className?`.

Adding a new icon: Edit `frontend/components/provider-icons.tsx`, add entry to `SSO_ICONS`, `LLM_ICONS`, or `ALL_ICONS`.

**Key file:** `frontend/components/provider-icons.tsx`

## Configuration Navigation

Grouped, collapsible navigation in `configuration/layout.tsx`. Items organized into groups (General, Users & Access, Communications, Integrations, Logs & Monitoring, Data). Expanded/collapsed state persisted in localStorage.

Adding a new item:
1. Choose appropriate group
2. Add entry to that group's `items` in `navigationGroups`
3. Create page at `frontend/app/(dashboard)/configuration/[slug]/page.tsx`

**Key file:** `frontend/app/(dashboard)/configuration/layout.tsx`

## Help System

In-app searchable documentation with contextual links and tooltips.

```tsx
import { HelpLink } from "@/components/help/help-link";

<p className="text-muted-foreground">
  Configure this feature. <HelpLink articleId="my-article-id" />
</p>
```

- **HelpArticle**: `id`, `title`, `content` (markdown), `tags?`
- **HelpCategory**: `slug`, `name`, `icon?`, `articles`, `permission?`
- Categories in `userHelpCategories` are visible to all users; categories in `permissionHelpCategories` are gated by the `permission` field (e.g., `backups.view`, `audit.view`). Admin users have all permissions, so they see everything.
- `getAllCategories(permissions: string[])` filters by the user's permissions array
- Help modal opened with `?` or `Ctrl+/`
- Search integration via `backend/config/search-pages.php` with `help:` URL prefix and `permission` field
- Every config page should have a `HelpLink` in its description area

**Key files:** `frontend/lib/help/help-content.ts`, `frontend/components/help/`

## SaveButton

Standardized save button for all settings/configuration forms. Always use this instead of inline `Button` with manual loading logic.

```tsx
import { SaveButton } from "@/components/ui/save-button";

// Inside a <form> (default type="submit")
<SaveButton isDirty={isDirty} isSaving={isSaving} />

// With onClick handler (no form), use type="button"
<SaveButton type="button" isDirty={isDirty} isSaving={isSaving} onClick={handleSave} />

// Custom size for inline contexts (e.g., per-item settings)
<SaveButton type="button" size="sm" isDirty={true} isSaving={isSaving} onClick={handleSave} />
```

Props: `isDirty: boolean`, `isSaving: boolean`, `type?: "submit" | "button"` (default `"submit"`), `children?` (default `"Save Changes"`), plus all standard `ButtonProps`.

### CardFooter Alignment Standards

Always place save buttons in `<CardFooter>` with consistent alignment:

| Pattern | CardFooter className | When to use |
|---------|---------------------|-------------|
| Single save button | `flex justify-end` | Most pages |
| Save + helper text | `flex flex-col gap-4 sm:flex-row sm:justify-between` | When explanatory text accompanies save |
| Save + other buttons | `flex flex-wrap justify-end gap-2` | Test Connection, Send Test, Preview alongside Save |
| Reset + Save | `flex justify-between` | Destructive/secondary action opposite Save |

**Key file:** `frontend/components/ui/save-button.tsx`

## Auth Page Components

All auth pages use the **shadcn login-03 pattern**: `bg-muted` full-page surface with logo centered above a `Card`. This replaces the previous glassmorphism/gradient approach.

### AuthPageLayout

Wraps all auth pages (login, register, 2FA). Renders a `bg-muted` background, centered logo, and a `Card` with title/description header.

```tsx
import { AuthPageLayout } from "@/components/auth/auth-page-layout";

<AuthPageLayout title="Welcome back" description="Enter your credentials to access your account">
  {/* Form content rendered inside Card > CardContent */}
</AuthPageLayout>
```

### FormField

```tsx
import { FormField } from "@/components/ui/form-field";

<FormField id="email" label="Email" description="We'll never share your email." error={errors.email?.message}>
  <Input id="email" type="email" {...register("email")} />
</FormField>
```

### LoadingButton

```tsx
import { LoadingButton } from "@/components/ui/loading-button";

<LoadingButton type="submit" isLoading={isLoading} loadingText="Signing in...">Sign In</LoadingButton>
```

### Checkbox (in forms)

Use the shadcn `Checkbox` component (not raw `<input type="checkbox">`). With react-hook-form, use `Controller`:

```tsx
import { Checkbox } from "@/components/ui/checkbox";
import { Controller } from "react-hook-form";

<Controller
  name="remember"
  control={control}
  render={({ field }) => (
    <Checkbox id="remember" checked={field.value} onCheckedChange={field.onChange} />
  )}
/>
```

### SSO Provider Display

Sign-in pages: `SSOButtons` fetches enabled providers from `GET /auth/sso/providers`. Provider shown when credentials set, test passed, and enabled flag is true. Icons from `ProviderIcon`.

Setup page (Configuration > SSO): Global options card + per-provider cards with CollapsibleCard, status badges, test connection, enable toggle. Three conditions for login page display: (1) Credentials, (2) Test passed, (3) Enabled.

### AuthDivider / AuthStateCard

`AuthDivider` uses `bg-card` (not `bg-background`) since it renders inside a Card. `AuthStateCard` uses the same `bg-muted` + logo-above-card layout as `AuthPageLayout`.

```tsx
<AuthDivider text="Or continue with email" />
<AuthStateCard variant="success" title="Email Verified" description="Your email has been verified." />
```

### Standalone Auth Pages (forgot-password, reset-password)

Pages not using `AuthPageLayout` should replicate the same pattern manually:

```tsx
<div className="bg-muted flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
  <div className="flex w-full max-w-sm flex-col gap-6">
    <div className="flex items-center gap-2 self-center">
      <Logo variant="full" size="md" />
    </div>
    <Card>
      <CardHeader className="text-center">
        <CardTitle className="text-xl">Page Title</CardTitle>
      </CardHeader>
      {/* ... */}
    </Card>
  </div>
</div>
```

**Key files:** `frontend/components/auth/`, `frontend/components/ui/form-field.tsx`, `frontend/components/ui/loading-button.tsx`, `frontend/app/(dashboard)/configuration/sso/page.tsx`

## PWA Install Prompt

```tsx
import { useInstallPrompt } from "@/lib/use-install-prompt";
import { InstallPrompt } from "@/components/install-prompt";

<InstallPrompt />

const { canPrompt, isInstalled, promptInstall } = useInstallPrompt();
```

- Hook: `useInstallPrompt()` returns `deferredPrompt`, `canPrompt`, `isInstalled`, `promptInstall()`, `dismissBanner()`, `shouldShowBanner`
- Dialog shows after 2+ visits, not dismissed, install available, not installed
- Uses `AlertDialog` (shadcn) for proper light/dark mode theming
- Add `<InstallPrompt />` to AppShell

**Key files:** `frontend/lib/use-install-prompt.ts`, `frontend/components/install-prompt.tsx`, `frontend/components/app-shell.tsx`

**Related:** [Recipe: Add collapsible section](../recipes/add-collapsible-section.md), [Recipe: Add SSO Provider](../recipes/add-sso-provider.md), [Recipe: Add help article](../recipes/add-help-article.md), [Recipe: Add configuration menu item](../recipes/add-configuration-menu-item.md), [Recipe: Add PWA install prompt](../recipes/add-pwa-install-prompt.md)
