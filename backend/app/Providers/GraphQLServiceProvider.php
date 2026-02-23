<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class GraphQLServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Override Lighthouse security settings from admin-configurable values.
        // ConfigServiceProvider injects graphql.* settings before this runs.
        config([
            'lighthouse.security.max_query_depth' => (int) config('graphql.max_query_depth', 12),
            'lighthouse.security.max_query_complexity' => (int) config('graphql.max_query_complexity', 200),
            'lighthouse.security.disable_introspection' => config('graphql.introspection_enabled', false)
                ? \GraphQL\Validator\Rules\DisableIntrospection::DISABLED
                : \GraphQL\Validator\Rules\DisableIntrospection::ENABLED,
            'lighthouse.pagination.max_count' => (int) config('graphql.max_result_size', 100),
        ]);
    }
}
