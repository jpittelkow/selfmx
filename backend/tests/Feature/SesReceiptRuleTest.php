<?php

use App\Services\Email\SesProvider;
use App\Services\SettingService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->settingService = Mockery::mock(SettingService::class);
    $this->settingService->shouldReceive('get')->andReturnUsing(function ($group, $key, $default = '') {
        return match ("{$group}.{$key}") {
            'ses.region' => 'us-east-1',
            'ses.access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'ses.secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            default => $default,
        };
    });

    $this->provider = new SesProvider($this->settingService);
});

describe('configureDomainWebhook', function () {

    it('creates SNS topic, receipt rule set, and receipt rule when no active set exists', function () {
        Http::fake([
            'https://sns.us-east-1.amazonaws.com' => Http::sequence()
                ->push('<CreateTopicResponse><CreateTopicResult><TopicArn>arn:aws:sns:us-east-1:123456789:selfmx-example-com-inbound</TopicArn></CreateTopicResult></CreateTopicResponse>', 200)
                ->push('<SubscribeResponse><SubscribeResult><SubscriptionArn>arn:aws:sns:us-east-1:123456789:selfmx-example-com-inbound:sub-123</SubscriptionArn></SubscribeResult></SubscribeResponse>', 200),
            // SES v1: DescribeActiveReceiptRuleSet (empty), CreateReceiptRuleSet, SetActiveReceiptRuleSet, CreateReceiptRule
            'https://email.us-east-1.amazonaws.com' => Http::sequence()
                ->push('<DescribeActiveReceiptRuleSetResponse><Metadata/></DescribeActiveReceiptRuleSetResponse>', 200)
                ->push('<CreateReceiptRuleSetResponse><ResponseMetadata><RequestId>abc123</RequestId></ResponseMetadata></CreateReceiptRuleSetResponse>', 200)
                ->push('<SetActiveReceiptRuleSetResponse><ResponseMetadata><RequestId>def456</RequestId></ResponseMetadata></SetActiveReceiptRuleSetResponse>', 200)
                ->push('<CreateReceiptRuleResponse><ResponseMetadata><RequestId>ghi789</RequestId></ResponseMetadata></CreateReceiptRuleResponse>', 200),
        ]);

        $result = $this->provider->configureDomainWebhook(
            'example.com',
            'https://app.example.com/api/email/webhook/ses',
        );

        expect($result)->toBeTrue();

        Http::assertSentCount(6); // 2 SNS + 4 SES v1
    });

    it('reuses existing active receipt rule set', function () {
        Http::fake([
            'https://sns.us-east-1.amazonaws.com' => Http::sequence()
                ->push('<CreateTopicResponse><CreateTopicResult><TopicArn>arn:aws:sns:us-east-1:123456789:topic</TopicArn></CreateTopicResult></CreateTopicResponse>', 200)
                ->push('<SubscribeResponse><SubscribeResult><SubscriptionArn>arn</SubscriptionArn></SubscribeResult></SubscribeResponse>', 200),
            // SES v1: DescribeActiveReceiptRuleSet (has existing set), CreateReceiptRule
            'https://email.us-east-1.amazonaws.com' => Http::sequence()
                ->push('<DescribeActiveReceiptRuleSetResponse><Metadata><Name>my-existing-rules</Name></Metadata></DescribeActiveReceiptRuleSetResponse>', 200)
                ->push('<CreateReceiptRuleResponse><ResponseMetadata><RequestId>ghi789</RequestId></ResponseMetadata></CreateReceiptRuleResponse>', 200),
        ]);

        $result = $this->provider->configureDomainWebhook(
            'example.com',
            'https://app.example.com/api/email/webhook/ses',
        );

        expect($result)->toBeTrue();

        // Should NOT have called CreateReceiptRuleSet or SetActiveReceiptRuleSet
        Http::assertSentCount(4); // 2 SNS + 2 SES v1 (Describe + CreateRule)
    });

    it('returns true when receipt rule already exists', function () {
        Http::fake([
            'https://sns.us-east-1.amazonaws.com' => Http::sequence()
                ->push('<CreateTopicResponse><CreateTopicResult><TopicArn>arn:aws:sns:us-east-1:123456789:selfmx-example-com-inbound</TopicArn></CreateTopicResult></CreateTopicResponse>', 200)
                ->push('<SubscribeResponse><SubscribeResult><SubscriptionArn>pending confirmation</SubscriptionArn></SubscribeResult></SubscribeResponse>', 200),
            'https://email.us-east-1.amazonaws.com' => Http::sequence()
                ->push('<DescribeActiveReceiptRuleSetResponse><Metadata><Name>selfmx</Name></Metadata></DescribeActiveReceiptRuleSetResponse>', 200)
                // CreateReceiptRule returns AlreadyExists error
                ->push('<ErrorResponse><Error><Code>AlreadyExists</Code><Message>Rule already exists</Message></Error></ErrorResponse>', 400),
        ]);

        $result = $this->provider->configureDomainWebhook(
            'example.com',
            'https://app.example.com/api/email/webhook/ses',
        );

        expect($result)->toBeTrue();
    });

    it('returns false when credentials are missing', function () {
        $emptySettings = Mockery::mock(SettingService::class);
        $emptySettings->shouldReceive('get')->andReturn('');

        $provider = new SesProvider($emptySettings);

        $result = $provider->configureDomainWebhook(
            'example.com',
            'https://app.example.com/api/email/webhook/ses',
        );

        expect($result)->toBeFalse();
    });

    it('returns false when activating rule set fails', function () {
        Http::fake([
            'https://sns.us-east-1.amazonaws.com' => Http::sequence()
                ->push('<CreateTopicResponse><CreateTopicResult><TopicArn>arn:aws:sns:us-east-1:123456789:topic</TopicArn></CreateTopicResult></CreateTopicResponse>', 200)
                ->push('<SubscribeResponse><SubscribeResult><SubscriptionArn>pending</SubscriptionArn></SubscribeResult></SubscribeResponse>', 200),
            'https://email.us-east-1.amazonaws.com' => Http::sequence()
                // No active rule set
                ->push('<DescribeActiveReceiptRuleSetResponse><Metadata/></DescribeActiveReceiptRuleSetResponse>', 200)
                ->push('<CreateReceiptRuleSetResponse></CreateReceiptRuleSetResponse>', 200)
                // SetActiveReceiptRuleSet fails
                ->push('<ErrorResponse><Error><Code>InvalidAction</Code><Message>Access denied</Message></Error></ErrorResponse>', 403),
        ]);

        $result = $this->provider->configureDomainWebhook(
            'example.com',
            'https://app.example.com/api/email/webhook/ses',
        );

        expect($result)->toBeFalse();
    });

    it('uses per-domain config when provided', function () {
        Http::fake([
            'https://sns.eu-west-1.amazonaws.com' => Http::sequence()
                ->push('<CreateTopicResponse><CreateTopicResult><TopicArn>arn:aws:sns:eu-west-1:123:topic</TopicArn></CreateTopicResult></CreateTopicResponse>', 200)
                ->push('<SubscribeResponse><SubscribeResult><SubscriptionArn>arn</SubscriptionArn></SubscribeResult></SubscribeResponse>', 200),
            'https://email.eu-west-1.amazonaws.com' => Http::sequence()
                ->push('<DescribeActiveReceiptRuleSetResponse><Metadata/></DescribeActiveReceiptRuleSetResponse>', 200)
                ->push('<CreateReceiptRuleSetResponse></CreateReceiptRuleSetResponse>', 200)
                ->push('<SetActiveReceiptRuleSetResponse></SetActiveReceiptRuleSetResponse>', 200)
                ->push('<CreateReceiptRuleResponse></CreateReceiptRuleResponse>', 200),
        ]);

        $result = $this->provider->configureDomainWebhook(
            'example.com',
            'https://app.example.com/api/email/webhook/ses',
            [
                'region' => 'eu-west-1',
                'access_key_id' => 'AKIAEUEXAMPLE',
                'secret_access_key' => 'euSecretKey',
            ],
        );

        expect($result)->toBeTrue();

        // Verify calls went to eu-west-1
        Http::assertSent(fn ($request) => str_contains($request->url(), 'eu-west-1'));
    });
});
