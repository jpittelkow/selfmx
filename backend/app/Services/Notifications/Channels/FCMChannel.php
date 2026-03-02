<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;
use App\Services\NotificationTemplateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FCMChannel implements ChannelInterface
{
    private string $projectId;
    private ?array $serviceAccount;

    public function __construct()
    {
        $this->projectId = config('notifications.channels.fcm.project_id', '');
        $this->serviceAccount = config('notifications.channels.fcm.service_account');

        // Backward compatibility: if service_account is a JSON string, decode it
        if (is_string($this->serviceAccount) && !empty($this->serviceAccount)) {
            $decoded = json_decode($this->serviceAccount, true);
            $this->serviceAccount = is_array($decoded) ? $decoded : null;
        }
    }

    public function send(User $user, string $type, string $title, string $message, array $data = []): array
    {
        $resolved = $this->resolveContent($user, $type, $title, $message, $data);
        $title = $resolved['title'];
        $message = $resolved['body'];

        $fcmToken = $user->getSetting('notifications', 'fcm_token');

        if (!$fcmToken) {
            throw new \RuntimeException('FCM token not configured for user');
        }

        if (!$this->projectId || !$this->serviceAccount) {
            throw new \RuntimeException('FCM service account not configured');
        }

        $accessToken = $this->getAccessToken();

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body' => $message,
                ],
                'data' => collect(array_merge($data, [
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'timestamp' => (string) time(),
                ]))->filter(fn ($v) => is_scalar($v) || is_null($v))
                    ->map(fn ($v) => (string) $v)
                    ->all(),
            ],
        ];

        // Add icon if provided
        if (isset($data['icon'])) {
            $payload['message']['notification']['image'] = $data['icon'];
        }

        // Add Android-specific options
        if (isset($data['android'])) {
            $payload['message']['android'] = $data['android'];
        } else {
            $payload['message']['android'] = [
                'priority' => 'high',
                'notification' => [
                    'click_action' => $data['click_action'] ?? 'FLUTTER_NOTIFICATION_CLICK',
                    'sound' => $data['sound'] ?? 'default',
                ],
            ];
        }

        // Add APNs (iOS) options
        if (isset($data['apns'])) {
            $payload['message']['apns'] = $data['apns'];
        }

        // Add web-specific options
        if (isset($data['webpush'])) {
            $payload['message']['webpush'] = $data['webpush'];
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ])->post($url, $payload);

        if (!$response->successful()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new \RuntimeException('FCM API error: ' . $error);
        }

        return [
            'message_name' => $response->json('name'),
            'sent' => true,
        ];
    }

    /**
     * Generate an OAuth2 access token from the service account credentials.
     * Uses a self-signed JWT to exchange for an access token, cached for 50 minutes.
     */
    private function getAccessToken(): string
    {
        $cacheKey = 'fcm_access_token_' . md5($this->projectId);

        return Cache::remember($cacheKey, 3000, function () {
            return $this->fetchAccessToken();
        });
    }

    /**
     * Fetch a fresh OAuth2 access token from Google.
     */
    private function fetchAccessToken(): string
    {
        $serviceAccount = $this->serviceAccount;
        if (!$serviceAccount || !isset($serviceAccount['client_email'], $serviceAccount['private_key'])) {
            throw new \RuntimeException('FCM service account is missing required fields');
        }

        $now = time();
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $claim = json_encode([
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $base64Header = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
        $base64Claim = rtrim(strtr(base64_encode($claim), '+/', '-_'), '=');
        $signingInput = "{$base64Header}.{$base64Claim}";

        try {
            $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Invalid FCM service account private key: ' . $e->getMessage());
        }

        if ($privateKey === false) {
            throw new \RuntimeException('Invalid FCM service account private key: unable to parse');
        }

        openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $base64Signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        $jwt = "{$signingInput}.{$base64Signature}";

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to obtain FCM access token: ' . $response->body());
        }

        $token = $response->json('access_token');
        if (empty($token)) {
            throw new \RuntimeException('FCM access token response missing access_token field');
        }

        return $token;
    }

    public function getName(): string
    {
        return 'Firebase Cloud Messaging';
    }

    public function isAvailableFor(User $user): bool
    {
        return config('notifications.channels.fcm.enabled', false)
            && !empty($user->getSetting('notifications', 'fcm_token'));
    }

    private function resolveContent(User $user, string $type, string $title, string $message, array $data): array
    {
        $service = app(NotificationTemplateService::class);
        $template = $service->getByTypeAndChannel($type, 'push');
        if (!$template) {
            return ['title' => $title, 'body' => $message];
        }
        $variables = array_merge([
            'user' => ['name' => $user->name, 'email' => $user->email],
            'app_name' => config('app.name', 'selfmx'),
        ], $data);
        return $service->renderTemplate($template, $variables);
    }
}
