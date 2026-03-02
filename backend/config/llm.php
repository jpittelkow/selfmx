<?php

/**
 * selfmx LLM Configuration
 *
 * Configure AI/LLM providers and orchestration modes.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default Operating Mode
    |--------------------------------------------------------------------------
    |
    | single     - Use one provider, direct responses
    | aggregation - Query multiple providers, primary synthesizes
    | council    - All providers vote/contribute, consensus resolution
    |
    */

    'mode' => env('LLM_MODE', 'single'),

    /*
    |--------------------------------------------------------------------------
    | Primary Provider
    |--------------------------------------------------------------------------
    |
    | The default provider for single mode, and the synthesizer for
    | aggregation mode. Must be one of the enabled providers.
    |
    */

    'primary' => env('LLM_PRIMARY', 'claude'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time in seconds to wait for LLM responses.
    |
    */

    'timeout' => env('LLM_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Enable Request Logging
    |--------------------------------------------------------------------------
    |
    | Log all LLM requests and responses for debugging and cost analysis.
    |
    */

    'logging_enabled' => env('LLM_LOGGING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Providers Configuration
    |--------------------------------------------------------------------------
    */

    'providers' => [

        'claude' => [
            'name' => 'Claude (Anthropic)',
            'driver' => 'anthropic',
            'enabled' => !empty(env('ANTHROPIC_API_KEY')),
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('CLAUDE_MODEL', 'claude-sonnet-4-20250514'),
            'max_tokens' => env('CLAUDE_MAX_TOKENS', 4096),
            'supports_vision' => true,
            'supports_tools' => true,
        ],

        'openai' => [
            'name' => 'OpenAI',
            'driver' => 'openai',
            'enabled' => !empty(env('OPENAI_API_KEY')),
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o'),
            'max_tokens' => env('OPENAI_MAX_TOKENS', 4096),
            'supports_vision' => true,
            'supports_tools' => true,
        ],

        'gemini' => [
            'name' => 'Gemini (Google)',
            'driver' => 'gemini',
            'enabled' => !empty(env('GEMINI_API_KEY')),
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-1.5-pro'),
            'max_tokens' => env('GEMINI_MAX_TOKENS', 4096),
            'supports_vision' => true,
            'supports_tools' => true,
        ],

        'ollama' => [
            'name' => 'Ollama (Local)',
            'driver' => 'ollama',
            'enabled' => env('OLLAMA_ENABLED', false),
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'model' => env('OLLAMA_MODEL', 'llama3.2'),
            'max_tokens' => env('OLLAMA_MAX_TOKENS', 4096),
            'supports_vision' => env('OLLAMA_SUPPORTS_VISION', false),
            'supports_tools' => false,
        ],

        'bedrock' => [
            'name' => 'AWS Bedrock (Claude)',
            'driver' => 'bedrock',
            'enabled' => !empty(env('AWS_ACCESS_KEY_ID')) && env('BEDROCK_ENABLED', false),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'model' => env('BEDROCK_MODEL', 'anthropic.claude-3-sonnet-20240229-v1:0'),
            'max_tokens' => env('BEDROCK_MAX_TOKENS', 4096),
            'supports_vision' => true,
            'supports_tools' => true,
        ],

        'azure' => [
            'name' => 'Azure OpenAI',
            'driver' => 'azure',
            'enabled' => !empty(env('AZURE_OPENAI_API_KEY')),
            'api_key' => env('AZURE_OPENAI_API_KEY'),
            'endpoint' => env('AZURE_OPENAI_ENDPOINT'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2024-02-15-preview'),
            'max_tokens' => env('AZURE_MAX_TOKENS', 4096),
            'supports_vision' => true,
            'supports_tools' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Council Mode Configuration
    |--------------------------------------------------------------------------
    */

    'council' => [
        // Minimum providers needed for council mode
        'min_providers' => env('LLM_COUNCIL_MIN_PROVIDERS', 2),

        // How to handle disagreements: 'majority', 'weighted', 'synthesize'
        'resolution_strategy' => env('LLM_COUNCIL_STRATEGY', 'synthesize'),

        // Weight factors for each provider in weighted voting
        'weights' => [
            'claude' => 1.0,
            'openai' => 1.0,
            'gemini' => 0.8,
            'ollama' => 0.5,
            'bedrock' => 1.0,
            'azure' => 1.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Aggregation Mode Configuration
    |--------------------------------------------------------------------------
    */

    'aggregation' => [
        // Run queries in parallel or sequential
        'parallel' => env('LLM_AGGREGATION_PARALLEL', true),

        // Include individual responses in the final output
        'include_sources' => env('LLM_AGGREGATION_INCLUDE_SOURCES', true),
    ],

];
