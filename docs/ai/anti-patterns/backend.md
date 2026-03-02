# Backend Anti-Patterns

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

### Don't: Skip access logging for PHI routes

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

### Don't: Partially Inject Socialite Config in ConfigServiceProvider

When injecting SSO provider credentials from DB into `config('services.{provider}.*')`, you must inject **all three keys**: `client_id`, `client_secret`, and `redirect`. If you only inject credentials but leave `redirect` dependent on env vars, Socialite sends a null `redirect_uri` to the OAuth provider when configured via the admin UI, causing `400: invalid_request`.

```php
// BAD - only injects client_id and client_secret, redirect stays null
foreach ($providers as $provider) {
    config(['services.' . $provider . '.client_id' => $settings[...]]);
    config(['services.' . $provider . '.client_secret' => $settings[...]]);
    // Missing: redirect URI is never set!
}

// GOOD - also sets redirect URI from APP_URL
foreach ($providers as $provider) {
    config(['services.' . $provider . '.client_id' => $settings[...]]);
    config(['services.' . $provider . '.client_secret' => $settings[...]]);
    config(['services.' . $provider . '.redirect' => rtrim(config('app.url'), '/') . '/api/auth/callback/' . $provider]);
}
```

### Don't: Use Null-Coalescing on json_decode

`json_decode()` returns `null` for both the valid JSON literal `"null"` and for decode failures. The `??` operator treats `null` as "missing", so `json_decode($v) ?? $v` silently converts a legitimate `null` value back to the raw string `"null"`.

```php
// BAD - json_decode("null") returns PHP null, ?? falls back to raw string "null"
return json_decode($value, true) ?? $value;
// If $value is the string "null", this returns "null" (string) instead of null (PHP null)

// GOOD - use json_last_error() to distinguish valid decode from failure
$decoded = json_decode($value, true);
if (json_last_error() === JSON_ERROR_NONE) {
    return $decoded;  // Returns PHP null for "null", [] for "[]", false for "false", etc.
}
return $value;  // Only returns raw string when JSON was actually invalid
```

**Real impact:** This caused the branding API to return `"null"` (string) instead of JSON `null` for deleted logo URLs, making the frontend render `<img src="null">` — a broken image.

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
