<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_templates')) {
            return;
        }

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('subject');
            $table->text('body_html');
            $table->text('body_text')->nullable();
            $table->json('variables')->nullable();
            $table->boolean('is_system')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('email_templates')->insert([
            [
                'key' => 'password_reset',
                'name' => 'Password Reset',
                'description' => 'Sent when a user requests a password reset.',
                'subject' => 'Reset your password',
                'body_html' => '<p>Hi {{user.name}},</p><p>You requested a password reset. Click the link below to reset your password:</p><p><a href="{{reset_url}}">{{reset_url}}</a></p><p>This link expires in {{expires_in}}.</p><p>If you did not request this, you can ignore this email.</p><p>— {{app_name}}</p>',
                'body_text' => "Hi {{user.name}},\n\nYou requested a password reset. Visit this link to reset your password:\n{{reset_url}}\n\nThis link expires in {{expires_in}}.\n\nIf you did not request this, you can ignore this email.\n\n— {{app_name}}",
                'variables' => json_encode(['user.name', 'user.email', 'reset_url', 'expires_in', 'app_name']),
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'email_verification',
                'name' => 'Email Verification',
                'description' => 'Sent to verify the user\'s email address.',
                'subject' => 'Verify your email address',
                'body_html' => '<p>Hi {{user.name}},</p><p>Please verify your email address by clicking the link below:</p><p><a href="{{verification_url}}">{{verification_url}}</a></p><p>— {{app_name}}</p>',
                'body_text' => "Hi {{user.name}},\n\nPlease verify your email address by visiting:\n{{verification_url}}\n\n— {{app_name}}",
                'variables' => json_encode(['user.name', 'user.email', 'verification_url', 'app_name']),
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'welcome',
                'name' => 'Welcome Email',
                'description' => 'Sent after a user registers.',
                'subject' => 'Welcome to {{app_name}}',
                'body_html' => '<p>Hi {{user.name}},</p><p>Welcome! Your account has been created. You can sign in here:</p><p><a href="{{login_url}}">{{login_url}}</a></p><p>— {{app_name}}</p>',
                'body_text' => "Hi {{user.name}},\n\nWelcome! Your account has been created. Sign in here:\n{{login_url}}\n\n— {{app_name}}",
                'variables' => json_encode(['user.name', 'user.email', 'login_url', 'app_name']),
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'notification',
                'name' => 'Notification',
                'description' => 'Generic notification email.',
                'subject' => '{{title}}',
                'body_html' => '<p>Hi {{user.name}},</p><p>{{message}}</p><p><a href="{{action_url}}">{{action_text}}</a></p><p>— {{app_name}}</p>',
                'body_text' => "Hi {{user.name}},\n\n{{message}}\n\n— {{app_name}}",
                'variables' => json_encode(['user.name', 'title', 'message', 'action_url', 'action_text', 'app_name']),
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
