<?php

namespace App\Services;

use App\Models\IntegrationUsage;
use Illuminate\Support\Facades\Log;
use App\Services\SettingService;

class UsageTrackingService
{
    /**
     * Record a usage event for any integration.
     */
    public function record(
        string $integration,
        string $provider,
        string $metric,
        float $quantity,
        ?float $estimatedCost = null,
        ?array $metadata = null,
        ?int $userId = null
    ): IntegrationUsage {
        return IntegrationUsage::create([
            'integration' => $integration,
            'provider' => $provider,
            'metric' => $metric,
            'quantity' => $quantity,
            'estimated_cost' => $estimatedCost,
            'metadata' => $metadata,
            'user_id' => $userId,
        ]);
    }

    /**
     * Record LLM usage (tokens in/out with optional cost).
     * Creates two records: one for input tokens and one for output tokens.
     * If no cost is provided, estimates from pricing_llm settings.
     */
    public function recordLLM(
        string $provider,
        ?string $model,
        int $tokensIn,
        int $tokensOut,
        ?float $cost = null,
        ?int $userId = null
    ): void {
        try {
            $metadata = array_filter(['model' => $model]);

            // If no cost provided, estimate from pricing settings
            if ($cost === null && $model) {
                $cost = $this->estimateLLMCost($model, $tokensIn, $tokensOut);
            }

            // Split cost proportionally between input/output tokens
            $totalTokens = $tokensIn + $tokensOut;
            $costIn = null;
            $costOut = null;

            if ($cost !== null && $totalTokens > 0) {
                $costIn = ($tokensIn / $totalTokens) * $cost;
                $costOut = ($tokensOut / $totalTokens) * $cost;
            } elseif ($cost !== null) {
                $costIn = $cost;
            }

            if ($tokensIn > 0) {
                $this->record(
                    IntegrationUsage::INTEGRATION_LLM,
                    $provider,
                    'tokens_in',
                    $tokensIn,
                    $costIn,
                    $metadata ?: null,
                    $userId
                );
            }

            if ($tokensOut > 0) {
                $this->record(
                    IntegrationUsage::INTEGRATION_LLM,
                    $provider,
                    'tokens_out',
                    $tokensOut,
                    $costOut,
                    $metadata ?: null,
                    $userId
                );
            }

            // Edge case: if both token counts are 0 but we have a cost, record it
            if ($tokensIn === 0 && $tokensOut === 0 && $cost !== null) {
                $this->record(
                    IntegrationUsage::INTEGRATION_LLM,
                    $provider,
                    'tokens_in',
                    0,
                    $cost,
                    $metadata ?: null,
                    $userId
                );
            }
        } catch (\Exception $e) {
            Log::warning('Failed to record LLM usage', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Record an email send event.
     */
    public function recordEmail(string $provider, ?int $userId = null): void
    {
        try {
            $this->record(
                IntegrationUsage::INTEGRATION_EMAIL,
                $provider,
                'messages',
                1,
                null,
                null,
                $userId
            );
        } catch (\Exception $e) {
            Log::warning('Failed to record email usage', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Record an SMS send event.
     */
    public function recordSMS(string $provider, ?string $country = null, ?int $userId = null): void
    {
        try {
            $metadata = $country ? ['country' => $country] : null;

            $this->record(
                IntegrationUsage::INTEGRATION_SMS,
                $provider,
                'messages',
                1,
                null,
                $metadata,
                $userId
            );
        } catch (\Exception $e) {
            Log::warning('Failed to record SMS usage', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Record a storage operation (upload or download).
     * Only call this for cloud providers (not local).
     */
    public function recordStorage(string $provider, string $operation, int $bytes, ?int $userId = null): void
    {
        try {
            $metric = $operation === 'upload' ? 'bytes_uploaded' : 'bytes_downloaded';

            $this->record(
                IntegrationUsage::INTEGRATION_STORAGE,
                $provider,
                $metric,
                $bytes,
                null,
                null,
                $userId
            );
        } catch (\Exception $e) {
            Log::warning('Failed to record storage usage', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Record a broadcasting event.
     */
    public function recordBroadcast(string $provider): void
    {
        try {
            $this->record(
                IntegrationUsage::INTEGRATION_BROADCASTING,
                $provider,
                'connections',
                1,
                null,
                null,
                null
            );
        } catch (\Exception $e) {
            Log::warning('Failed to record broadcast usage', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Record a payment event (successful charge or refund).
     */
    public function recordPayment(
        string $provider,
        float $amountDollars,
        string $metric = 'payment_processed',
        ?array $metadata = null,
        ?int $userId = null
    ): void {
        try {
            $this->record(
                IntegrationUsage::INTEGRATION_PAYMENTS,
                $provider,
                $metric,
                1,
                $amountDollars,
                $metadata,
                $userId
            );
        } catch (\Exception $e) {
            Log::warning('Failed to record payment usage', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Estimate LLM cost from pricing settings.
     * Pricing JSON format: {"model_name": {"input": rate_per_1k, "output": rate_per_1k}, ...}
     * Falls back to sensible defaults for common models.
     */
    private function estimateLLMCost(string $model, int $tokensIn, int $tokensOut): ?float
    {
        try {
            $pricingJson = app(SettingService::class)->get('usage', 'pricing_llm') ?? '{}';
            $pricing = json_decode($pricingJson, true) ?: [];

            // Check for exact model match first, then partial match
            $rates = $pricing[$model] ?? null;
            if (!$rates) {
                foreach ($pricing as $key => $value) {
                    if (str_contains($model, $key) || str_contains($key, $model)) {
                        $rates = $value;
                        break;
                    }
                }
            }

            // Default rates (per 1K tokens) for common models if no pricing configured
            if (!$rates) {
                $rates = $this->getDefaultRates($model);
            }

            if (!$rates) {
                return null;
            }

            $inputRate = (float) ($rates['input'] ?? 0);
            $outputRate = (float) ($rates['output'] ?? 0);

            return (($tokensIn / 1000) * $inputRate) + (($tokensOut / 1000) * $outputRate);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get default pricing rates for common LLM models (per 1K tokens).
     */
    private function getDefaultRates(string $model): ?array
    {
        $defaults = [
            'gpt-4o' => ['input' => 0.0025, 'output' => 0.01],
            'gpt-4' => ['input' => 0.03, 'output' => 0.06],
            'gpt-3.5' => ['input' => 0.0005, 'output' => 0.0015],
            'claude-3-opus' => ['input' => 0.015, 'output' => 0.075],
            'claude-3-sonnet' => ['input' => 0.003, 'output' => 0.015],
            'claude-3-haiku' => ['input' => 0.00025, 'output' => 0.00125],
            'gemini-pro' => ['input' => 0.00025, 'output' => 0.0005],
            'gemini-1.5-pro' => ['input' => 0.00125, 'output' => 0.005],
        ];

        foreach ($defaults as $key => $rates) {
            if (str_contains(strtolower($model), $key)) {
                return $rates;
            }
        }

        return null;
    }
}
