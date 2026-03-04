<?php

use App\Models\EmailDomain;
use App\Models\User;
use App\Services\Email\MailgunProvider;
use App\Services\GroupService;
use App\Services\SettingService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->adminUser = User::factory()->admin()->create();

    $this->domain = EmailDomain::create([
        'user_id' => $this->user->id,
        'name' => 'test.example.com',
        'provider' => 'mailgun',
        'is_verified' => true,
        'is_active' => true,
    ]);

    $this->otherUser = User::factory()->create();
    $this->otherDomain = EmailDomain::create([
        'user_id' => $this->otherUser->id,
        'name' => 'other.example.com',
        'provider' => 'mailgun',
        'is_verified' => true,
        'is_active' => true,
    ]);
});

// ─── Domain Filtering ────────────────────────────────────────────────────────

describe('Domain Filtering', function () {
    it('lists only user domains', function () {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/email/domains');

        $response->assertOk()
            ->assertJsonCount(1, 'domains')
            ->assertJsonPath('domains.0.name', 'test.example.com');
    });

    it('filters by search query', function () {
        EmailDomain::create([
            'user_id' => $this->user->id,
            'name' => 'another.example.com',
            'provider' => 'mailgun',
            'is_verified' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/email/domains?search=another');

        $response->assertOk()
            ->assertJsonCount(1, 'domains')
            ->assertJsonPath('domains.0.name', 'another.example.com');
    });

    it('filters by verified status', function () {
        EmailDomain::create([
            'user_id' => $this->user->id,
            'name' => 'unverified.example.com',
            'provider' => 'mailgun',
            'is_verified' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/email/domains?verified=0');

        $response->assertOk()
            ->assertJsonCount(1, 'domains')
            ->assertJsonPath('domains.0.name', 'unverified.example.com');
    });
});

// ─── Provider Health ─────────────────────────────────────────────────────────

describe('Provider Health', function () {
    it('returns healthy status with latency', function () {
        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('checkApiHealth')->once()->andReturn(true);
        $this->app->instance(MailgunProvider::class, $mock);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/email/provider/health');

        $response->assertOk()
            ->assertJsonStructure(['healthy', 'latency_ms', 'provider'])
            ->assertJsonPath('healthy', true)
            ->assertJsonPath('provider', 'mailgun');
    });

    it('returns unhealthy status', function () {
        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('checkApiHealth')->once()->andReturn(false);
        $this->app->instance(MailgunProvider::class, $mock);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/email/provider/health');

        $response->assertOk()
            ->assertJsonPath('healthy', false);
    });
});

// ─── Webhook Testing ─────────────────────────────────────────────────────────

describe('Webhook Testing', function () {
    it('tests a configured webhook', function () {
        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('listWebhooks')->once()->andReturn([
            'delivered' => ['urls' => ['https://example.com/webhook']],
        ]);
        $mock->shouldReceive('testWebhook')->once()->andReturn([
            'success' => true,
            'status_code' => 200,
            'message' => 'Webhook test delivered successfully',
        ]);
        $this->app->instance(MailgunProvider::class, $mock);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/email/domains/{$this->domain->id}/mailgun/webhooks/delivered/test");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status_code', 200);
    });

    it('rejects test for unconfigured webhook', function () {
        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('listWebhooks')->once()->andReturn([]);
        $this->app->instance(MailgunProvider::class, $mock);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/email/domains/{$this->domain->id}/mailgun/webhooks/delivered/test");

        $response->assertStatus(404);
    });
});

// ─── DKIM Rotation Settings ─────────────────────────────────────────────────

describe('DKIM Rotation Settings', function () {
    it('returns current rotation interval for admin', function () {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/email/dkim-rotation-settings');

        $response->assertOk()
            ->assertJsonStructure(['interval_days', 'enabled'])
            ->assertJsonPath('interval_days', 0)
            ->assertJsonPath('enabled', false);
    });

    it('rejects non-admin access to rotation settings', function () {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/email/dkim-rotation-settings');

        $response->assertStatus(403);
    });

    it('updates rotation interval for admin', function () {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson('/api/email/dkim-rotation-settings', ['interval_days' => 90]);

        $response->assertOk()
            ->assertJsonPath('interval_days', 90)
            ->assertJsonPath('enabled', true);
    });

    it('validates rotation interval bounds', function () {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson('/api/email/dkim-rotation-settings', ['interval_days' => 5000]);

        $response->assertStatus(422);
    });

    it('returns rotation history for domain', function () {
        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('getDkimKey')->andReturn([]);
        $this->app->instance(MailgunProvider::class, $mock);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/email/domains/{$this->domain->id}/mailgun/dkim/rotation-history");

        $response->assertOk()
            ->assertJsonStructure(['history']);
    });
});

// ─── Suppression Batch Check ─────────────────────────────────────────────────

describe('Suppression Batch Check', function () {
    it('checks batch of addresses', function () {
        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('checkSuppression')
            ->with('test.example.com', 'bounce@test.com', Mockery::any())
            ->andReturn(['suppressed' => true, 'reason' => 'bounce', 'detail' => 'hard bounce']);
        $mock->shouldReceive('checkSuppression')
            ->with('test.example.com', 'clean@test.com', Mockery::any())
            ->andReturn(['suppressed' => false, 'reason' => null, 'detail' => null]);
        $this->app->instance(MailgunProvider::class, $mock);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/email/domains/{$this->domain->id}/mailgun/suppressions/check-batch", [
                'addresses' => ['bounce@test.com', 'clean@test.com'],
            ]);

        $response->assertOk();
        $data = $response->json('results');
        expect($data['bounce@test.com']['suppressed'])->toBeTrue();
        expect($data['bounce@test.com']['reason'])->toBe('bounce');
        expect($data['clean@test.com']['suppressed'])->toBeFalse();
    });

    it('validates addresses are emails', function () {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/email/domains/{$this->domain->id}/mailgun/suppressions/check-batch", [
                'addresses' => ['not-an-email'],
            ]);

        $response->assertStatus(422);
    });
});

// ─── User Scoping ────────────────────────────────────────────────────────────

describe('User Scoping', function () {
    it('cannot access another users domain', function () {
        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $this->app->instance(MailgunProvider::class, $mock);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/email/domains/{$this->otherDomain->id}/mailgun/dkim");

        $response->assertStatus(404);
    });

    it('cannot test webhook on another users domain', function () {
        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $this->app->instance(MailgunProvider::class, $mock);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/email/domains/{$this->otherDomain->id}/mailgun/webhooks/delivered/test");

        $response->assertStatus(404);
    });
});

// ─── Suppression Export ──────────────────────────────────────────────────────

describe('Suppression Export', function () {
    it('exports bounces as CSV', function () {
        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('listBounces')->andReturn([
            'items' => [
                ['address' => 'bounce@test.com', 'error' => 'hard bounce', 'code' => 550, 'created_at' => '2026-01-01'],
            ],
            'nextPage' => null,
        ]);
        $this->app->instance(MailgunProvider::class, $mock);

        $response = $this->actingAs($this->user, 'sanctum')
            ->get("/api/email/domains/{$this->domain->id}/mailgun/suppressions/bounces/export");

        $response->assertOk()
            ->assertHeader('content-disposition');

        $content = $response->streamedContent();
        expect($content)->toContain('address,error,code,created_at');
        expect($content)->toContain('bounce@test.com');
    });
});
