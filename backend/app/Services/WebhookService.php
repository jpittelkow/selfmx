<?php

namespace App\Services;

use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    public function __construct(
        private UrlValidationService $urlValidator
    ) {}

    /**
     * Send a test webhook delivery.
     *
     * @return array{success: bool, status_code: ?int, message: string, ssrf_blocked?: bool}
     */
    public function sendTest(Webhook $webhook): array
    {
        $resolved = $this->urlValidator->validateAndResolve($webhook->url);
        if ($resolved === null) {
            return [
                'success' => false,
                'status_code' => null,
                'message' => 'Webhook test failed: URL points to an internal or private address',
                'ssrf_blocked' => true,
            ];
        }

        $payload = [
            'event' => 'webhook.test',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'message' => 'This is a test webhook',
            ],
        ];

        try {
            $headers = $this->buildHeaders($webhook, $payload);

            $response = Http::timeout(10)
                ->withHeaders($headers)
                ->withOptions($this->urlValidator->pinnedOptions($resolved))
                ->post($webhook->url, $payload);

            $webhook->deliveries()->create([
                'event' => 'webhook.test',
                'payload' => $payload,
                'response_code' => $response->status(),
                'response_body' => $response->body(),
                'success' => $response->successful(),
            ]);

            $webhook->update(['last_triggered_at' => now()]);

            return [
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'message' => 'Webhook test completed',
            ];
        } catch (\Exception $e) {
            Log::error('Webhook test failed', ['webhook_id' => $webhook->id, 'exception' => $e]);

            $webhook->deliveries()->create([
                'event' => 'webhook.test',
                'payload' => $payload,
                'response_code' => null,
                'response_body' => 'Connection failed',
                'success' => false,
            ]);

            return [
                'success' => false,
                'status_code' => null,
                'message' => 'Webhook test failed',
            ];
        }
    }

    /**
     * Build webhook headers including signature if secret is configured.
     */
    private function buildHeaders(Webhook $webhook, array $payload): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'selfmx-Webhook/1.0',
        ];

        if (!empty($webhook->secret)) {
            $timestamp = time();
            $signature = $this->generateSignature($webhook->secret, $timestamp, $payload);

            $headers['X-Webhook-Timestamp'] = $timestamp;
            $headers['X-Webhook-Signature'] = 'sha256=' . $signature;
        }

        return $headers;
    }

    /**
     * Generate HMAC-SHA256 signature for webhook payload.
     *
     * The signature is computed over: timestamp.json_payload
     * This prevents replay attacks by binding the signature to a specific timestamp.
     */
    private function generateSignature(string $secret, int $timestamp, array $payload): string
    {
        $payloadJson = json_encode($payload);
        $signaturePayload = $timestamp . '.' . $payloadJson;

        return hash_hmac('sha256', $signaturePayload, $secret);
    }
}
