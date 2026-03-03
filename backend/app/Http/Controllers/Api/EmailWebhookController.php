<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\EmailWebhookLog;
use App\Services\Email\DomainService;
use App\Services\Email\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmailWebhookController extends Controller
{
    public function __construct(
        private EmailService $emailService,
        private DomainService $domainService,
    ) {}

    /**
     * Handle inbound email webhook from a provider.
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        try {
            $emailProvider = $this->domainService->resolveProvider($provider);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Unknown provider'], 404);
        }

        // Verify webhook signature
        if (!$emailProvider->verifyWebhookSignature($request)) {
            Log::warning("Email webhook signature verification failed for provider: {$provider}");
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        try {
            $parsed = $emailProvider->parseInboundEmail($request);
            $email = $this->emailService->processInboundEmail($parsed, $provider);

            if ($email === null) {
                // Duplicate or no matching mailbox — still return 200 so provider doesn't retry
                return response()->json(['message' => 'accepted'], 200);
            }

            return response()->json(['message' => 'ok', 'email_id' => $email->id], 200);
        } catch (\Exception $e) {
            Log::error("Email webhook processing failed", [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            // Return 500 so provider retries
            return response()->json(['message' => 'Processing failed'], 500);
        }
    }

    /**
     * Handle delivery event webhooks (delivered, bounced, failed, etc.).
     */
    public function handleEvent(Request $request, string $provider): JsonResponse
    {
        try {
            $emailProvider = $this->domainService->resolveProvider($provider);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Unknown provider'], 404);
        }

        if (!$emailProvider->verifyWebhookSignature($request)) {
            Log::warning("Delivery event webhook signature verification failed for provider: {$provider}");
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        try {
            $eventData = $emailProvider->parseDeliveryEvent($request);
            $providerMessageId = $eventData['provider_message_id'] ?? null;
            $eventId = $request->input('event-data.id', uniqid('evt_', true));

            if (!$providerMessageId) {
                // No message ID to match — log and acknowledge so provider doesn't retry
                EmailWebhookLog::create([
                    'provider' => $provider,
                    'provider_event_id' => $eventId,
                    'event_type' => 'delivery_status',
                    'payload' => ['event' => $eventData['event_type'] ?? 'unknown'],
                    'status' => 'failed',
                    'error_message' => 'No provider_message_id in payload',
                    'created_at' => now(),
                ]);
                return response()->json(['message' => 'accepted'], 200);
            }

            // Find the email by provider_message_id
            $email = Email::where('provider_message_id', $providerMessageId)->first();

            if ($email && $eventData['event_type']) {
                $email->update(['delivery_status' => $eventData['event_type']]);
            }

            // Log the event
            EmailWebhookLog::create([
                'provider' => $provider,
                'provider_event_id' => $eventId,
                'event_type' => 'delivery_status',
                'payload' => [
                    'event' => $eventData['event_type'],
                    'recipient' => $eventData['recipient'],
                    'provider_message_id' => $providerMessageId,
                ],
                'status' => $email ? 'processed' : 'failed',
                'error_message' => $email ? null : 'Email not found for provider_message_id',
                'created_at' => now(),
            ]);

            return response()->json(['message' => 'ok'], 200);
        } catch (\Exception $e) {
            Log::error("Delivery event webhook processing failed", [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return 200 for parsing/data errors to prevent infinite retries.
            // Only truly transient failures (DB down, etc.) should trigger retries.
            return response()->json(['message' => 'accepted'], 200);
        }
    }
}
