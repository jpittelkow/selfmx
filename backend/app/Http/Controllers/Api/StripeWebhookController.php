<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Stripe\StripeWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController extends Controller
{
    public function __construct(
        private StripeWebhookService $webhookService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature', '');

        if (empty($sigHeader)) {
            Log::warning('Stripe webhook received with no Stripe-Signature header');

            return response()->json(['message' => 'Missing signature'], 400);
        }

        try {
            $event = $this->webhookService->constructEvent($payload, $sigHeader);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Invalid signature'], 400);
        } catch (\UnexpectedValueException $e) {
            Log::warning('Stripe webhook payload parsing failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Invalid payload'], 400);
        }

        try {
            $result = $this->webhookService->handleEvent($event);

            return response()->json([
                'message' => $result['skipped'] ? 'skipped' : 'ok',
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Handler error'], 500);
        }
    }
}
