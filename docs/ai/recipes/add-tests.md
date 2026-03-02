# Recipe: Add Tests

Step-by-step guide to add tests for a feature.

## Test Types Overview

| Type | Framework | Location | Purpose |
|------|-----------|----------|---------|
| Unit (PHP) | Pest | `backend/tests/Unit/` | Service/model logic |
| Feature (PHP) | Pest | `backend/tests/Feature/` | API endpoint tests |
| Unit (JS) | Vitest | `frontend/__tests__/` | Component/utility tests |
| E2E | Playwright | `e2e/` | Full user flows |

## Files to Create

| Test Type | File Pattern |
|-----------|--------------|
| Backend Feature | `backend/tests/Feature/{Feature}Test.php` |
| Backend Unit | `backend/tests/Unit/{Service}Test.php` |
| Frontend Unit | `frontend/__tests__/{component}.test.tsx` |
| E2E | `e2e/{feature}.spec.ts` |

---

## Backend API Tests (Pest)

### Step 1: Create Test File

```php
<?php
// backend/tests/Feature/ExampleTest.php

use App\Models\User;
use App\Models\Example;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('GET /api/examples', function () {
    it('requires authentication', function () {
        $this->getJson('/api/examples')
            ->assertUnauthorized();
    });

    it('lists examples for authenticated user', function () {
        Example::factory()->count(3)->create(['user_id' => $this->user->id]);
        Example::factory()->count(2)->create(); // Other user's examples

        $this->actingAs($this->user)
            ->getJson('/api/examples')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'created_at'],
                ],
                'meta' => ['current_page', 'last_page', 'total'],
            ]);
    });

    it('does not return other users examples', function () {
        $otherUser = User::factory()->create();
        Example::factory()->count(3)->create(['user_id' => $otherUser->id]);

        $this->actingAs($this->user)
            ->getJson('/api/examples')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

describe('POST /api/examples', function () {
    it('creates an example', function () {
        $this->actingAs($this->user)
            ->postJson('/api/examples', [
                'name' => 'Test Example',
                'type' => 'typeA',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Test Example')
            ->assertJsonPath('message', 'Example created successfully.');

        $this->assertDatabaseHas('examples', [
            'user_id' => $this->user->id,
            'name' => 'Test Example',
            'type' => 'typeA',
        ]);
    });

    it('validates required fields', function () {
        $this->actingAs($this->user)
            ->postJson('/api/examples', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'type']);
    });

    it('validates type enum', function () {
        $this->actingAs($this->user)
            ->postJson('/api/examples', [
                'name' => 'Test',
                'type' => 'invalid_type',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    });
});

describe('GET /api/examples/{id}', function () {
    it('shows an example', function () {
        $example = Example::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->getJson("/api/examples/{$example->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $example->id);
    });

    it('returns 404 for other users example', function () {
        $otherUser = User::factory()->create();
        $example = Example::factory()->create(['user_id' => $otherUser->id]);

        $this->actingAs($this->user)
            ->getJson("/api/examples/{$example->id}")
            ->assertNotFound();
    });
});

describe('PUT /api/examples/{id}', function () {
    it('updates an example', function () {
        $example = Example::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->putJson("/api/examples/{$example->id}", [
                'name' => 'Updated Name',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('examples', [
            'id' => $example->id,
            'name' => 'Updated Name',
        ]);
    });

    it('cannot update other users example', function () {
        $otherUser = User::factory()->create();
        $example = Example::factory()->create(['user_id' => $otherUser->id]);

        $this->actingAs($this->user)
            ->putJson("/api/examples/{$example->id}", ['name' => 'Hacked'])
            ->assertNotFound();
    });
});

describe('DELETE /api/examples/{id}', function () {
    it('deletes an example', function () {
        $example = Example::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->deleteJson("/api/examples/{$example->id}")
            ->assertOk();

        $this->assertDatabaseMissing('examples', ['id' => $example->id]);
    });

    it('cannot delete other users example', function () {
        $otherUser = User::factory()->create();
        $example = Example::factory()->create(['user_id' => $otherUser->id]);

        $this->actingAs($this->user)
            ->deleteJson("/api/examples/{$example->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('examples', ['id' => $example->id]);
    });
});
```

### Step 2: Create Factory (if needed)

```php
<?php
// backend/database/factories/ExampleFactory.php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExampleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->sentence(3),
            'type' => fake()->randomElement(['typeA', 'typeB', 'typeC']),
            'description' => fake()->optional()->paragraph(),
            'config' => [],
        ];
    }
}
```

### Step 3: Run Tests

```bash
# Inside Docker container
docker exec selfmx-dev bash -c "cd /var/www/html/backend && php artisan test"

# Run specific test file
docker exec selfmx-dev bash -c "cd /var/www/html/backend && php artisan test --filter=ExampleTest"

# Run with coverage
docker exec selfmx-dev bash -c "cd /var/www/html/backend && php artisan test --coverage"
```

---

## Backend Service Tests (Unit)

### Step 1: Create Unit Test

```php
<?php
// backend/tests/Unit/ExampleServiceTest.php

use App\Services\Example\ExampleService;
use App\Models\Example;
use App\Models\User;

beforeEach(function () {
    $this->service = new ExampleService();
    $this->user = User::factory()->create();
});

it('processes example correctly', function () {
    $example = Example::factory()->create(['user_id' => $this->user->id]);

    $result = $this->service->process($example, ['option' => 'value']);

    expect($result->success)->toBeTrue();
    expect($result->data)->toBeArray();
});

it('handles invalid input gracefully', function () {
    $example = Example::factory()->create([
        'user_id' => $this->user->id,
        'config' => ['invalid' => true],
    ]);

    $result = $this->service->process($example);

    expect($result->success)->toBeFalse();
    expect($result->error)->toBeString();
});
```

---

## Frontend Component Tests (Vitest)

### Step 1: Create Test File

```tsx
// frontend/__tests__/components/example-card.test.tsx

import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { ExampleCard } from '@/components/example-card';

describe('ExampleCard', () => {
  it('renders title and description', () => {
    render(
      <ExampleCard
        title="Test Title"
        description="Test Description"
      />
    );

    expect(screen.getByText('Test Title')).toBeInTheDocument();
    expect(screen.getByText('Test Description')).toBeInTheDocument();
  });

  it('calls onClick when clicked', () => {
    const handleClick = vi.fn();

    render(
      <ExampleCard
        title="Test"
        description="Description"
        onClick={handleClick}
      />
    );

    fireEvent.click(screen.getByRole('button'));
    expect(handleClick).toHaveBeenCalledTimes(1);
  });

  it('shows loading state', () => {
    render(
      <ExampleCard
        title="Test"
        description="Description"
        loading
      />
    );

    expect(screen.getByRole('status')).toBeInTheDocument();
  });

  it('handles missing optional props', () => {
    render(<ExampleCard title="Only Title" />);

    expect(screen.getByText('Only Title')).toBeInTheDocument();
    expect(screen.queryByText('undefined')).not.toBeInTheDocument();
  });
});
```

### Step 2: Run Frontend Tests

```bash
# From frontend directory
cd frontend

# Run tests
npm test

# Run with coverage
npm run test:coverage

# Watch mode during development
npm run test:watch
```

---

## E2E Tests (Playwright)

### Step 1: Create Test File

```typescript
// e2e/examples.spec.ts

import { test, expect } from '@playwright/test';

// Helper to login (adjust based on your auth flow)
async function loginAsUser(page: any) {
  await page.goto('/login');
  await page.fill('[name="email"]', 'test@example.com');
  await page.fill('[name="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForURL('/dashboard');
}

test.describe('Examples Feature', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsUser(page);
  });

  test('can view examples list', async ({ page }) => {
    await page.goto('/examples');

    await expect(page.getByRole('heading', { name: 'Examples' })).toBeVisible();
  });

  test('can create an example', async ({ page }) => {
    await page.goto('/examples');

    // Click create button
    await page.getByRole('button', { name: 'Create Example' }).click();

    // Fill form
    await page.getByLabel('Name').fill('Test Example');
    await page.getByLabel('Type').selectOption('typeA');

    // Submit
    await page.getByRole('button', { name: 'Save' }).click();

    // Verify success
    await expect(page.getByText('Example created successfully')).toBeVisible();
    await expect(page.getByText('Test Example')).toBeVisible();
  });

  test('can edit an example', async ({ page }) => {
    await page.goto('/examples');

    // Click edit on first example
    await page.getByRole('button', { name: 'Edit' }).first().click();

    // Update name
    await page.getByLabel('Name').clear();
    await page.getByLabel('Name').fill('Updated Example');
    await page.getByRole('button', { name: 'Save' }).click();

    // Verify success
    await expect(page.getByText('Example updated successfully')).toBeVisible();
    await expect(page.getByText('Updated Example')).toBeVisible();
  });

  test('can delete an example', async ({ page }) => {
    await page.goto('/examples');

    // Get count before
    const countBefore = await page.locator('.example-card').count();

    // Click delete on first example
    await page.getByRole('button', { name: 'Delete' }).first().click();

    // Confirm deletion
    await page.getByRole('button', { name: 'Confirm' }).click();

    // Verify success
    await expect(page.getByText('Example deleted successfully')).toBeVisible();

    // Verify count decreased
    const countAfter = await page.locator('.example-card').count();
    expect(countAfter).toBe(countBefore - 1);
  });

  test('shows validation errors', async ({ page }) => {
    await page.goto('/examples');
    await page.getByRole('button', { name: 'Create Example' }).click();

    // Submit empty form
    await page.getByRole('button', { name: 'Save' }).click();

    // Verify validation errors shown
    await expect(page.getByText('Name is required')).toBeVisible();
  });
});
```

### Step 2: Run E2E Tests

```bash
# Run all E2E tests
npx playwright test

# Run specific test file
npx playwright test e2e/examples.spec.ts

# Run with UI (debugging)
npx playwright test --ui

# Run headed (see browser)
npx playwright test --headed

# Generate test from recording
npx playwright codegen http://localhost:8080
```

---

## Test Checklist

### Backend API Tests
- [ ] Authentication required (401 for unauthenticated)
- [ ] List endpoint returns only user's data
- [ ] Create endpoint validates input
- [ ] Create endpoint stores with correct user_id
- [ ] Show endpoint returns 404 for other user's data
- [ ] Update endpoint validates input
- [ ] Update endpoint returns 404 for other user's data
- [ ] Delete endpoint returns 404 for other user's data
- [ ] Factory created for the model

### Frontend Tests
- [ ] Component renders with required props
- [ ] Component handles optional props correctly
- [ ] Event handlers are called
- [ ] Loading states render correctly
- [ ] Error states render correctly

### E2E Tests
- [ ] Can view the list page
- [ ] Can create new items
- [ ] Can edit existing items
- [ ] Can delete items
- [ ] Validation errors display correctly
- [ ] Success messages display correctly

---

## Running All Tests

```bash
# Backend tests
docker exec selfmx-dev bash -c "cd /var/www/html/backend && php artisan test"

# Frontend tests
cd frontend && npm test

# E2E tests
npx playwright test

# All tests (CI script)
./scripts/test-all.sh
```
