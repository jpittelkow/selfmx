<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;
use App\Services\NotificationTemplateService;
use App\Services\UsageTrackingService;
use Illuminate\Support\Facades\Http;

class SNSChannel implements ChannelInterface
{
    use ExtractsCountryFromPhone;

    private string $accessKeyId;
    private string $secretAccessKey;
    private string $region;

    public function __construct()
    {
        $this->accessKeyId = config('notifications.channels.sns.key', '');
        $this->secretAccessKey = config('notifications.channels.sns.secret', '');
        $this->region = config('notifications.channels.sns.region', 'us-east-1');
    }

    public function send(User $user, string $type, string $title, string $message, array $data = []): array
    {
        $resolved = $this->resolveContent($user, $type, $title, $message, $data);
        $title = $resolved['title'];
        $message = $resolved['body'];

        $phoneNumber = $user->getSetting('notifications', 'sns_phone_number')
            ?? $user->getSetting('phone_number');

        if (!$phoneNumber) {
            throw new \RuntimeException('Phone number not configured for user');
        }

        if (!$this->accessKeyId || !$this->secretAccessKey) {
            throw new \RuntimeException('AWS credentials not configured');
        }

        $smsBody = "{$title}\n\n{$message}";

        $host = "sns.{$this->region}.amazonaws.com";
        $endpoint = "https://{$host}/";

        $params = [
            'Action' => 'Publish',
            'Message' => $smsBody,
            'PhoneNumber' => $phoneNumber,
            'Version' => '2010-03-31',
        ];

        $headers = $this->signRequest('POST', $endpoint, $params);

        $response = Http::withHeaders($headers)
            ->asForm()
            ->post($endpoint, $params);

        if (!$response->successful()) {
            throw new \RuntimeException('AWS SNS API error: ' . $response->body());
        }

        // Parse XML response
        $xml = simplexml_load_string($response->body());
        $messageId = (string) ($xml->PublishResult->MessageId ?? '');

        // Record usage for integration usage dashboard
        $country = $this->extractCountryFromPhone($phoneNumber);
        app(UsageTrackingService::class)->recordSMS('sns', $country, $user->id);

        return [
            'message_id' => $messageId,
            'to' => $phoneNumber,
            'sent' => true,
        ];
    }

    /**
     * Generate AWS Signature Version 4 headers.
     */
    private function signRequest(string $method, string $url, array $params): array
    {
        $timestamp = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');

        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'];

        $service = 'sns';
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$datestamp}/{$this->region}/{$service}/aws4_request";

        // Sort and encode parameters
        ksort($params);
        $queryString = http_build_query($params);
        $payloadHash = hash('sha256', $queryString);

        $canonicalHeaders = "content-type:application/x-www-form-urlencoded\nhost:{$host}\nx-amz-date:{$timestamp}\n";
        $signedHeaders = 'content-type;host;x-amz-date';

        $canonicalRequest = "{$method}\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $stringToSign = "{$algorithm}\n{$timestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        // Calculate signature
        $kDate = hash_hmac('sha256', $datestamp, "AWS4{$this->secretAccessKey}", true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = "{$algorithm} Credential={$this->accessKeyId}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Host' => $host,
            'X-Amz-Date' => $timestamp,
            'Authorization' => $authorization,
        ];
    }

    public function getName(): string
    {
        return 'SMS (AWS SNS)';
    }

    public function isAvailableFor(User $user): bool
    {
        $phone = $user->getSetting('notifications', 'sns_phone_number')
            ?? $user->getSetting('phone_number');

        return config('notifications.channels.sns.enabled', false)
            && !empty($phone);
    }

    private function resolveContent(User $user, string $type, string $title, string $message, array $data): array
    {
        $service = app(NotificationTemplateService::class);
        $template = $service->getByTypeAndChannel($type, 'chat');
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
