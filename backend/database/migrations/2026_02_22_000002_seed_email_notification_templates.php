<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = \Database\Seeders\NotificationTemplateSeeder::defaults();
        $emailDefaults = array_filter($defaults, fn ($d) => $d['channel_group'] === 'email');

        foreach ($emailDefaults as $attrs) {
            NotificationTemplate::firstOrCreate(
                [
                    'type' => $attrs['type'],
                    'channel_group' => $attrs['channel_group'],
                ],
                $attrs
            );
        }
    }

    public function down(): void
    {
        NotificationTemplate::where('channel_group', 'email')->delete();
    }
};
