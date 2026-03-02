# selfmx Developer Guide

This guide is for developers who want to contribute to selfmx or extend its functionality.

## Table of Contents

1. [Development Setup](#development-setup)
2. [Project Structure](#project-structure)
3. [Backend Development](#backend-development)
4. [Frontend Development](#frontend-development)
5. [Testing](#testing)
6. [Adding New Features](#adding-new-features)
7. [Contributing](#contributing)

---

## Development Setup

### Prerequisites

- **PHP 8.3+** with extensions: pdo_sqlite, mbstring, xml, curl, zip
- **Composer 2.x**
- **Node.js 20+**
- **npm 10+**
- **Docker** (optional, for containerized development)

### Local Development

#### Backend Setup

```bash
cd backend

# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Create SQLite database
touch database/database.sqlite

# Run migrations
php artisan migrate

# Start development server
php artisan serve
# Backend available at http://localhost:8000
```

#### Frontend Setup

```bash
cd frontend

# Install dependencies
npm install

# Start development server
npm run dev
# Frontend available at http://localhost:3000
```

#### Docker Development

```bash
# Start all services
docker-compose up -d

# View logs
docker-compose logs -f

# Stop services
docker-compose down
```

### IDE Setup

#### VS Code Extensions
- PHP Intelephense
- Laravel Blade Snippets
- ESLint
- Prettier
- Tailwind CSS IntelliSense

#### PHPStorm
- Enable Laravel plugin
- Configure PHP interpreter
- Set up Laravel Pint for formatting

---

## Project Structure

```
selfmx/
├── backend/                    # Laravel 11 API
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/    # API Controllers
│   │   │   └── Middleware/     # Custom middleware
│   │   ├── Models/             # Eloquent models
│   │   ├── Policies/           # Authorization policies
│   │   ├── Providers/          # Service providers
│   │   └── Services/           # Business logic
│   │       ├── Auth/           # Authentication services
│   │       ├── Backup/         # Backup/restore services
│   │       ├── LLM/            # LLM orchestrator
│   │       │   └── Providers/  # LLM provider implementations
│   │       └── Notifications/  # Notification system
│   │           └── Channels/   # Notification channels
│   ├── config/                 # Configuration files
│   ├── database/
│   │   └── migrations/         # Database migrations
│   ├── routes/
│   │   ├── api.php            # API routes
│   │   └── web.php            # Web routes (minimal)
│   └── tests/                  # PHP tests
├── frontend/                   # Next.js 16+
│   ├── app/                    # App Router pages
│   │   ├── (auth)/            # Public auth pages
│   │   └── (dashboard)/       # Protected pages
│   ├── components/
│   │   ├── auth/              # Auth-specific components
│   │   └── ui/                # shadcn/ui components
│   └── lib/                    # Utilities and API client
├── docker/                     # Docker configuration
├── docs/                       # Documentation
│   ├── adr/                   # Architecture Decision Records
│   ├── api/                   # API documentation
│   ├── dev/                   # Developer guide (this file)
│   └── user/                  # User guide
└── .github/
    └── workflows/              # CI/CD pipelines
```

---

## Backend Development

### Architecture Overview

selfmx follows a service-oriented architecture:

```
Request → Controller → Service → Model → Database
                ↓
            Response
```

### Creating a Controller

```php
// app/Http/Controllers/Api/ExampleController.php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExampleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        // Create resource...

        return response()->json(['data' => $resource], 201);
    }
}
```

### Creating a Service

```php
// app/Services/ExampleService.php
<?php

namespace App\Services;

class ExampleService
{
    public function process(array $data): array
    {
        // Business logic here
        return $result;
    }
}
```

### Adding Routes

```php
// routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/examples', [ExampleController::class, 'index']);
    Route::post('/examples', [ExampleController::class, 'store']);
});
```

### Creating a Migration

```bash
php artisan make:migration create_examples_table
```

```php
// database/migrations/xxxx_create_examples_table.php
public function up(): void
{
    Schema::create('examples', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('name');
        $table->json('data')->nullable();
        $table->timestamps();

        $table->index('user_id');
    });
}
```

### Adding an LLM Provider

1. Create the provider class:

```php
// app/Services/LLM/Providers/NewProvider.php
<?php

namespace App\Services\LLM\Providers;

use App\Services\LLM\LLMProviderInterface;

class NewProvider implements LLMProviderInterface
{
    public function query(string $prompt, array $options = []): array
    {
        // Implement API call
    }

    public function queryWithVision(string $prompt, array $images, array $options = []): array
    {
        // Implement vision API call
    }

    public function isConfigured(): bool
    {
        return !empty(config('llm.providers.newprovider.api_key'));
    }

    public function getIdentifier(): string
    {
        return 'newprovider';
    }

    public function getName(): string
    {
        return 'New Provider';
    }

    public function supportsVision(): bool
    {
        return true;
    }

    public function getModels(): array
    {
        return ['model-1', 'model-2'];
    }
}
```

2. Register in config:

```php
// config/llm.php
'providers' => [
    'newprovider' => [
        'api_key' => env('NEWPROVIDER_API_KEY'),
        'default_model' => 'model-1',
    ],
],
```

3. Register in orchestrator:

```php
// app/Services/LLM/LLMOrchestrator.php
protected function registerProviders(): void
{
    $this->providers['newprovider'] = new NewProvider();
}
```

### Adding a Notification Channel

1. Create the channel class:

```php
// app/Services/Notifications/Channels/NewChannel.php
<?php

namespace App\Services\Notifications\Channels;

class NewChannel implements ChannelInterface
{
    public function send($notifiable, $notification): bool
    {
        // Implement delivery
    }

    public function isConfigured(): bool
    {
        return !empty(config('notifications.channels.newchannel.key'));
    }

    public function getIdentifier(): string
    {
        return 'newchannel';
    }

    public function getName(): string
    {
        return 'New Channel';
    }
}
```

2. Register in orchestrator.

---

## Frontend Development

### Component Structure

```
components/
├── ui/                 # Base UI components (shadcn/ui)
│   ├── button.tsx
│   ├── input.tsx
│   └── ...
├── auth/              # Auth-specific components
│   ├── login-form.tsx
│   └── ...
└── feature/           # Feature-specific components
    └── ...
```

### Creating a Component

```tsx
// components/feature/example-card.tsx
'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

interface ExampleCardProps {
  title: string;
  description: string;
}

export function ExampleCard({ title, description }: ExampleCardProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent>
        <p>{description}</p>
      </CardContent>
    </Card>
  );
}
```

### Creating a Page

```tsx
// app/(dashboard)/examples/page.tsx
import { ExampleCard } from '@/components/feature/example-card';

export default async function ExamplesPage() {
  // Fetch data server-side
  const examples = await fetchExamples();

  return (
    <div className="container mx-auto py-8">
      <h1 className="text-2xl font-bold mb-6">Examples</h1>
      <div className="grid gap-4">
        {examples.map((example) => (
          <ExampleCard key={example.id} {...example} />
        ))}
      </div>
    </div>
  );
}
```

### API Client Usage

```tsx
// lib/api.ts
import { api } from '@/lib/api';

// In a component or page
const examples = await api.get('/examples');
const newExample = await api.post('/examples', { name: 'Test' });
```

### State Management with Zustand

```tsx
// lib/stores/example-store.ts
import { create } from 'zustand';

interface ExampleState {
  items: Example[];
  setItems: (items: Example[]) => void;
  addItem: (item: Example) => void;
}

export const useExampleStore = create<ExampleState>((set) => ({
  items: [],
  setItems: (items) => set({ items }),
  addItem: (item) => set((state) => ({ items: [...state.items, item] })),
}));
```

---

## Testing

### Backend Tests (Pest PHP)

```bash
cd backend

# Run all tests
php artisan test

# Run specific test file
php artisan test tests/Feature/AuthTest.php

# Run with coverage
php artisan test --coverage
```

#### Writing Tests

```php
// tests/Feature/ExampleTest.php
<?php

use App\Models\User;

it('lists examples for authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/examples');

    $response->assertOk()
        ->assertJsonStructure(['data' => []]);
});
```

### Frontend Tests (Vitest)

```bash
cd frontend

# Run tests
npm test

# Run with coverage
npm run test:coverage

# Watch mode
npm run test:watch
```

#### Writing Tests

```tsx
// components/__tests__/example-card.test.tsx
import { render, screen } from '@testing-library/react';
import { ExampleCard } from '../feature/example-card';

describe('ExampleCard', () => {
  it('renders title and description', () => {
    render(<ExampleCard title="Test" description="Description" />);

    expect(screen.getByText('Test')).toBeInTheDocument();
    expect(screen.getByText('Description')).toBeInTheDocument();
  });
});
```

### E2E Tests (Playwright)

```bash
# Run E2E tests
npx playwright test

# Run with UI
npx playwright test --ui
```

---

## Adding New Features

### Checklist

1. [ ] Create ADR for significant decisions
2. [ ] Add database migrations (if needed)
3. [ ] Create backend service and controller
4. [ ] Add API routes
5. [ ] Create frontend components/pages
6. [ ] Write tests (unit, feature, E2E)
7. [ ] Update documentation
8. [ ] Update CHANGELOG

### Feature Branch Workflow

```bash
# Create feature branch
git checkout -b feature/new-feature

# Make changes, commit often
git add .
git commit -m "feat: add new feature"

# Push and create PR
git push -u origin feature/new-feature
```

---

## Contributing

### Code Style

#### PHP
- Follow PSR-12
- Use Laravel Pint for formatting: `./vendor/bin/pint`
- Use strict types: `declare(strict_types=1);`

#### TypeScript/JavaScript
- Use ESLint + Prettier
- Format on save
- Use TypeScript strict mode

### Commit Messages

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add new notification channel
fix: resolve 2FA verification issue
docs: update API documentation
refactor: simplify LLM orchestrator
test: add auth flow E2E tests
chore: update dependencies
```

### Pull Request Process

1. Create feature branch from `main`
2. Make changes with tests
3. Ensure CI passes
4. Request review
5. Address feedback
6. Squash and merge

### Architecture Decision Records

For significant changes, create an ADR:

```bash
# Create new ADR
cp docs/adr/000-template.md docs/adr/011-your-decision.md
# Edit the file with your decision
```

---

## Resources

- [Laravel Documentation](https://laravel.com/docs)
- [Next.js Documentation](https://nextjs.org/docs)
- [shadcn/ui Components](https://ui.shadcn.com)
- [Tailwind CSS](https://tailwindcss.com/docs)
- [Pest PHP](https://pestphp.com)
- [Playwright](https://playwright.dev)
