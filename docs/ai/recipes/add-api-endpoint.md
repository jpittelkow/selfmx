# Recipe: Add API Endpoint

Add a new REST API endpoint.

## Files to Create/Modify

| File | Action | Purpose |
|------|--------|---------|
| `backend/routes/api.php` | Modify | Add route definition |
| `backend/app/Http/Controllers/Api/{Name}Controller.php` | Create | Handle requests |
| `backend/app/Http/Requests/{Name}Request.php` | Create | Validate input (if POST/PUT) |
| `backend/app/Http/Resources/{Name}Resource.php` | Create | Format response (optional) |
| `backend/app/Models/{Name}.php` | Create | Data model (if new entity) |
| `backend/database/migrations/` | Create | Database table (if new entity) |

## Reference Implementations

- **CRUD controller**: `backend/app/Http/Controllers/Api/WebhookController.php`
- **Settings controller**: `backend/app/Http/Controllers/Api/StorageSettingController.php`
- **Admin controller**: `backend/app/Http/Controllers/Api/UserController.php`

## Route Patterns

```php
// backend/routes/api.php
use App\Http\Controllers\Api\ExampleController;

Route::middleware('auth:sanctum')->group(function () {
    // Full resource (index, store, show, update, destroy)
    Route::apiResource('examples', ExampleController::class);

    // Or individual routes
    Route::get('/examples', [ExampleController::class, 'index']);
    Route::post('/examples', [ExampleController::class, 'store']);

    // Admin-only
    Route::middleware('can:admin')->group(function () {
        Route::get('/admin/examples', [AdminExampleController::class, 'index']);
    });
});
```

## Controller Skeleton

Use `ApiResponseTrait` for consistent responses and `config('app.pagination.default')` for pagination.

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Example;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExampleController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request): JsonResponse
    {
        $examples = Example::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', config('app.pagination.default')));

        return $this->dataResponse([
            'data' => ExampleResource::collection($examples),
            'meta' => [
                'current_page' => $examples->currentPage(),
                'last_page' => $examples->lastPage(),
                'total' => $examples->total(),
            ],
        ]);
    }

    public function store(StoreExampleRequest $request): JsonResponse
    {
        $example = Example::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return $this->createdResponse('Example created.', ['data' => new ExampleResource($example)]);
    }

    public function show(Request $request, Example $example): JsonResponse
    {
        $this->authorize('view', $example);
        return $this->dataResponse(['data' => new ExampleResource($example)]);
    }

    public function update(UpdateExampleRequest $request, Example $example): JsonResponse
    {
        $this->authorize('update', $example);
        $example->update($request->validated());
        return $this->successResponse('Example updated.', ['data' => new ExampleResource($example)]);
    }

    public function destroy(Request $request, Example $example): JsonResponse
    {
        $this->authorize('delete', $example);
        $example->delete();
        return $this->deleteResponse('Example deleted.');
    }
}
```

## Migration Skeleton

```php
Schema::create('examples', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->text('description')->nullable();
    $table->json('config')->nullable();
    $table->timestamps();
    $table->index('user_id');
});
```

## Run & Verify

```bash
docker-compose exec app php /var/www/html/backend/artisan migrate
docker-compose exec app php /var/www/html/backend/artisan route:list --path=examples
```

## Checklist

- [ ] Route added to `backend/routes/api.php`
- [ ] Controller uses `ApiResponseTrait` and proper namespace
- [ ] FormRequest(s) created for validation
- [ ] Model + migration created (if new entity)
- [ ] User scoping applied (`user_id` checks)
- [ ] Auth middleware applied (`auth:sanctum`)
- [ ] Foreign keys have indexes
- [ ] Routes verified with `route:list`

For admin endpoints that modify/delete users, use `AdminAuthorizationTrait`. See [add-admin-protected-action recipe](add-admin-protected-action.md).
