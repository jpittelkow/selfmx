# ADR-010: Database Abstraction Strategy

## Status

Accepted

## Date

2026-01-24

## Context

selfmx needs to support multiple databases to serve different deployment scenarios:
- **Self-hosted individuals**: Want zero-config simplicity (SQLite)
- **Small teams**: May use existing MySQL/PostgreSQL
- **Enterprise**: Often require PostgreSQL or managed databases
- **Cloud-native**: Want Supabase or other DBaaS

We need to maintain a single codebase that works across all these databases without database-specific code.

## Decision

We will use **Laravel's database abstraction** with SQLite as the default, following strict database-agnostic coding practices.

### Supported Databases

| Database | Use Case | Configuration |
|----------|----------|---------------|
| SQLite | Default, self-hosted | Zero config |
| MySQL 8+ | Teams, existing infra | Connection string |
| PostgreSQL 14+ | Enterprise, Supabase | Connection string |
| Supabase | Cloud-hosted | PostgreSQL + API |

### Database Selection Flow

```
┌────────────────────────────────────────────────────────────┐
│                   Database Selection                        │
├────────────────────────────────────────────────────────────┤
│                                                             │
│  Is DB_CONNECTION set?                                      │
│         │                                                   │
│    ┌────┴────┐                                             │
│    │   No    │──► Use SQLite at /data/database.sqlite      │
│    └─────────┘                                             │
│    │   Yes   │                                             │
│    └────┬────┘                                             │
│         ▼                                                   │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ sqlite: Use DB_DATABASE path                         │   │
│  │ mysql: Use DB_HOST, DB_DATABASE, DB_USERNAME, etc.   │   │
│  │ pgsql: Use DB_HOST, DB_DATABASE, DB_USERNAME, etc.   │   │
│  │ supabase: Use SUPABASE_URL, SUPABASE_KEY             │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
└────────────────────────────────────────────────────────────┘
```

### Configuration

```php
// config/database.php
return [
    'default' => env('DB_CONNECTION', 'sqlite'),
    
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'selfmx'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict' => true,
        ],
        
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'selfmx'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'schema' => 'public',
        ],
    ],
];
```

### Database-Agnostic Coding Rules

#### DO:
```php
// Use Eloquent query builder
User::where('email', $email)->first();

// Use Laravel's schema builder
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('email')->unique();
    $table->json('settings')->nullable();
    $table->timestamps();
});

// Use Carbon for dates
$user->created_at->diffForHumans();

// Use database transactions
DB::transaction(function () {
    // Multiple operations
});
```

#### DON'T:
```php
// Don't use raw SQL with database-specific syntax
DB::raw('SELECT * FROM users WHERE email REGEXP ?');

// Don't use SQLite-specific JSON syntax
DB::raw("json_extract(settings, '$.theme')");

// Don't assume auto-increment behavior
// Different databases handle this differently

// Don't use database-specific functions
DB::raw('NOW()');  // Use Carbon::now() instead
```

### JSON Column Handling

JSON columns work differently across databases. Use Laravel's casting:

```php
// Model
class User extends Model
{
    protected $casts = [
        'settings' => 'array',
        'metadata' => 'object',
    ];
}

// Usage (works on all databases)
$user->settings = ['theme' => 'dark'];
$user->save();
```

For JSON queries, use Laravel's built-in methods:
```php
// This works across all databases
User::whereJsonContains('settings->roles', 'admin')->get();
User::whereJsonLength('settings->roles', '>', 0)->get();
```

### Migration Guidelines

1. **Always use Blueprint methods** - Never raw SQL in migrations
2. **Test on all databases** - CI runs migrations on SQLite, MySQL, PostgreSQL
3. **Avoid changing column types** - Some databases don't support this well
4. **Use nullable for optional columns** - SQLite handles defaults differently

```php
// Good migration
Schema::create('notifications', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('type');
    $table->text('body');
    $table->json('data')->nullable();
    $table->timestamp('read_at')->nullable();
    $table->timestamps();
    
    $table->index(['user_id', 'read_at']);
});
```

### SQLite Limitations

SQLite has some limitations to be aware of:

| Feature | SQLite | MySQL | PostgreSQL |
|---------|--------|-------|------------|
| Concurrent writes | Limited | Good | Excellent |
| ALTER TABLE | Limited | Good | Good |
| Full-text search | Basic | Good | Excellent |
| JSON functions | Basic | Good | Excellent |

For self-hosted single-user deployments, these limitations are acceptable.

### Database Migration Tool (Future)

For users wanting to migrate from SQLite to MySQL/PostgreSQL:

```bash
php artisan db:migrate-to --driver=pgsql --host=localhost
```

This would:
1. Export all data from current database
2. Create tables in target database
3. Import data with ID preservation
4. Update .env configuration

## Consequences

### Positive

- SQLite default = zero-config for most users
- Same codebase runs on all databases
- Easy migration path as needs grow
- Laravel's abstraction is well-tested

### Negative

- Must avoid database-specific features
- Some advanced queries need workarounds
- Testing on all databases increases CI time
- SQLite concurrent write limitations

### Neutral

- Most applications won't hit SQLite limits
- Enterprise users typically have MySQL/PG already
- Supabase support enables cloud-native deployment

## Related Decisions

- [ADR-001: Technology Stack](./001-technology-stack.md)
- [ADR-007: Backup System Design](./007-backup-system-design.md)
- [ADR-009: Docker Single-Container Architecture](./009-docker-single-container.md)

## Notes

### Supabase Integration

Supabase is PostgreSQL with additional features:
- Use standard PostgreSQL connection for database
- Optionally use Supabase client for realtime, auth, storage
- Environment variables:
  ```env
  DB_CONNECTION=pgsql
  DB_HOST=db.xxxxx.supabase.co
  DB_PORT=5432
  DB_DATABASE=postgres
  DB_USERNAME=postgres
  DB_PASSWORD=your-password
  ```

### Performance Considerations

For high-traffic deployments:
1. Use MySQL or PostgreSQL instead of SQLite
2. Add read replicas for read-heavy workloads
3. Use Redis for session/cache instead of database
4. Consider connection pooling (PgBouncer, ProxySQL)
