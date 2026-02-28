# Recipe: Add Configuration Page

Add a new configuration page with react-hook-form + Zod validation. **Fields are optional by default.**

## Files to Create/Modify

| File | Action | Purpose |
|------|--------|---------|
| `frontend/app/(dashboard)/configuration/{name}/page.tsx` | Create | Config page UI |
| `frontend/app/(dashboard)/configuration/layout.tsx` | Modify | Add nav item to `navigationGroups` |
| `backend/routes/api.php` | Modify | Add config endpoints |
| `backend/app/Http/Controllers/Api/{Name}Controller.php` | Create | Handle requests |
| `backend/config/search-pages.php` | Modify | Search registration (backend) |
| `frontend/lib/search-pages.ts` | Modify | Search registration (frontend) |

## Reference Implementations

- **Simple config page**: `frontend/app/(dashboard)/configuration/branding/page.tsx`
- **Config with SettingService**: `frontend/app/(dashboard)/configuration/storage/page.tsx` + `backend/app/Http/Controllers/Api/StorageSettingController.php`
- **Nav registration**: see [add-configuration-menu-item recipe](add-configuration-menu-item.md)
- **Search registration**: see [add-searchable-page recipe](add-searchable-page.md)

## Critical Rules

1. **Optional by default** - Use `z.string().optional()`. For format validation on optional fields, use `.refine()` to allow empty:
   ```tsx
   webhook_url: z.string()
     .refine((val) => !val || val === "" || isValidUrl(val), { message: "Must be a valid URL" })
     .optional(),
   ```

2. **Validate on blur** - `useForm({ resolver: zodResolver(schema), mode: "onBlur" })`

3. **Use `reset()` for initial values** - Not `setValue()`. This establishes clean state for `isDirty` tracking.

4. **Custom inputs need `shouldDirty`** - `setValue("field", value, { shouldDirty: true })`

5. **Send null for empty fields** - Backend receives `null` (not empty string) to clear fields.

6. **Backend uses `nullable`** - `'field' => 'nullable|string|max:255'`

## Common Field Patterns

```tsx
// Optional string (most common)
field_name: z.string().optional(),

// Optional string with format validation (URL, email, etc.)
url_field: z.string()
  .refine((val) => !val || val === "" || isValidUrl(val), { message: "Must be a valid URL" })
  .optional(),

// Boolean (always has a value)
toggle_field: z.boolean().default(false),

// Optional number (coerce from string input)
number_field: z.coerce.number().optional(),

// ONLY when truly required
required_field: z.string().min(1, "This field is required"),
```

## Page Skeleton

```tsx
"use client";

import { useState, useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { Switch } from "@/components/ui/switch";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { SaveButton } from "@/components/ui/save-button";

const configSchema = z.object({
  api_key: z.string().optional(),
  enabled: z.boolean().default(false),
});
type ConfigForm = z.infer<typeof configSchema>;

export default function ExampleConfigPage() {
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);

  const { register, handleSubmit, formState: { errors, isDirty }, setValue, watch, reset } = useForm<ConfigForm>({
    resolver: zodResolver(configSchema),
    mode: "onBlur",
    defaultValues: { api_key: "", enabled: false },
  });

  const fetchSettings = async () => {
    setIsLoading(true);
    try {
      const response = await api.get("/config/example");
      reset({ api_key: response.data.settings?.api_key || "", enabled: response.data.settings?.enabled || false });
    } catch { toast.error("Failed to load settings"); }
    finally { setIsLoading(false); }
  };

  const onSubmit = async (data: ConfigForm) => {
    setIsSaving(true);
    try {
      await api.put("/config/example", { ...data, api_key: data.api_key || null });
      toast.success("Settings saved");
      await fetchSettings();
    } catch { toast.error("Failed to save settings"); }
    finally { setIsSaving(false); }
  };

  useEffect(() => { fetchSettings(); }, []);
  if (isLoading) return <SettingsPageSkeleton />;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Example Config</h1>
        <p className="text-muted-foreground mt-2">Configure your integration.</p>
      </div>
      <form onSubmit={handleSubmit(onSubmit)}>
        <Card>
          <CardHeader><CardTitle>Settings</CardTitle></CardHeader>
          <CardContent className="space-y-6">
            <div className="flex items-center justify-between">
              <Label>Enabled</Label>
              <Switch checked={watch("enabled")} onCheckedChange={(checked) => setValue("enabled", checked, { shouldDirty: true })} />
            </div>
            <div className="space-y-2">
              <Label htmlFor="api_key">API Key</Label>
              <Input {...register("api_key")} type="password" placeholder="Optional" />
              {errors.api_key && <p className="text-sm text-destructive">{errors.api_key.message}</p>}
            </div>
          </CardContent>
          <CardFooter className="flex justify-end"><SaveButton isDirty={isDirty} isSaving={isSaving} /></CardFooter>
        </Card>
      </form>
    </div>
  );
}
```

## When Config Uses SettingService (DB + env fallback)

1. Add keys to `backend/config/settings-schema.php` with `env`, `default`, `encrypted`, `public` where needed
2. Backend controller uses **SettingService** — `$this->settingService->getGroup('group')` and `$this->settingService->set('group', $key, $value, $userId)`
3. Add `injectXxxConfig()` method in `ConfigServiceProvider` and call from `boot()`
4. Reset to default: `DELETE /api/.../keys/{key}` — validate key exists in `config('settings-schema.{group}')`

See [SettingService pattern](../patterns/setting-service.md) and `MailSettingController.php` for key mapping example.

## Checklist

- [ ] Schema uses `.optional()` for non-required fields
- [ ] Format validations use `.refine()` to allow empty strings
- [ ] Form uses `mode: "onBlur"`
- [ ] Initial values loaded with `reset()`
- [ ] Custom inputs use `setValue(..., { shouldDirty: true })`
- [ ] Submit converts empty strings to `null`
- [ ] Save button disabled when `!isDirty || isSaving`
- [ ] Backend validation uses `nullable` for optional fields
- [ ] Nav item added to `configuration/layout.tsx` `navigationGroups` ([recipe](add-configuration-menu-item.md))
- [ ] Page registered in both `search-pages.php` and frontend search pages ([recipe](add-searchable-page.md))
