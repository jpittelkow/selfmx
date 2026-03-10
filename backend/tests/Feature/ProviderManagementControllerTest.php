<?php

use App\Exceptions\ProviderApiException;
use App\Models\EmailDomain;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Email\Concerns\HasDkimManagement;
use App\Services\Email\Concerns\HasEventLog;
use App\Services\Email\Concerns\HasSuppressionManagement;
use App\Services\Email\Concerns\HasWebhookManagement;
use App\Services\Email\MailgunProvider;
use App\Services\Email\ProviderManagementInterface;
use Mockery\MockInterface;

beforeEach(function () {
    UserGroup::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'permissions' => []]);
    UserGroup::firstOrCreate(['slug' => 'users'], ['name' => 'Users', 'permissions' => []]);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function managementDomain(User $user, string $provider = 'mailgun'): EmailDomain
{
    return EmailDomain::create([
        'user_id'        => $user->id,
        'name'           => 'example.com',
        'provider'       => $provider,
        'provider_config' => ['api_key' => 'test-key', 'domain' => 'example.com'],
        'status'         => 'active',
    ]);
}

// ---------------------------------------------------------------------------
// MailgunProvider implements the interfaces
// ---------------------------------------------------------------------------

describe('MailgunProvider capability contract', function () {
    it('implements ProviderManagementInterface', function () {
        $provider = app(MailgunProvider::class);
        expect($provider)->toBeInstanceOf(ProviderManagementInterface::class);
    });

    it('implements all capability sub-interfaces', function () {
        $provider = app(MailgunProvider::class);
        expect($provider)->toBeInstanceOf(HasDkimManagement::class);
        expect($provider)->toBeInstanceOf(HasWebhookManagement::class);
        expect($provider)->toBeInstanceOf(HasEventLog::class);
        expect($provider)->toBeInstanceOf(HasSuppressionManagement::class);
    });

    it('reports correct capabilities', function () {
        $caps = app(MailgunProvider::class)->getCapabilities();

        expect($caps['dkim_rotation'])->toBeTrue();
        expect($caps['webhooks'])->toBeTrue();
        expect($caps['inbound_routes'])->toBeTrue();
        expect($caps['events'])->toBeTrue();
        expect($caps['suppressions'])->toBeTrue();
        expect($caps['stats'])->toBeTrue();
        expect($caps['domain_management'])->toBeFalse();
        expect($caps['dns_records'])->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// GET /api/email/domains/{id}/management/capabilities
// ---------------------------------------------------------------------------

describe('getCapabilities', function () {
    it('returns provider capabilities for a mailgun domain', function () {
        $user = User::factory()->create();
        $domain = managementDomain($user);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/email/domains/{$domain->id}/management/capabilities");

        $response->assertStatus(200);
        $response->assertJsonStructure(['provider', 'capabilities']);
        $response->assertJsonFragment(['provider' => 'mailgun']);
        $response->assertJsonPath('capabilities.dkim_rotation', true);
        $response->assertJsonPath('capabilities.webhooks', true);
    });

    it('returns 404 for a domain belonging to another user', function () {
        $user   = User::factory()->create();
        $other  = User::factory()->create();
        $domain = managementDomain($other);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/email/domains/{$domain->id}/management/capabilities");

        $response->assertStatus(404);
    });

    it('requires authentication', function () {
        $user   = User::factory()->create();
        $domain = managementDomain($user);

        $response = $this->getJson("/api/email/domains/{$domain->id}/management/capabilities");

        $response->assertStatus(401);
    });
});

// ---------------------------------------------------------------------------
// GET /api/email/domains/{id}/management/dkim
// ---------------------------------------------------------------------------

describe('getDkim', function () {
    it('delegates to provider and returns dkim data', function () {
        $user   = User::factory()->create();
        $domain = managementDomain($user);

        $this->mock(MailgunProvider::class, function (MockInterface $mock) {
            $mock->makePartial();
            $mock->shouldReceive('getDkimKey')
                ->once()
                ->andReturn(['public_key' => 'abc123', 'dns_record' => 'v=DKIM1;']);
        });

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/email/domains/{$domain->id}/management/dkim");

        $response->assertStatus(200);
        $response->assertJsonFragment(['public_key' => 'abc123']);
    });

    it('maps provider 401 to 502', function () {
        $user   = User::factory()->create();
        $domain = managementDomain($user);

        $this->mock(MailgunProvider::class, function (MockInterface $mock) {
            $mock->makePartial();
            $mock->shouldReceive('getDkimKey')
                ->once()
                ->andThrow(new ProviderApiException('Unauthorized', 401));
        });

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/email/domains/{$domain->id}/management/dkim");

        $response->assertStatus(502);
    });
});

// ---------------------------------------------------------------------------
// POST /api/email/domains/{id}/management/dkim/rotate
// ---------------------------------------------------------------------------

describe('rotateDkim', function () {
    it('rotates dkim and audits the action', function () {
        $user   = User::factory()->create();
        $domain = managementDomain($user);

        $this->mock(MailgunProvider::class, function (MockInterface $mock) {
            $mock->makePartial();
            $mock->shouldReceive('rotateDkimKey')
                ->once()
                ->andReturn(['status' => 'rotated', 'public_key' => 'newkey']);
        });

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/email/domains/{$domain->id}/management/dkim/rotate");

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'rotated']);

        $domain->refresh();
        expect($domain->dkim_rotated_at)->not->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// GET /api/email/domains/{id}/management/webhooks
// ---------------------------------------------------------------------------

describe('listWebhooks', function () {
    it('returns webhook list from provider', function () {
        $user   = User::factory()->create();
        $domain = managementDomain($user);

        $this->mock(MailgunProvider::class, function (MockInterface $mock) {
            $mock->makePartial();
            $mock->shouldReceive('listWebhooks')
                ->once()
                ->andReturn(['delivered' => ['urls' => ['https://example.com/hook']]]);
        });

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/email/domains/{$domain->id}/management/webhooks");

        $response->assertStatus(200);
        $response->assertJsonStructure(['webhooks']);
    });
});

// ---------------------------------------------------------------------------
// GET /api/email/domains/{id}/management/events
// ---------------------------------------------------------------------------

describe('getEvents', function () {
    it('passes query filters to provider', function () {
        $user   = User::factory()->create();
        $domain = managementDomain($user);

        $this->mock(MailgunProvider::class, function (MockInterface $mock) {
            $mock->makePartial();
            $mock->shouldReceive('getEvents')
                ->once()
                ->with('example.com', \Mockery::subset(['event' => 'delivered']), \Mockery::any())
                ->andReturn(['items' => [], 'nextPage' => null]);
        });

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/email/domains/{$domain->id}/management/events?event=delivered");

        $response->assertStatus(200);
    });
});

// ---------------------------------------------------------------------------
// GET /api/email/domains/{id}/management/suppressions/{type}
// ---------------------------------------------------------------------------

describe('listSuppressions', function () {
    it('returns bounces list', function () {
        $user   = User::factory()->create();
        $domain = managementDomain($user);

        $this->mock(MailgunProvider::class, function (MockInterface $mock) {
            $mock->makePartial();
            $mock->shouldReceive('listBounces')
                ->once()
                ->andReturn(['items' => [['address' => 'bad@example.com']], 'nextPage' => null]);
        });

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/email/domains/{$domain->id}/management/suppressions/bounces");

        $response->assertStatus(200);
    });

    it('rejects invalid suppression type', function () {
        $user   = User::factory()->create();
        $domain = managementDomain($user);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/email/domains/{$domain->id}/management/suppressions/invalid");

        $response->assertStatus(422);
    });
});

// ---------------------------------------------------------------------------
// Deprecated /mailgun/ aliases still work (backward compat)
// ---------------------------------------------------------------------------

describe('mailgun alias routes', function () {
    it('old /mailgun/dkim route still resolves via ProviderManagementController', function () {
        $user   = User::factory()->create();
        $domain = managementDomain($user);

        $this->mock(MailgunProvider::class, function (MockInterface $mock) {
            $mock->makePartial();
            $mock->shouldReceive('getDkimKey')
                ->once()
                ->andReturn(['public_key' => 'legacykey']);
        });

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/email/domains/{$domain->id}/mailgun/dkim");

        $response->assertStatus(200);
        $response->assertJsonFragment(['public_key' => 'legacykey']);
    });

    it('old /mailgun/webhooks route still resolves', function () {
        $user   = User::factory()->create();
        $domain = managementDomain($user);

        $this->mock(MailgunProvider::class, function (MockInterface $mock) {
            $mock->makePartial();
            $mock->shouldReceive('listWebhooks')
                ->once()
                ->andReturn([]);
        });

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/email/domains/{$domain->id}/mailgun/webhooks");

        $response->assertStatus(200);
    });
});

// ---------------------------------------------------------------------------
// GET /api/email/provider/health
// ---------------------------------------------------------------------------

describe('checkHealth', function () {
    it('returns health status with provider query param', function () {
        $user = User::factory()->create();

        $this->mock(MailgunProvider::class, function (MockInterface $mock) {
            $mock->makePartial();
            $mock->shouldReceive('checkApiHealth')
                ->once()
                ->andReturn(true);
        });

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/email/provider/health?provider=mailgun');

        $response->assertStatus(200);
        $response->assertJsonStructure(['healthy', 'latency_ms', 'provider']);
        $response->assertJsonFragment(['provider' => 'mailgun', 'healthy' => true]);
    });

    it('returns 422 for unknown provider', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/email/provider/health?provider=unknown');

        $response->assertStatus(422);
    });
});
