<?php

use App\Models\EmailDomain;
use App\Models\Mailbox;
use App\Models\MailboxForward;
use App\Models\MailboxUser;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->domain = EmailDomain::create([
        'user_id' => $this->user->id,
        'name' => 'test.example.com',
        'provider' => 'mailgun',
        'is_verified' => true,
        'is_active' => true,
    ]);

    $this->mailbox = Mailbox::create([
        'user_id' => $this->user->id,
        'email_domain_id' => $this->domain->id,
        'address' => 'inbox',
        'is_active' => true,
    ]);

    MailboxUser::create([
        'mailbox_id' => $this->mailbox->id,
        'user_id' => $this->user->id,
        'role' => 'owner',
    ]);
});

describe('Mailbox Forward API', function () {

    it('returns null when no forward is configured', function () {
        $response = $this->actingAs($this->user)
            ->getJson("/api/email/mailboxes/{$this->mailbox->id}/forward");

        $response->assertOk()
            ->assertJson(['forward' => null]);
    });

    it('can create a forward via upsert', function () {
        $response = $this->actingAs($this->user)
            ->putJson("/api/email/mailboxes/{$this->mailbox->id}/forward", [
                'forward_to' => 'external@gmail.com',
                'keep_local_copy' => true,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('mailbox_forwards', [
            'mailbox_id' => $this->mailbox->id,
            'forward_to' => 'external@gmail.com',
            'keep_local_copy' => true,
            'is_active' => true,
        ]);
    });

    it('can update an existing forward', function () {
        MailboxForward::create([
            'user_id' => $this->user->id,
            'mailbox_id' => $this->mailbox->id,
            'forward_to' => 'old@gmail.com',
            'keep_local_copy' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/email/mailboxes/{$this->mailbox->id}/forward", [
                'forward_to' => 'new@gmail.com',
                'keep_local_copy' => false,
            ]);

        $response->assertOk();
        $this->assertDatabaseCount('mailbox_forwards', 1);
        $this->assertDatabaseHas('mailbox_forwards', [
            'forward_to' => 'new@gmail.com',
            'keep_local_copy' => false,
        ]);
    });

    it('can delete a forward', function () {
        MailboxForward::create([
            'user_id' => $this->user->id,
            'mailbox_id' => $this->mailbox->id,
            'forward_to' => 'external@gmail.com',
            'keep_local_copy' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/email/mailboxes/{$this->mailbox->id}/forward");

        $response->assertOk();
        $this->assertDatabaseCount('mailbox_forwards', 0);
    });

    it('validates forward_to is a valid email', function () {
        $response = $this->actingAs($this->user)
            ->putJson("/api/email/mailboxes/{$this->mailbox->id}/forward", [
                'forward_to' => 'not-an-email',
            ]);

        $response->assertUnprocessable();
    });

    it('denies access to non-owners', function () {
        $otherUser = User::factory()->create();
        MailboxUser::create([
            'mailbox_id' => $this->mailbox->id,
            'user_id' => $otherUser->id,
            'role' => 'viewer',
        ]);

        $response = $this->actingAs($otherUser)
            ->putJson("/api/email/mailboxes/{$this->mailbox->id}/forward", [
                'forward_to' => 'external@gmail.com',
            ]);

        $response->assertNotFound();
    });

    it('allows viewers to read forward config', function () {
        $otherUser = User::factory()->create();
        MailboxUser::create([
            'mailbox_id' => $this->mailbox->id,
            'user_id' => $otherUser->id,
            'role' => 'viewer',
        ]);

        MailboxForward::create([
            'user_id' => $this->user->id,
            'mailbox_id' => $this->mailbox->id,
            'forward_to' => 'external@gmail.com',
            'keep_local_copy' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($otherUser)
            ->getJson("/api/email/mailboxes/{$this->mailbox->id}/forward");

        $response->assertOk();
    });
});
