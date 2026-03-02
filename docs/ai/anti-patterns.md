# Anti-Patterns

What NOT to do when developing on Sourdough.

## Backend Anti-Patterns

### Don't: Put Business Logic in Controllers

```php
// BAD - logic in controller
public function store(Request $request): JsonResponse
{
    $data = $request->all();
    // 50 lines of business logic, API calls, calculations...
    $result = $this->processComplexLogic($data);
    return response()->json(['data' => $result], 201);
}

// GOOD - delegate to service
public function store(StoreExampleRequest $request): JsonResponse
{
    $result = $this->exampleService->create(
        $request->validated(),
        $request->user()
    );

    return response()->json([
        'data' => new ExampleResource($result),
        'message' => 'Example created successfully.',
    ], 201);
}
```

### Don't: Forget User Scoping

```php
// BAD - returns ALL records (security issue!)
$examples = Example::all();
$examples = Example::where('type', 'active')->get();

// GOOD - scoped to authenticated user
$examples = Example::where('user_id', $request->user()->id)->get();
$examples = Example::where('user_id', $request->user()->id)
    ->where('type', 'active')
    ->get();
```

### Don't: Skip Form Request Validation

```php
// BAD - manual validation in controller
public function store(Request $request): JsonResponse
{
    $validated = $request->validate([
        'name' => 'required|string|max:255',
    ]);
    // ...
}

// GOOD - dedicated FormRequest class
public function store(StoreExampleRequest $request): JsonResponse
{
    // $request->validated() is already clean and validated
    $example = Example::create([
        ...$request->validated(),
        'user_id' => $request->user()->id,
    ]);
    // ...
}
```

### Don't: Return Inconsistent Response Formats

```php
// BAD - inconsistent formats
return response()->json($example);                    // Just the model
return response()->json(['example' => $example]);    // Different key
return response()->json(['success' => true, 'item' => $example]);

// GOOD - consistent format
return response()->json([
    'data' => new ExampleResource($example),
    'message' => 'Example created successfully.',
], 201);
```

### Don't: Duplicate "Last Admin" Checks

```php
// BAD - duplicated logic (UserController, ProfileController, GroupController, etc.)
if ($user->inGroup('admin') && User::whereHas('groups', fn ($q) => $q->where('slug', 'admin'))->count() === 1) {
    return response()->json(['message' => 'Cannot delete the last admin account'], 400);
}

// GOOD - use AdminAuthorizationTrait
use App\Http\Traits\AdminAuthorizationTrait;

if ($error = $this->ensureNotLastAdmin($user, 'delete')) {
    return $error;
}
```

### Don't: Hardcode Pagination Defaults

```php
// BAD - magic numbers, inconsistent across controllers
$perPage = $request->input('per_page', 15);
$perPage = $request->input('per_page', 20);

// GOOD - use config
$perPage = $request->input('per_page', config('app.pagination.default'));
// Audit logs: config('app.pagination.audit_log')
```

### Don't: Double-hash User Passwords

The `User` model uses the `hashed` cast. Pass plaintext; the cast hashes automatically.

```php
// BAD - double-hash when User has hashed cast
User::create(['password' => Hash::make($validated['password'])]);
$user->update(['password' => Hash::make($validated['password'])]);

// GOOD - plaintext, cast hashes
User::create(['password' => $validated['password']]);
$user->update(['password' => $validated['password']]);
```

### Don't: Forget to Add Indexes on Foreign Keys

```php
// BAD - foreign key without index (slow queries)
Schema::create('examples', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id');
    $table->timestamps();
});

// GOOD - constrained foreign key with index
Schema::create('examples', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->timestamps();

    $table->index('user_id');
});
```

### Don't: Use Service Classes in Migrations

Migrations run during application bootstrap when the service container may not be fully initialized. Using services or Eloquent models in migrations can fail silently or throw cryptic errors.

```php
// BAD - service container may not be ready, dependencies may fail
public function up(): void
{
    app(GroupService::class)->ensureDefaultGroupsExist();  // Can fail!
    
    // Or using Eloquent models with complex boot methods
    UserGroup::create(['name' => 'Admins', 'slug' => 'admin']);
}

// GOOD - self-contained with direct SQL
public function up(): void
{
    // Check if already exists (idempotent)
    if (DB::table('user_groups')->where('slug', 'admin')->exists()) {
        return;
    }
    
    $now = now();
    DB::table('user_groups')->insert([
        'name' => 'Administrators',
        'slug' => 'admin',
        'is_system' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}
```

**Why this matters:**
- Service constructors may have unresolvable dependencies during migrations
- Eloquent model boot methods may fail if tables don't exist yet
- Service providers may not have registered
- Failures may be silent, causing subsequent code to fail with confusing errors

**Best practice:** Keep migrations self-contained using `DB::table()` queries. If you need business logic, inline it in the migration or create a private method within the migration class.

### Don't: Create Providers/Channels Without Implementing Interface

```php
// BAD - provider without interface (won't work with orchestrator)
class NewLLMProvider
{
    public function ask(string $question): string { /* ... */ }
}

// GOOD - implements the interface
class NewLLMProvider implements LLMProviderInterface
{
    public function query(string $prompt, array $options = []): array { /* ... */ }
    public function isConfigured(): bool { /* ... */ }
    public function getIdentifier(): string { /* ... */ }
    // ... all interface methods
}
```

### Don't: Create Widgets Without Loading/Error States

```tsx
// BAD - no loading or error handling
function StatsWidget() {
  const { data } = useQuery({ queryKey: ["stats"], queryFn: fetchStats });
  return <div>{data?.total}</div>;
}

// GOOD - handle loading and error states
function StatsWidget() {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ["stats"],
    queryFn: fetchStats,
  });

  if (isLoading) return <WidgetSkeleton />;
  if (error) {
    return (
      <Card>
        <CardContent className="flex flex-col items-center py-4">
          <AlertCircle className="h-6 w-6 text-destructive" />
          <p className="text-sm text-muted-foreground">Failed to load</p>
          <Button variant="outline" size="sm" onClick={() => refetch()}>
            Retry
          </Button>
        </CardContent>
      </Card>
    );
  }

  return <div>{data?.total}</div>;
}
```

### Don't: Forget Permission Checks for Admin Widgets

```tsx
// BAD - admin-only widget shown to all users
function Dashboard() {
  return (
    <div className="grid gap-4">
      <WelcomeWidget />
      <SystemHealthWidget /> {/* Should be admin-only! */}
    </div>
  );
}

// GOOD - use permission checks for sensitive widgets
function Dashboard() {
  const { hasPermission } = usePermission();
  return (
    <div className="grid gap-4">
      <WelcomeWidget />
      {hasPermission("admin") && <SystemHealthWidget />}
      {hasPermission("audit.view") && <RecentActivityWidget />}
    </div>
  );
}
```

### Don't: Create User-Configurable Widget Infrastructure

Widgets are static and developer-defined. Do not add:

- Database tables for widgets or user widget layout
- Backend registries or handler interfaces for widget types
- Frontend modals for adding/removing widgets or drag-and-drop layout
- Permissions for "widgets.view" or "widgets.configure"

To add a new widget, create a React component in `frontend/components/dashboard/widgets/`, add it to the dashboard page, and optionally add a data endpoint in `DashboardController`. See [Recipe: Add Dashboard Widget](recipes/add-dashboard-widget.md).

## Frontend Anti-Patterns

### Don't: Duplicate Logic Across Pages

```tsx
// BAD - Logo rendering duplicated in multiple pages
// login/page.tsx
export default function LoginPage() {
  const [logoError, setLogoError] = useState(false);
  const logoUrl = useAppConfig().logoUrl;
  
  return logoUrl && !logoError ? (
    <Image src={logoUrl} onError={() => setLogoError(true)} />
  ) : (
    <span className="font-bold text-xl">AppName</span>
  );
}

// register/page.tsx - SAME CODE DUPLICATED!
export default function RegisterPage() {
  const [logoError, setLogoError] = useState(false);
  // ... same logic repeated
}

// GOOD - Use shared component
// login/page.tsx
import { Logo } from '@/components/logo';

export default function LoginPage() {
  return <Logo variant="full" size="lg" />;
}

// register/page.tsx
import { Logo } from '@/components/logo';

export default function RegisterPage() {
  return <Logo variant="full" size="lg" />;
}
```

**Before writing any logic, ask:**
1. Does this functionality exist elsewhere in the codebase?
2. Could another page need this same functionality?
3. Should this be a shared component in `frontend/components/`?

### Don't: Duplicate Groups Fetch

```tsx
// BAD - fetching groups inline in multiple components
// user-group-picker.tsx
const [groups, setGroups] = useState([]);
useEffect(() => {
  api.get("/groups").then(res => setGroups(res.data?.data ?? []));
}, []);

// users/page.tsx - SAME FETCH DUPLICATED
const [groups, setGroups] = useState([]);
useEffect(() => {
  api.get("/groups").then(res => setGroups(res.data?.data ?? []));
}, []);
```

```tsx
// GOOD - use shared useGroups() hook
import { useGroups } from "@/lib/use-groups";

const { groups, isLoading, error } = useGroups();
```

Use **`useGroups()`** from `frontend/lib/use-groups.ts` for any component that needs the group list (filter dropdown, picker, etc.). See [Recipe: Assign user to groups](recipes/assign-user-to-groups.md).

### Don't: Create Page-Specific Utilities

```tsx
// BAD - Utility function defined inside a page
// settings/page.tsx
function formatDate(date: string) {
  return new Date(date).toLocaleDateString();
}

export default function SettingsPage() {
  return <span>{formatDate(user.created_at)}</span>;
}

// GOOD - Put utilities in shared location
// lib/utils.ts
export function formatDate(date: string) {
  return new Date(date).toLocaleDateString();
}

// settings/page.tsx
import { formatDate } from '@/lib/utils';

export default function SettingsPage() {
  return <span>{formatDate(user.created_at)}</span>;
}
```

### Don't: Fetch Without Error Handling

```tsx
// BAD - no error handling
useEffect(() => {
  const fetchData = async () => {
    const response = await api.get('/examples');
    setExamples(response.data);
  };
  fetchData();
}, []);

// GOOD - proper error handling and loading state
useEffect(() => {
  const fetchData = async () => {
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
  fetchData();
}, []);
```

### Don't: Forget Loading States

```tsx
// BAD - no loading indicator (confusing UX)
return <div>{examples.map(e => <Card key={e.id}>{e.name}</Card>)}</div>;

// GOOD - show loading state using shared component
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";

if (loading) {
  return <SettingsPageSkeleton />;
}

return <div>{examples.map(e => <Card key={e.id}>{e.name}</Card>)}</div>;
```

### Don't: Duplicate Loading Spinners

```tsx
// BAD - inline loading spinner (duplicated across pages)
if (isLoading) {
  return (
    <div className="flex items-center justify-center min-h-[400px]">
      <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
    </div>
  );
}

// GOOD - use the shared component
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";

if (isLoading) {
  return <SettingsPageSkeleton />;
}
```

### Don't: Duplicate Save Button Logic

```tsx
// BAD - save button logic repeated in every form
<Button type="submit" disabled={!isDirty || isSaving}>
  {isSaving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
  Save Changes
</Button>

// GOOD - use the shared component
import { SaveButton } from "@/components/ui/save-button";

<SaveButton isDirty={isDirty} isSaving={isSaving} />
```

### Don't: Hardcode API URLs

```tsx
// BAD - hardcoded URL (breaks in different environments)
const response = await fetch('http://localhost:8080/api/examples');
const response = await fetch('https://myapp.com/api/examples');

// GOOD - use relative path (Nginx proxies to backend)
const response = await api.get('/examples');
// or
const response = await fetch('/api/examples', {
  credentials: 'include',
});
```

### Don't: Forget `credentials: 'include'` for Auth

```tsx
// BAD - auth cookies not sent (401 errors)
const response = await fetch('/api/examples');

// GOOD - include cookies for Sanctum session auth
const response = await fetch('/api/examples', {
  credentials: 'include',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// BEST - use the api utility which handles this
import { api } from '@/lib/api';
const response = await api.get('/examples');
```

### Don't: Add shadcn/ui Components via npm

```bash
# BAD - shadcn components are not npm packages
npm install @shadcn/button

# GOOD - use the shadcn CLI (run from frontend/)
cd frontend
npx shadcn@latest add button

# Replace existing component
npx shadcn@latest add dialog --overwrite

# Config is in frontend/components.json; components live in frontend/components/ui/
```

### Don't: Create Components Without TypeScript Interfaces

```tsx
// BAD - no type safety
function ExampleCard({ title, description, onClick }) {
  return <Card>...</Card>;
}

// GOOD - typed props
interface ExampleCardProps {
  title: string;
  description?: string;
  onClick?: () => void;
}

function ExampleCard({ title, description, onClick }: ExampleCardProps) {
  return <Card>...</Card>;
}
```

### Don't: Misuse Help System Conventions

```tsx
// BAD - Real URL for help articles in search-pages.php (help opens via modal, not navigation)
'url' => '/help/welcome',

// GOOD - Use help: prefix so the search modal opens the help center
'url' => 'help:welcome',
```

```tsx
// BAD - Adding a help article but forgetting the search entry (users can't find it via Cmd+K)
// Only added to help-content.ts

// GOOD - Add both: help-content.ts AND search-pages.php
```

```tsx
// BAD - Duplicating help content between tooltip and article (maintenance burden)
// Tooltip: "Configures SMTP settings. See Email Configuration article for full guide."
// Article: Same paragraph repeated

// GOOD - Tooltips = 1–2 sentences max. Articles = full explanation with steps.
// Tooltip: "SMTP host for your mail server"
// Article: Full guide with setup steps, troubleshooting, etc.
```

See [Recipe: Add help article](recipes/add-help-article.md) and [Help System Pattern](patterns.md#help-system-pattern).

## Form Validation Anti-Patterns (react-hook-form + Zod)

### Don't: Make Fields Required by Default

```tsx
// BAD - URL validation fails on empty string (blocks save)
const schema = z.object({
  webhook_url: z.string().url().optional(),  // Empty string fails URL validation!
  api_key: z.string().min(1),  // Requires value even if optional
});

// GOOD - Allow empty strings explicitly for optional fields
const schema = z.object({
  webhook_url: z.string()
    .refine((val) => !val || val === "" || isValidUrl(val), {
      message: "Must be a valid URL",
    })
    .optional(),
  api_key: z.string().optional(),  // Empty is fine
});
```

### Don't: Use onChange Mode for Form Validation

```tsx
// BAD - Validates on every keystroke (blocks typing, shows errors too early)
const form = useForm({
  resolver: zodResolver(schema),
  mode: "onChange",
});

// GOOD - Validates when user leaves field
const form = useForm({
  resolver: zodResolver(schema),
  mode: "onBlur",
});
```

### Don't: Use setValue for Initial Data Load

```tsx
// BAD - isDirty won't work correctly
const fetchSettings = async () => {
  const response = await api.get("/settings");
  setValue("name", response.data.name);
  setValue("enabled", response.data.enabled);
  // Form thinks these are user changes, isDirty = true immediately!
};

// GOOD - Use reset() to establish clean state
const fetchSettings = async () => {
  const response = await api.get("/settings");
  reset({
    name: response.data.name || "",
    enabled: response.data.enabled || false,
  });
  // Form knows this is the baseline, isDirty = false
};
```

### Don't: Forget shouldDirty for Custom Inputs

```tsx
// BAD - Switch/ColorPicker changes don't enable save button
<Switch
  checked={watch("enabled")}
  onCheckedChange={(checked) => setValue("enabled", checked)}
/>

// GOOD - Mark the form as dirty when value changes
<Switch
  checked={watch("enabled")}
  onCheckedChange={(checked) => setValue("enabled", checked, { shouldDirty: true })}
/>
```

### Don't: Send Empty Strings to Backend

```tsx
// BAD - Backend may store empty string instead of null
const onSubmit = async (data) => {
  await api.put("/settings", data);  // { webhook_url: "" }
};

// GOOD - Convert empty strings to null
const onSubmit = async (data) => {
  await api.put("/settings", {
    ...data,
    webhook_url: data.webhook_url || null,
    api_key: data.api_key || null,
  });
};
```

## Database Anti-Patterns

### Don't: Use Raw SQL for Simple Queries

```php
// BAD - raw SQL (harder to maintain, potential SQL injection)
$examples = DB::select('SELECT * FROM examples WHERE user_id = ?', [$userId]);

// GOOD - Eloquent ORM
$examples = Example::where('user_id', $userId)->get();
```

### Don't: N+1 Query Problems

```php
// BAD - N+1 queries (1 query + N queries for relations)
$examples = Example::where('user_id', $userId)->get();
foreach ($examples as $example) {
    echo $example->user->name; // Query per iteration!
}

// GOOD - eager loading
$examples = Example::where('user_id', $userId)
    ->with('user')
    ->get();
```

When checking relations that may already be loaded (e.g. in traits), use the loaded relation instead of querying again:

```php
// BAD - inGroup() always queries, even if groups already eager-loaded
public function inGroup(string $slug): bool
{
    return $this->groups()->where('slug', $slug)->exists();
}

// GOOD - check relationLoaded first, then use collection or query
public function inGroup(string $slug): bool
{
    if (array_key_exists('groups', $this->relations) && $this->relationLoaded('groups')) {
        return $this->groups->contains('slug', $slug);
    }
    return $this->groups()->where('slug', $slug)->exists();
}
```

### Don't: Assume SQLite Features Work Everywhere

```php
// BAD - SQLite-specific syntax
DB::statement('PRAGMA foreign_keys = ON');

// GOOD - database-agnostic approach
// Use Laravel's schema builder and query builder
// Test with MySQL/PostgreSQL if targeting production
```

## Architecture Anti-Patterns

### Don't: Add Admin Routes Without Middleware

```php
// BAD - admin route without protection
Route::get('/admin/users', [AdminController::class, 'users']);
Route::get('/users/all', [AdminController::class, 'index']);

// GOOD - protected with auth AND admin middleware
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/users', [AdminController::class, 'users']);
});
```

### Don't: Store Secrets in Code

```php
// BAD - secrets in code
$apiKey = 'sk-1234567890abcdef';
Http::withHeaders(['Authorization' => 'Bearer sk-1234567890abcdef']);

// GOOD - use environment variables
$apiKey = config('services.example.api_key');
// In .env: EXAMPLE_API_KEY=sk-1234567890abcdef
// In config/services.php: 'example' => ['api_key' => env('EXAMPLE_API_KEY')]
```

### Don't: Mix User Settings with System Settings

```php
// BAD - using wrong model
$systemSetting = Setting::where('key', 'app_name')->first(); // User-scoped!

// GOOD - use the correct model
$systemSetting = SystemSetting::where('key', 'app_name')->first();

// User settings (has user_id)
$userSetting = Setting::where('user_id', $userId)
    ->where('key', 'theme')
    ->first();
```

### Don't: Use SystemSetting Directly for Schema-Backed Settings

Settings that are defined in `backend/config/settings-schema.php` (e.g. mail, future groups) should go through **SettingService** so env fallback and encryption apply. Using `SystemSetting::get()` / `SystemSetting::set()` directly for those keys bypasses cache, env fallback, and encryption.

```php
// BAD - no env fallback, no encryption, bypasses cache
$host = SystemSetting::get('smtp_host', null, 'mail');
SystemSetting::set('smtp_password', $password, 'mail', $user->id, false);

// GOOD - use SettingService for schema-backed groups
$host = $this->settingService->get('mail', 'smtp_host', '127.0.0.1');
$this->settingService->set('mail', 'smtp_password', $password, $user->id);
```

For groups **not** in settings-schema (e.g. notifications toggles, branding), `SystemSetting` directly is still correct.

### Don't: Read SettingService Inside BackupService or Destinations

Backup configuration is injected into Laravel config at boot by ConfigServiceProvider (from the `backup` group in settings-schema). BackupService and destination classes should read only from `config('backup.*')`, not from SettingService. Reading SettingService inside those classes bypasses the injected config and can cause inconsistent or uncached values.

```php
// BAD - bypasses config injection, may not reflect DB values at boot
$disk = $this->settingService->get('backup', 'disk', 'backups');

// GOOD - read from config (injected at boot from DB)
$disk = config('backup.disk', 'backups');
```

See [Backup & Restore patterns](patterns.md#backup--restore-patterns) and [Backup documentation](../backup.md).

## Testing Anti-Patterns

### Don't: Test Without Authentication

```php
// BAD - test will fail (401)
it('lists examples', function () {
    $this->getJson('/api/examples')
        ->assertOk();
});

// GOOD - authenticate the request
it('lists examples', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/examples')
        ->assertOk();
});
```

### Don't: Skip Testing User Isolation

```php
// BAD - only tests happy path
it('returns examples', function () {
    $user = User::factory()->create();
    Example::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->getJson('/api/examples')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

// GOOD - also test user can't see other users' data
it('only returns current user examples', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Example::factory()->count(3)->create(['user_id' => $user->id]);
    Example::factory()->count(5)->create(['user_id' => $otherUser->id]);

    $this->actingAs($user)
        ->getJson('/api/examples')
        ->assertOk()
        ->assertJsonCount(3, 'data'); // Not 8!
});
```

## Responsive & Mobile Anti-Patterns

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

## Summary Checklist

Before submitting code, verify:

- [ ] **No duplicated logic** - Searched for existing components/utilities first
- [ ] **Centralized utilities** - Common functions (formatBytes, formatDate) in `frontend/lib/utils.ts`, not inline
- [ ] **Shared components used** - New reusable functionality placed in `frontend/components/` or `frontend/lib/`
- [ ] Business logic is in Services, not Controllers
- [ ] All queries are user-scoped where appropriate
- [ ] FormRequest classes used for validation
- [ ] Response format is consistent (`data`, `message`, `meta`)
- [ ] Last-admin checks use `AdminAuthorizationTrait`; pagination uses `config('app.pagination.default')`
- [ ] User password: plaintext when User has `hashed` cast; no `Hash::make()` in controllers
- [ ] Foreign keys have indexes
- [ ] Frontend has loading and error states
- [ ] Using `api` utility, not raw fetch
- [ ] No hardcoded URLs or secrets
- [ ] Admin routes have proper middleware
- [ ] Tests verify user isolation
- [ ] Form fields are optional by default (use `.optional()`, `mode: "onBlur"`, `reset()`)
- [ ] Custom inputs use `setValue(..., { shouldDirty: true })`
- [ ] **Mobile-first CSS** - Base styles for mobile, breakpoints for larger screens
- [ ] **Touch targets** - All interactive elements have minimum 44px touch targets
- [ ] **Tables** - Wrapped in `overflow-x-auto` or have card view alternative
- [ ] **Tested** - Verified at 320px, 375px, 768px, and 1024px+ widths
