<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    /**
     * Default template definitions (key => attributes).
     * Used by run() and by getDefaultForKey() for reset.
     */
    protected static function defaults(): array
    {
        $appName = config('app.name', 'selfmx');
        return [
            'password_reset' => [
                'key' => 'password_reset',
                'name' => 'Password Reset',
                'description' => 'Sent when a user requests a password reset.',
                'subject' => 'Reset your password',
                'body_html' => "<p>Hi {{user.name}},</p><p>You requested a password reset. Click the link below to reset your password:</p><p><a href=\"{{reset_url}}\">{{reset_url}}</a></p><p>This link expires in {{expires_in}}.</p><p>If you did not request this, you can ignore this email.</p><p>— {{app_name}}</p>",
                'body_text' => "Hi {{user.name}},\n\nYou requested a password reset. Visit this link to reset your password:\n{{reset_url}}\n\nThis link expires in {{expires_in}}.\n\nIf you did not request this, you can ignore this email.\n\n— {{app_name}}",
                'variables' => ['user.name', 'user.email', 'reset_url', 'expires_in', 'app_name'],
                'is_system' => true,
                'is_active' => true,
            ],
            'email_verification' => [
                'key' => 'email_verification',
                'name' => 'Email Verification',
                'description' => 'Sent to verify the user\'s email address.',
                'subject' => 'Verify your email address',
                'body_html' => "<p>Hi {{user.name}},</p><p>Please verify your email address by clicking the link below:</p><p><a href=\"{{verification_url}}\">{{verification_url}}</a></p><p>— {{app_name}}</p>",
                'body_text' => "Hi {{user.name}},\n\nPlease verify your email address by visiting:\n{{verification_url}}\n\n— {{app_name}}",
                'variables' => ['user.name', 'user.email', 'verification_url', 'app_name'],
                'is_system' => true,
                'is_active' => true,
            ],
            'welcome' => [
                'key' => 'welcome',
                'name' => 'Welcome Email',
                'description' => 'Sent after a user registers.',
                'subject' => 'Welcome to {{app_name}}',
                'body_html' => "<p>Hi {{user.name}},</p><p>Welcome! Your account has been created. You can sign in here:</p><p><a href=\"{{login_url}}\">{{login_url}}</a></p><p>— {{app_name}}</p>",
                'body_text' => "Hi {{user.name}},\n\nWelcome! Your account has been created. Sign in here:\n{{login_url}}\n\n— {{app_name}}",
                'variables' => ['user.name', 'user.email', 'login_url', 'app_name'],
                'is_system' => true,
                'is_active' => true,
            ],
            'notification' => [
                'key' => 'notification',
                'name' => 'Notification',
                'description' => 'Generic notification email.',
                'subject' => '{{title}}',
                'body_html' => "<p>Hi {{user.name}},</p><p>{{message}}</p><p><a href=\"{{action_url}}\">{{action_text}}</a></p><p>— {{app_name}}</p>",
                'body_text' => "Hi {{user.name}},\n\n{{message}}\n\n— {{app_name}}",
                'variables' => ['user.name', 'title', 'message', 'action_url', 'action_text', 'app_name'],
                'is_system' => true,
                'is_active' => true,
            ],
        ];
    }

    /**
     * Get default template data for a key (for reset functionality).
     *
     * @return array{subject: string, body_html: string, body_text: string}|null
     */
    public static function getDefaultForKey(string $key): ?array
    {
        $defaults = self::defaults();
        if (!isset($defaults[$key])) {
            return null;
        }
        $def = $defaults[$key];
        return [
            'subject' => $def['subject'],
            'body_html' => $def['body_html'],
            'body_text' => $def['body_text'],
        ];
    }

    /**
     * Run the seeder.
     */
    public function run(): void
    {
        foreach (self::defaults() as $attrs) {
            EmailTemplate::updateOrCreate(
                ['key' => $attrs['key']],
                $attrs
            );
        }
    }
}
