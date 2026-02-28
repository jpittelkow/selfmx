# Configuration & Settings Page Patterns

Patterns for building dashboard pages, settings pages, and forms with validation.

## Layout & Shell

Pages render inside the app shell which provides:
- **Container**: `max-w-7xl mx-auto px-4 py-6 sm:px-6 lg:px-8` — pages should NOT add their own container or outer padding
- **Background**: Subtle `bg-muted/30` content well behind the main area
- **Breadcrumbs**: Auto-generated from the URL path via `AppBreadcrumbs` (in the header) — no per-page breadcrumb setup needed
- **Header height**: Fixed `h-14` sticky header with `backdrop-blur-lg`

### Page heading standard

All pages use the same h1 pattern: `text-2xl font-bold tracking-tight`. Do not use `text-3xl` or `font-semibold`.

## Page Component Pattern

```tsx
// frontend/app/(dashboard)/example/page.tsx
'use client';

import { useState, useEffect } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { toast } from 'sonner';
import { Loader2 } from 'lucide-react';

interface Example {
  id: string;
  name: string;
  description: string;
  created_at: string;
}

export default function ExamplePage() {
  const [examples, setExamples] = useState<Example[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => { fetchExamples(); }, []);

  const fetchExamples = async () => {
    try {
      setLoading(true);
      const response = await api.get('/examples');
      setExamples(response.data.data);
      setError(null);
    } catch (err) {
      setError('Failed to load examples');
      toast.error('Failed to load examples');
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[400px] space-y-4">
        <p className="text-destructive">{error}</p>
        <Button onClick={fetchExamples}>Retry</Button>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Examples</h1>
          <p className="text-muted-foreground">Manage your examples here.</p>
        </div>
        <Button>Create Example</Button>
      </div>

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {examples.map((example) => (
          <Card key={example.id}>
            <CardHeader>
              <CardTitle>{example.name}</CardTitle>
              <CardDescription>{example.description}</CardDescription>
            </CardHeader>
            <CardContent>
              <p className="text-sm text-muted-foreground">
                Created: {new Date(example.created_at).toLocaleDateString()}
              </p>
            </CardContent>
          </Card>
        ))}
      </div>

      {examples.length === 0 && (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <p className="text-muted-foreground mb-4">No examples yet.</p>
            <Button>Create your first example</Button>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
```

## Settings Page Components

### SettingsPageSkeleton

Loading state component for settings pages:

```tsx
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";

if (isLoading) return <SettingsPageSkeleton />;
```

Props: `minHeight?: string` (default: "400px"). **Key file:** `frontend/components/ui/settings-page-skeleton.tsx`

### SaveButton

Form save button with built-in dirty/saving state handling:

```tsx
import { SaveButton } from "@/components/ui/save-button";

<SaveButton isDirty={isDirty} isSaving={isSaving} />
```

Props: `isDirty: boolean`, `isSaving: boolean`, `children?: ReactNode` (default: "Save Changes"). Extends all `ButtonProps` except `type`. **Key file:** `frontend/components/ui/save-button.tsx`

### SettingsSwitchRow

Settings row with label and switch, 44px touch target for accessibility:

```tsx
import { SettingsSwitchRow } from "@/components/ui/settings-switch-row";

<SettingsSwitchRow
  label="Enable feature"
  description="Optional description below the label"
  checked={watch("enabled")}
  onCheckedChange={(checked) => setValue("enabled", checked, { shouldDirty: true })}
/>
```

Props: `label: string`, `description?: string`, `checked: boolean`, `onCheckedChange: (checked: boolean) => void`, `disabled?: boolean`, `className?: string`. **Key file:** `frontend/components/ui/settings-switch-row.tsx`

## Form with Validation Pattern

```tsx
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import * as z from 'zod';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';

const formSchema = z.object({
  name: z.string().min(1, 'Name is required').max(255),
  email: z.string().email('Invalid email address'),
  type: z.enum(['typeA', 'typeB', 'typeC']),
});

type FormData = z.infer<typeof formSchema>;

export function ExampleForm({ onSubmit }: { onSubmit: (data: FormData) => void }) {
  const form = useForm<FormData>({
    resolver: zodResolver(formSchema),
    defaultValues: { name: '', email: '', type: 'typeA' },
  });

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
        <FormField
          control={form.control}
          name="name"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Name</FormLabel>
              <FormControl><Input {...field} /></FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <Button type="submit">Submit</Button>
      </form>
    </Form>
  );
}
```

**Key files:** `frontend/components/ui/settings-page-skeleton.tsx`, `frontend/components/ui/save-button.tsx`, `frontend/components/ui/settings-switch-row.tsx`, `frontend/components/ui/form-field.tsx`

**Related:** [Recipe: Add config page](../recipes/add-config-page.md), [Anti-patterns: Frontend](../anti-patterns/frontend.md), [Anti-patterns: Forms](../anti-patterns/forms.md)
