# Recipe: Add Settings Page

Add a new settings page in the dashboard (user-scoped settings using the `Setting` model).

## Files to Create/Modify

| File | Action | Purpose |
|------|--------|---------|
| `frontend/app/(dashboard)/settings/{name}/page.tsx` | Create | Settings page UI |
| `frontend/app/(dashboard)/settings/layout.tsx` | Modify | Add to navigation (`navItems` array) |
| `backend/routes/api.php` | Modify | Add settings endpoints |
| `backend/app/Http/Controllers/Api/SettingController.php` | Modify | Add get/update methods |

## Reference Implementations

- **Settings page**: `frontend/app/(dashboard)/user/preferences/page.tsx`
- **Settings controller**: `backend/app/Http/Controllers/Api/SettingController.php`
- **For system-wide config (not user-scoped)**: use [add-config-page recipe](add-config-page.md) instead

## Backend Pattern

User-scoped settings use the `Setting` model with `user_id`, `group`, `key`, `value`:

```php
// GET - read settings
$settings = Setting::where('user_id', $request->user()->id)
    ->where('group', 'example')
    ->pluck('value', 'key')->toArray();

// PUT - save settings
foreach ($validated as $key => $value) {
    Setting::updateOrCreate(
        ['user_id' => $request->user()->id, 'group' => 'example', 'key' => $key],
        ['value' => $value]
    );
}
```

## Frontend Pattern

Follow the same form patterns as config pages:
- Use react-hook-form + Zod with `mode: "onBlur"`
- Load with `reset()`, save with `api.put()`
- Use `<SaveButton isDirty={isDirty} isSaving={isSaving} />`

See [add-config-page recipe](add-config-page.md) for the full skeleton and critical rules.

## Checklist

- [ ] Page created at `frontend/app/(dashboard)/settings/{name}/page.tsx`
- [ ] Navigation updated in `settings/layout.tsx`
- [ ] Loading state handled (use `<SettingsPageSkeleton />`)
- [ ] Error handling with `toast`
- [ ] Backend endpoint with user scoping
- [ ] Form validation on both frontend and backend
- [ ] Responsive design verified
