<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Email;
use App\Models\EmailDomain;
use App\Models\EmailLabel;
use App\Models\EmailRecipient;
use App\Models\EmailThread;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DemoEmailSeeder extends Seeder
{
    /**
     * Seed 200 demo emails for the first user in the system.
     * Creates a demo domain, mailbox, labels, threads, and contacts.
     *
     * Usage: docker exec selfmx-dev bash -c "cd /var/www/html/backend && php artisan db:seed --class=DemoEmailSeeder"
     */
    public function run(): void
    {
        $user = User::first();
        if (!$user) {
            $this->command->error('No user found. Create a user first.');
            return;
        }

        $this->command->info("Seeding demo emails for user: {$user->email}");

        // Create demo domain + mailbox
        $domain = $this->createDomain($user);
        $mailbox = $this->createMailbox($user, $domain);

        // Create labels
        $labels = $this->createLabels($user);

        // Build sender pool
        $senders = self::senders();

        // Create contacts from senders
        $contacts = $this->createContacts($user, $senders);

        // Seed threaded conversations + standalone emails
        $this->seedEmails($user, $mailbox, $domain, $labels, $senders);

        $count = Email::where('user_id', $user->id)->count();
        $this->command->info("Done! {$count} emails seeded.");
    }

    private function createDomain(User $user): EmailDomain
    {
        return EmailDomain::firstOrCreate(
            ['name' => 'demo.selfmx.local'],
            [
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'provider_domain_id' => 'demo-domain-id',
                'provider_config' => ['api_key' => 'demo-key'],
                'is_verified' => true,
                'verified_at' => now(),
                'is_active' => true,
            ]
        );
    }

    private function createMailbox(User $user, EmailDomain $domain): Mailbox
    {
        return Mailbox::firstOrCreate(
            ['email_domain_id' => $domain->id, 'address' => 'me'],
            [
                'user_id' => $user->id,
                'display_name' => $user->name,
                'is_active' => true,
            ]
        );
    }

    private function createLabels(User $user): array
    {
        $defs = [
            ['name' => 'Work', 'color' => '#3b82f6', 'sort_order' => 1],
            ['name' => 'Personal', 'color' => '#10b981', 'sort_order' => 2],
            ['name' => 'Finance', 'color' => '#f59e0b', 'sort_order' => 3],
            ['name' => 'Travel', 'color' => '#8b5cf6', 'sort_order' => 4],
            ['name' => 'Newsletters', 'color' => '#6b7280', 'sort_order' => 5],
        ];

        $labels = [];
        foreach ($defs as $def) {
            $labels[$def['name']] = EmailLabel::firstOrCreate(
                ['user_id' => $user->id, 'name' => $def['name']],
                $def
            );
        }

        return $labels;
    }

    private function createContacts(User $user, array $senders): array
    {
        $contacts = [];
        foreach ($senders as $sender) {
            $contacts[$sender['address']] = Contact::firstOrCreate(
                ['user_id' => $user->id, 'email_address' => $sender['address']],
                [
                    'display_name' => $sender['name'],
                    'email_count' => rand(1, 30),
                    'last_emailed_at' => now()->subDays(rand(0, 30)),
                ]
            );
        }

        return $contacts;
    }

    /**
     * Pool of realistic external senders.
     */
    private static function senders(): array
    {
        return [
            ['name' => 'Alice Chen', 'address' => 'alice.chen@acme-corp.com'],
            ['name' => 'Bob Martinez', 'address' => 'bob.martinez@globex.io'],
            ['name' => 'Carol Nguyen', 'address' => 'carol@designstudio.co'],
            ['name' => 'David Kim', 'address' => 'david.kim@techstart.dev'],
            ['name' => 'Emma Wilson', 'address' => 'emma.w@university.edu'],
            ['name' => 'Frank Osei', 'address' => 'frank.osei@financeplus.com'],
            ['name' => 'Grace Liu', 'address' => 'grace@openstack.org'],
            ['name' => 'Hassan Ali', 'address' => 'hassan.ali@cloudnine.io'],
            ['name' => 'Iris Johansson', 'address' => 'iris@nordicdesign.se'],
            ['name' => 'James O\'Brien', 'address' => 'james@devtools.com'],
            ['name' => 'Karen Patel', 'address' => 'karen.patel@lawfirm.co'],
            ['name' => 'Leo Tanaka', 'address' => 'leo@pixelcraft.jp'],
            ['name' => 'Maria Santos', 'address' => 'maria.santos@healthcare.org'],
            ['name' => 'Nadia Petrova', 'address' => 'nadia@datascience.ai'],
            ['name' => 'Oscar Reyes', 'address' => 'oscar.reyes@logistics.com'],
            ['name' => 'GitHub', 'address' => 'notifications@github.com'],
            ['name' => 'Stripe', 'address' => 'receipts@stripe.com'],
            ['name' => 'AWS Notifications', 'address' => 'no-reply@aws.amazon.com'],
            ['name' => 'Hacker News', 'address' => 'hn@ycombinator.com'],
            ['name' => 'Docker Hub', 'address' => 'noreply@docker.com'],
        ];
    }

    /**
     * Master list of email thread scenarios with realistic content.
     */
    private function seedEmails(User $user, Mailbox $mailbox, EmailDomain $domain, array $labels, array $senders): void
    {
        $myAddress = $mailbox->address . '@' . $domain->name;
        $baseTime = now()->subDays(14);
        $emailIndex = 0;

        // --- Threaded conversations (multi-message) ---
        $threads = self::threadedConversations();
        foreach ($threads as $threadDef) {
            $thread = EmailThread::create([
                'user_id' => $user->id,
                'subject' => $threadDef['subject'],
                'last_message_at' => $baseTime->copy()->addMinutes($emailIndex * 10 + count($threadDef['messages']) * 10),
                'message_count' => count($threadDef['messages']),
            ]);

            $prevMessageId = null;
            $references = [];

            foreach ($threadDef['messages'] as $i => $msg) {
                $sentAt = $baseTime->copy()->addMinutes($emailIndex * 10);
                $sender = $senders[array_rand($senders)];
                $isInbound = $msg['direction'] === 'inbound';
                $fromAddr = $isInbound ? $sender['address'] : $myAddress;
                $fromName = $isInbound ? $sender['name'] : $user->name;
                $toAddr = $isInbound ? $myAddress : $sender['address'];
                $toName = $isInbound ? $user->name : $sender['name'];

                // Use specific sender if specified
                if (isset($msg['sender_index'])) {
                    $sender = $senders[$msg['sender_index']];
                    if ($isInbound) {
                        $fromAddr = $sender['address'];
                        $fromName = $sender['name'];
                    } else {
                        $toAddr = $sender['address'];
                        $toName = $sender['name'];
                    }
                }

                $messageId = '<' . Str::random(32) . '@' . ($isInbound ? explode('@', $fromAddr)[1] : $domain->name) . '>';

                $email = Email::create([
                    'user_id' => $user->id,
                    'mailbox_id' => $mailbox->id,
                    'message_id' => $messageId,
                    'thread_id' => $thread->id,
                    'provider_message_id' => 'demo-' . Str::random(16),
                    'direction' => $msg['direction'],
                    'from_address' => $fromAddr,
                    'from_name' => $fromName,
                    'subject' => ($i > 0 ? 'Re: ' : '') . $threadDef['subject'],
                    'body_text' => $msg['body'],
                    'body_html' => '<p>' . nl2br(e($msg['body'])) . '</p>',
                    'headers' => [],
                    'in_reply_to' => $prevMessageId,
                    'references' => $references ? implode(' ', $references) : null,
                    'is_read' => $msg['is_read'] ?? ($emailIndex < 180),
                    'is_starred' => $msg['is_starred'] ?? false,
                    'is_draft' => false,
                    'is_spam' => false,
                    'is_trashed' => false,
                    'delivery_status' => $isInbound ? null : 'delivered',
                    'spam_score' => $isInbound ? round(rand(0, 15) / 10, 1) : null,
                    'sent_at' => $sentAt,
                ]);

                EmailRecipient::create([
                    'email_id' => $email->id,
                    'type' => 'to',
                    'address' => $toAddr,
                    'name' => $toName,
                ]);

                // Occasionally add CC
                if (isset($msg['cc'])) {
                    $ccSender = $senders[$msg['cc']];
                    EmailRecipient::create([
                        'email_id' => $email->id,
                        'type' => 'cc',
                        'address' => $ccSender['address'],
                        'name' => $ccSender['name'],
                    ]);
                }

                // Assign labels
                if (isset($threadDef['labels'])) {
                    $labelIds = array_filter(array_map(
                        fn($name) => ($labels[$name] ?? null)?->id,
                        $threadDef['labels']
                    ));
                    if ($labelIds) {
                        $email->labels()->syncWithoutDetaching($labelIds);
                    }
                }

                $references[] = $messageId;
                $prevMessageId = $messageId;
                $emailIndex++;
            }

            // Update thread last_message_at to actual last email
            $thread->update(['last_message_at' => $baseTime->copy()->addMinutes(($emailIndex - 1) * 10)]);
        }

        // --- Standalone emails (fill to 200) ---
        $remaining = 200 - $emailIndex;
        $standaloneSubjects = self::standaloneSubjects();

        for ($i = 0; $i < $remaining; $i++) {
            $sentAt = $baseTime->copy()->addMinutes($emailIndex * 10);
            $isInbound = $i % 5 !== 0; // 80% inbound, 20% outbound
            $sender = $senders[array_rand($senders)];
            $subjectDef = $standaloneSubjects[$i % count($standaloneSubjects)];

            $fromAddr = $isInbound ? $sender['address'] : $myAddress;
            $fromName = $isInbound ? $sender['name'] : $user->name;
            $toAddr = $isInbound ? $myAddress : $sender['address'];
            $toName = $isInbound ? $user->name : $sender['name'];

            $thread = EmailThread::create([
                'user_id' => $user->id,
                'subject' => $subjectDef['subject'],
                'last_message_at' => $sentAt,
                'message_count' => 1,
            ]);

            $messageId = '<' . Str::random(32) . '@' . ($isInbound ? explode('@', $fromAddr)[1] : $domain->name) . '>';

            $email = Email::create([
                'user_id' => $user->id,
                'mailbox_id' => $mailbox->id,
                'message_id' => $messageId,
                'thread_id' => $thread->id,
                'provider_message_id' => 'demo-' . Str::random(16),
                'direction' => $isInbound ? 'inbound' : 'outbound',
                'from_address' => $fromAddr,
                'from_name' => $fromName,
                'subject' => $subjectDef['subject'],
                'body_text' => $subjectDef['body'],
                'body_html' => '<p>' . nl2br(e($subjectDef['body'])) . '</p>',
                'headers' => [],
                'in_reply_to' => null,
                'references' => null,
                'is_read' => $emailIndex < 180,
                'is_starred' => $i % 11 === 0,
                'is_draft' => false,
                'is_spam' => $i === $remaining - 1, // 1 spam
                'is_trashed' => $i === $remaining - 2, // 1 trashed
                'delivery_status' => $isInbound ? null : 'delivered',
                'spam_score' => $isInbound ? round(rand(0, 20) / 10, 1) : null,
                'sent_at' => $sentAt,
            ]);

            EmailRecipient::create([
                'email_id' => $email->id,
                'type' => 'to',
                'address' => $toAddr,
                'name' => $toName,
            ]);

            // Assign label based on subject category
            if (isset($subjectDef['label']) && isset($labels[$subjectDef['label']])) {
                $email->labels()->attach($labels[$subjectDef['label']]->id);
            }

            $emailIndex++;
        }
    }

    /**
     * Multi-message thread definitions with realistic back-and-forth.
     */
    private static function threadedConversations(): array
    {
        return [
            [
                'subject' => 'Q1 Planning Meeting — Agenda & Prep',
                'labels' => ['Work'],
                'messages' => [
                    ['direction' => 'inbound', 'sender_index' => 0, 'body' => "Hi,\n\nI've drafted the agenda for our Q1 planning meeting next Tuesday. Key topics:\n\n1. Revenue targets and pipeline review\n2. Engineering roadmap priorities\n3. Hiring plan for Q1-Q2\n4. Budget allocation\n\nPlease review and add any items you'd like to discuss. I'll send the calendar invite today.\n\nBest,\nAlice", 'is_read' => true],
                    ['direction' => 'outbound', 'sender_index' => 0, 'body' => "Thanks Alice,\n\nLooks good. Can we also add a 15-minute slot for the infrastructure migration update? The team has some blockers they need visibility on.\n\nAlso, should we invite the new PM from the product team?", 'is_read' => true],
                    ['direction' => 'inbound', 'sender_index' => 0, 'body' => "Great call — added the infra slot after item 3. And yes, I'll add Sarah from product to the invite.\n\nUpdated agenda attached. See you Tuesday!", 'is_read' => true],
                ],
            ],
            [
                'subject' => 'Bug: Login page crashes on Safari 17',
                'labels' => ['Work'],
                'messages' => [
                    ['direction' => 'inbound', 'sender_index' => 3, 'body' => "Hey,\n\nWe've got multiple reports of the login page crashing on Safari 17.2+. Looks like it's related to the WebAuthn polyfill we added last sprint.\n\nStack trace points to credentials.get() throwing a NotAllowedError before the user even interacts with the prompt.\n\nCan you take a look? I've tagged the issue as P1.", 'is_read' => true],
                    ['direction' => 'outbound', 'sender_index' => 3, 'body' => "Found it. Safari 17.2 changed how they handle conditional mediation for passkeys. The polyfill assumes the old behavior.\n\nI've got a fix — basically we need to feature-detect PublicKeyCredential.isConditionalMediationAvailable() before calling get().\n\nPR incoming.", 'is_read' => true],
                    ['direction' => 'inbound', 'sender_index' => 3, 'body' => "Nice catch! PR looks clean. I'll get QA to verify on the Safari builds we have.\n\nShould we also add a Safari-specific test to the E2E suite?", 'is_read' => true],
                    ['direction' => 'outbound', 'sender_index' => 3, 'body' => "Already added — check the second commit. It runs a conditional mediation smoke test against WebKit.\n\nMerging once CI is green.", 'is_read' => true],
                ],
            ],
            [
                'subject' => 'Invoice #4821 — January Services',
                'labels' => ['Finance'],
                'messages' => [
                    ['direction' => 'inbound', 'sender_index' => 5, 'body' => "Hi,\n\nPlease find attached Invoice #4821 for January consulting services.\n\nAmount: $12,500.00\nDue date: February 15, 2026\nPayment terms: Net 30\n\nBank details are on the invoice. Let me know if you have any questions.\n\nRegards,\nFrank Osei\nFinancePlus Consulting", 'is_read' => true],
                    ['direction' => 'outbound', 'sender_index' => 5, 'body' => "Hi Frank,\n\nReceived, thanks. Quick question — the line item for \"additional research hours\" (8 hrs @ $150/hr) wasn't in the original SOW. Can you provide a breakdown of what those hours covered?\n\nI want to get this approved before the due date.", 'is_read' => true],
                    ['direction' => 'inbound', 'sender_index' => 5, 'body' => "Good catch. Those were the extra competitive analysis hours your team requested on Jan 15th. I have the email approval from Karen — let me forward it to you.\n\nI'll also update the invoice description to reference the approval for your records.", 'is_read' => true],
                ],
            ],
            [
                'subject' => 'Re: Docker build failing on ARM64',
                'labels' => ['Work'],
                'messages' => [
                    ['direction' => 'inbound', 'sender_index' => 7, 'body' => "The multi-arch build is failing again on our ARM64 runners. Error:\n\nexec /usr/local/bin/docker-entrypoint.sh: exec format error\n\nI think the base image we pulled doesn't have ARM manifests. We might need to pin to a specific digest or switch to the official alpine-based image.", 'is_read' => true],
                    ['direction' => 'outbound', 'sender_index' => 7, 'body' => "You're right — the node:20-slim image we use doesn't publish ARM64 variants for every patch release. Let me switch us to node:20-alpine which has better multi-arch support.\n\nI'll also add a CI step that validates the manifest includes both amd64 and arm64.", 'is_read' => true],
                    ['direction' => 'inbound', 'sender_index' => 7, 'body' => "Sounds good. While you're at it, can you also check if our Python sidecar container has the same issue? We deploy them together.", 'is_read' => true],
                    ['direction' => 'outbound', 'sender_index' => 7, 'body' => "Checked — Python image is fine, it's using the official multi-arch python:3.12-slim. Only the Node container needed updating.\n\nPR is up: #847. Build is green on both architectures now.", 'is_read' => true],
                    ['direction' => 'inbound', 'sender_index' => 7, 'cc' => 3, 'body' => "Merged! Thanks for the quick turnaround. I've also pinged David to update the deployment docs with the new base image.", 'is_read' => true],
                ],
            ],
            [
                'subject' => 'Apartment viewing Saturday?',
                'labels' => ['Personal'],
                'messages' => [
                    ['direction' => 'inbound', 'sender_index' => 2, 'body' => "Hey!\n\nI found a really nice 2-bedroom near the park. The landlord has open viewings Saturday 10am-12pm. Want to come check it out?\n\nIt's $1,850/mo, includes parking. Photos look great but you never know until you see it in person.", 'is_read' => true],
                    ['direction' => 'outbound', 'sender_index' => 2, 'body' => "Oh nice! That's actually in my budget. I'm free Saturday morning — send me the address and I'll meet you there at 10.\n\nDoes it allow pets? That's my dealbreaker.", 'is_read' => true],
                    ['direction' => 'inbound', 'sender_index' => 2, 'body' => "Yes! Cats and small dogs allowed, $25/mo pet rent. Address is 742 Evergreen Terrace, Apt 3B.\n\nSee you at 10! Bring questions for the landlord — she's apparently pretty responsive.", 'is_read' => false],
                ],
            ],
            [
                'subject' => 'Conference talk proposal: Building Resilient Distributed Systems',
                'labels' => ['Work'],
                'messages' => [
                    ['direction' => 'inbound', 'sender_index' => 6, 'body' => "Hi,\n\nThe CFP for KubeCon EU 2026 closes in 2 weeks. I think you should submit a talk on the circuit breaker patterns we implemented. The real-world failure data we collected would make for a compelling case study.\n\nWant to co-present? I can cover the Kubernetes operator side if you handle the application-layer patterns.", 'is_read' => true],
                    ['direction' => 'outbound', 'sender_index' => 6, 'body' => "I'd love to! Great idea. Let me draft an abstract this week. Working title:\n\n\"From Theory to Production: Circuit Breakers That Actually Work\"\n\nI'll share a Google Doc for us to collaborate on. We should include those latency percentile charts from the November incident.", 'is_read' => true],
                    ['direction' => 'inbound', 'sender_index' => 6, 'body' => "Love the title. I'll dig up the Grafana dashboards from the incident. Also — should we mention the false positive issue we had with the health checks? That's the kind of real-world nuance the audience would appreciate.\n\nLet's sync Thursday to finalize the abstract.", 'is_read' => true],
                ],
            ],
            [
                'subject' => 'Flight options for Tokyo trip',
                'labels' => ['Travel'],
                'messages' => [
                    ['direction' => 'inbound', 'sender_index' => 8, 'body' => "Here are the best options I found for the Tokyo trip in April:\n\n1. ANA direct LAX→NRT, Apr 12-19, $1,240 RT\n2. JAL via SFO→HND, Apr 12-19, $1,180 RT (1 stop)\n3. United LAX→NRT, Apr 11-19, $980 RT (1 stop, longer layover)\n\nI'd recommend option 1 for the direct flight. Option 3 is cheapest but the 6hr layover in Seoul isn't great.\n\nShould I book?", 'is_read' => true],
                    ['direction' => 'outbound', 'sender_index' => 8, 'body' => "Let's go with option 1 — the direct ANA flight. Worth the extra $260 to avoid layovers.\n\nCan you also check hotel options near Shibuya? Prefer something walkable to the conference venue.", 'is_read' => true],
                    ['direction' => 'inbound', 'sender_index' => 8, 'body' => "Booked the ANA flights! Confirmation code: ANA-7X92K.\n\nFor hotels near Shibuya, I found:\n- Shibuya Stream Excel: $185/night, 5 min walk to venue\n- Cerulean Tower Tokyu: $220/night, directly connected\n- Sequence Miyashita Park: $155/night, 10 min walk\n\nLet me know your pick and I'll reserve.", 'is_read' => false],
                ],
            ],
            [
                'subject' => 'Database migration plan — PostgreSQL upgrade',
                'labels' => ['Work'],
                'messages' => [
                    ['direction' => 'inbound', 'sender_index' => 13, 'body' => "Team,\n\nI've put together the migration plan for upgrading from PostgreSQL 14 to 16. Key steps:\n\n1. Set up PG 16 replica with logical replication\n2. Run parallel query benchmarks (I've seen 20-30% improvement on our workload)\n3. Cutover during maintenance window with <30s downtime\n\nThe main risk is the jsonb indexing changes in PG 16 — we need to rebuild 3 GIN indexes.\n\nFull doc: https://internal.docs/pg16-migration\n\nThoughts?", 'is_read' => true, 'cc' => 7],
                    ['direction' => 'outbound', 'sender_index' => 13, 'body' => "Plan looks solid. Two questions:\n\n1. Have you tested our custom PL/pgSQL functions against PG 16? We had issues last time with the recursive CTEs in the reporting queries.\n2. What's the rollback plan if we hit issues post-cutover?\n\nAlso +1 on logical replication over pg_upgrade. Gives us a clean fallback.", 'is_read' => true],
                    ['direction' => 'inbound', 'sender_index' => 13, 'body' => "Good questions:\n\n1. Yes — all 47 functions pass on PG 16. The recursive CTE issue from PG 13→14 was fixed upstream. I've added the test results to the doc.\n2. Rollback: keep the PG 14 instance warm for 48 hours post-cutover. If anything breaks, we flip the connection string back. Data sync continues via logical replication in reverse.\n\nProposed date: March 8, 2am-4am UTC. Works for everyone?", 'is_read' => false],
                ],
            ],
            [
                'subject' => 'Freelance project: Website redesign quote',
                'labels' => ['Finance'],
                'messages' => [
                    ['direction' => 'inbound', 'sender_index' => 2, 'body' => "Hi!\n\nThanks for the intro to the bakery client. I had a call with them yesterday. They want a full website redesign — current site is a WordPress mess from 2019.\n\nScope:\n- Modern responsive design (Next.js)\n- Online ordering integration\n- Menu management CMS\n- Photo gallery\n\nI'm thinking $8,500 for the full project, 6-week timeline. Does that sound reasonable for this market?\n\nCarol", 'is_read' => true],
                    ['direction' => 'outbound', 'sender_index' => 2, 'body' => "That scope sounds right for $8,500-10,000 in this market. For a bakery with online ordering, I'd probably quote closer to $10K because the ordering integration always has hidden complexity (payment processing, order notifications, kitchen printer integration, etc.).\n\nMake sure to spec out the ordering part really clearly in the SOW. That's where scope creep always hits.", 'is_read' => true],
                ],
            ],
            [
                'subject' => 'Your AWS bill is higher than usual',
                'labels' => ['Work', 'Finance'],
                'messages' => [
                    ['direction' => 'inbound', 'sender_index' => 17, 'body' => "Hello,\n\nYour AWS account ending in 4829 has charges that are 47% higher than your average for the billing period.\n\nCurrent month-to-date: $3,847.22\nPrevious month total: $2,612.15\n\nTop cost increases:\n- EC2: +$580 (new instances in us-west-2)\n- S3: +$340 (increased data transfer)\n- RDS: +$290 (storage autoscaling)\n\nReview your usage in the AWS Cost Explorer.\n\nAmazon Web Services", 'is_read' => true],
                    ['direction' => 'outbound', 'sender_index' => 7, 'body' => "Hassan — did you see the AWS alert? Our bill jumped 47%. Looks like the new staging environment instances in us-west-2 are the main culprit. Also the S3 transfer costs spiked — might be related to the CDN migration.\n\nCan you check if we left any test instances running?", 'is_read' => true],
                    ['direction' => 'inbound', 'sender_index' => 7, 'body' => "Found the issue — we had 3 c5.2xlarge instances from load testing last week that nobody terminated. That's ~$600/mo right there. I've shut them down.\n\nThe S3 spike is from the log export job — it was writing uncompressed JSON. I've updated it to use gzip. Should see the savings next billing cycle.", 'is_read' => true],
                ],
            ],
            [
                'subject' => 'Book club: Next month\'s pick',
                'labels' => ['Personal'],
                'messages' => [
                    ['direction' => 'inbound', 'sender_index' => 4, 'body' => "Hey everyone!\n\nTime to vote on next month's book. Options:\n\n1. \"Project Hail Mary\" by Andy Weir\n2. \"Piranesi\" by Susanna Clarke\n3. \"The Kaiju Preservation Society\" by John Scalzi\n\nReply with your vote by Friday! We'll meet March 20 at the usual spot.", 'is_read' => true],
                    ['direction' => 'outbound', 'sender_index' => 4, 'body' => "My vote is #1 — Project Hail Mary. I've been meaning to read it forever and this is the push I need.\n\nAlso, can we start 30 min later this time? 7:30 instead of 7? Last time I barely made it from work.", 'is_read' => true],
                    ['direction' => 'inbound', 'sender_index' => 4, 'body' => "Project Hail Mary it is — it won 4-2! 7:30 works for everyone too.\n\nSee you all March 20 at Bean & Gone. I'll bring the discussion questions.\n\nHappy reading!", 'is_read' => true],
                ],
            ],
            [
                'subject' => 'GitHub Actions: Build failed for main',
                'labels' => ['Work'],
                'messages' => [
                    ['direction' => 'inbound', 'sender_index' => 15, 'body' => "Build #1847 failed for main (commit abc123f)\n\nFailed jobs:\n- test-integration (ubuntu-latest)\n\nError: Connection refused at localhost:5432\n\nThe PostgreSQL service container failed to start. This may be a transient infrastructure issue.\n\nView run: https://github.com/org/repo/actions/runs/1847", 'is_read' => true],
                    ['direction' => 'inbound', 'sender_index' => 15, 'body' => "Build #1848 succeeded for main (commit abc123f)\n\nAll jobs passed. The previous failure was transient.\n\nView run: https://github.com/org/repo/actions/runs/1848", 'is_read' => true],
                ],
            ],
        ];
    }

    /**
     * Standalone single-email subjects and bodies.
     */
    private static function standaloneSubjects(): array
    {
        return [
            ['subject' => 'Weekly team standup notes — Feb 24', 'body' => "Hi team,\n\nHere are the notes from today's standup:\n\n- Backend: API rate limiting shipped, starting on webhook retry logic\n- Frontend: Dashboard redesign at 80%, need design review for mobile\n- DevOps: Terraform modules refactored, staging environment updated\n- QA: 12 bugs filed, 8 closed, 2 P1s remaining\n\nBlockers: None reported.\n\nNext standup: Wednesday 10am.", 'label' => 'Work'],
            ['subject' => 'Your Stripe payout has been initiated', 'body' => "A payout of $4,250.00 has been initiated to your bank account ending in 7823.\n\nPayout ID: po_1NkJ2x...\nExpected arrival: February 26, 2026\n\nView details in your Stripe Dashboard.", 'label' => 'Finance'],
            ['subject' => 'Invitation: Design Review — Mobile App v3', 'body' => "You've been invited to a design review session.\n\nWhen: Thursday, Feb 27 at 2:00 PM EST\nWhere: Zoom (link in calendar invite)\nDuration: 45 minutes\n\nAgenda:\n- Walk through updated mobile navigation\n- Review new onboarding flow mockups\n- Discuss accessibility improvements\n\nPlease review the Figma file before the meeting.", 'label' => 'Work'],
            ['subject' => 'New comment on your pull request #312', 'body' => "carol-nguyen commented on your pull request:\n\nLooks good overall! One suggestion: the error handling in the retry logic could use exponential backoff instead of fixed intervals. Happy to pair on this if you want.\n\nAlso, minor nit: line 47 has a TODO that should probably be resolved before merge.", 'label' => 'Work'],
            ['subject' => 'Receipt: Annual domain renewal — selfmx.com', 'body' => "Your domain selfmx.com has been renewed.\n\nDomain: selfmx.com\nRenewed for: 1 year\nNew expiry: March 1, 2027\nAmount charged: $12.99\n\nManage your domains at your registrar dashboard.", 'label' => 'Finance'],
            ['subject' => 'Lunch tomorrow?', 'body' => "Hey! I'm going to be in your neighborhood tomorrow for a meeting. Want to grab lunch? I was thinking that new ramen place on 5th — heard great things.\n\nFree between 12-2pm. Let me know!", 'label' => 'Personal'],
            ['subject' => 'Security alert: New sign-in from Chrome on Windows', 'body' => "We noticed a new sign-in to your account.\n\nDevice: Chrome on Windows\nLocation: San Francisco, CA\nTime: February 25, 2026 at 3:42 PM PST\nIP: 203.0.113.42\n\nIf this was you, no action is needed. If you don't recognize this activity, please reset your password immediately.", 'label' => 'Work'],
            ['subject' => 'Your Docker Hub image was updated', 'body' => "The image myorg/api-server:latest was pushed to Docker Hub.\n\nPushed by: ci-bot\nDigest: sha256:a1b2c3d4...\nCompressed size: 247 MB\nArchitectures: linux/amd64, linux/arm64\n\nView image details on Docker Hub.", 'label' => 'Work'],
            ['subject' => 'Reminder: Dentist appointment Thursday 9am', 'body' => "This is a reminder of your upcoming appointment:\n\nDr. Sarah Mitchell, DDS\nThursday, February 27, 2026 at 9:00 AM\nDuration: 1 hour (cleaning + checkup)\n\nPlease arrive 10 minutes early. If you need to reschedule, call us at (555) 123-4567.", 'label' => 'Personal'],
            ['subject' => 'This Week in Tech: AI coding assistants, Rust in the kernel, and more', 'body' => "This week's highlights:\n\n1. GitHub announced Copilot Workspace GA — now supports multi-file editing with plan-and-execute\n2. Linux 6.8 merges more Rust drivers: NVMe and network stack\n3. PostgreSQL 17 beta adds incremental backup and JSON_TABLE\n4. Deno 2.1 ships with npm compatibility improvements\n5. The WebAssembly Component Model reaches 1.0\n\nRead more at the full newsletter.", 'label' => 'Newsletters'],
            ['subject' => 'RE: Contract renewal discussion', 'body' => "Hi,\n\nI've reviewed the renewal terms. The 15% rate increase is steep — our budget for this line item only accounts for a 5-8% increase.\n\nCan we schedule a call to discuss? I'd like to explore a multi-year commitment in exchange for a lower rate. We've been a customer for 3 years and I think there's room for a win-win.\n\nAvailable Tuesday or Wednesday afternoon.", 'label' => 'Finance'],
            ['subject' => 'Recommended: System Design Interview — An Insider\'s Guide', 'body' => "Based on your reading history, you might enjoy:\n\n\"System Design Interview — An Insider's Guide\" by Alex Xu\n\nRating: 4.6/5 (12,847 ratings)\nFormat: Paperback, Kindle\n\nCovers: rate limiters, chat systems, notification systems, news feeds, and more. Great prep for senior engineering interviews.\n\nView on our platform.", 'label' => 'Newsletters'],
            ['subject' => 'Your package has been delivered', 'body' => "Your package was delivered today at 2:14 PM.\n\nOrder #: 7829-4561-2233\nCarrier: UPS\nTracking: 1Z999AA10123456784\nLeft at: Front door\n\nDelivery photo attached.\n\nIf you didn't receive this package, please contact us.", 'label' => 'Personal'],
            ['subject' => 'Sprint retrospective action items', 'body' => "Team,\n\nHere are the action items from today's retro:\n\nWhat went well:\n- Shipped the notification system 2 days early\n- Zero P1 incidents this sprint\n\nWhat to improve:\n- [ ] Set up automated performance benchmarks (assigned: David)\n- [ ] Create runbook for the new webhook system (assigned: you)\n- [ ] Review and update on-call rotation (assigned: Hassan)\n\nDeadline: End of next sprint. Let's hold each other accountable!", 'label' => 'Work'],
            ['subject' => 'Hacker News Daily Digest', 'body' => "Today's top stories:\n\n1. Show HN: I built a self-hosted email server in Rust (487 points)\n2. SQLite is now the most deployed software in the world (392 points)\n3. Why we switched from Kubernetes to plain Docker Compose (341 points)\n4. Ask HN: How do you manage your personal finances? (289 points)\n5. The unreasonable effectiveness of simple code reviews (256 points)\n\nRead and discuss on Hacker News.", 'label' => 'Newsletters'],
            ['subject' => 'Can you review my resume?', 'body' => "Hey,\n\nI'm updating my resume for some senior roles I'm applying to. Would you mind taking a look? I'm not sure if I should emphasize the architecture work or the team leadership more.\n\nI've attached the current draft. No rush — whenever you get a chance this week would be great.\n\nThanks!", 'label' => 'Personal'],
            ['subject' => 'Terraform plan output — infrastructure changes', 'body' => "Terraform detected the following changes:\n\nPlan: 3 to add, 1 to change, 0 to destroy.\n\n+ aws_elasticache_cluster.sessions (Redis 7.x)\n+ aws_security_group.redis_sg\n+ aws_subnet_group.redis_subnets\n~ aws_ecs_service.api (force new deployment)\n\nApply this plan? Reply with approval or review the full diff in the PR.", 'label' => 'Work'],
            ['subject' => 'Happy birthday! 🎉', 'body' => "Happy birthday!\n\nHope you have an amazing day. Let's celebrate this weekend — dinner's on me. That Italian place you've been wanting to try?\n\nEnjoy your day!", 'label' => 'Personal'],
            ['subject' => 'Tax documents ready for download', 'body' => "Your 2025 tax documents are now available.\n\nDocuments ready:\n- W-2 from TechCorp Inc.\n- 1099-INT from First National Bank\n- 1099-DIV from Vanguard\n\nDocuments are available for download until April 15, 2026. We recommend downloading and storing them securely.\n\nAccess your tax center to download.", 'label' => 'Finance'],
            ['subject' => 'Outage postmortem: February 20 API degradation', 'body' => "Postmortem: API Degradation, Feb 20 2026, 14:22-15:47 UTC\n\nImpact: 23% of API requests returned 503 for 85 minutes\nRoot cause: Connection pool exhaustion due to slow queries from unindexed JOIN\nResolution: Added missing index on orders.customer_id, increased pool size from 20 to 50\n\nTimeline:\n14:22 — PagerDuty alert fired\n14:28 — On-call engineer identified connection pool saturation\n14:45 — Slow query identified via pg_stat_statements\n15:15 — Index deployed to production\n15:47 — All metrics returned to normal\n\nAction items:\n- Add query performance regression tests\n- Set up connection pool monitoring dashboard\n- Review all JOINs without covering indexes", 'label' => 'Work'],
            ['subject' => 'New follower on your blog', 'body' => "Someone new is following your blog!\n\nNew follower: @devops_daily (2,847 followers)\n\nYour most popular recent post: \"Why I Switched from Nginx to Caddy\" (1,247 views this week)\n\nKeep writing great content!", 'label' => 'Newsletters'],
            ['subject' => 'Gym membership renewal confirmation', 'body' => "Your gym membership has been renewed.\n\nMember: Your Account\nPlan: Premium (24/7 access + classes)\nBilling period: March 1 - March 31, 2026\nAmount: $49.99/month\n\nUpcoming classes you've bookmarked:\n- Monday 7am: HIIT Circuit\n- Wednesday 6pm: Yoga Flow\n- Saturday 9am: Spin Class", 'label' => 'Personal'],
            ['subject' => 'Meeting notes: Architecture review — Event sourcing proposal', 'body' => "Notes from today's architecture review:\n\nProposal: Migrate the order service from CRUD to event sourcing\n\nPros discussed:\n- Full audit trail for compliance\n- Easy to rebuild read models\n- Supports temporal queries\n\nCons discussed:\n- Complexity increase for the team\n- Event schema evolution is non-trivial\n- Current team has no ES experience\n\nDecision: Proof of concept in Q2, limited to the order service. Revisit for broader adoption in Q3.\n\nNext step: David to set up the EventStoreDB sandbox.", 'label' => 'Work'],
            ['subject' => 'Your electricity bill is ready', 'body' => "Your February electricity bill is available.\n\nAccount: 4829-7712\nBilling period: Feb 1-28, 2026\nUsage: 847 kWh\nAmount due: $127.35\nDue date: March 15, 2026\n\nCompared to last month: Usage down 12%, likely due to milder temperatures.\n\nPay online or set up autopay at the utility portal.", 'label' => 'Finance'],
            ['subject' => 'Feedback on your conference talk', 'body' => "Hi,\n\nJust wanted to say your talk at DevConf on \"Scaling WebSockets to 1M Connections\" was excellent. The part about kernel tuning and epoll optimization was particularly useful.\n\nWould you be open to doing a workshop version? Our engineering team of 40 would love a deeper dive. We could host it at our office in Austin.\n\nLet me know if you're interested and we can discuss logistics.", 'label' => 'Work'],
        ];
    }
}
