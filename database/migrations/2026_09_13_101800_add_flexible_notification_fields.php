<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('notification_reminders')->nullable()->after('notification_days_before');
            $table->boolean('notification_overdue')->default(false)->after('notification_reminders');
            $table->unsignedInteger('notification_overdue_frequency')->default(3)->after('notification_overdue');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->json('notification_reminders')->nullable()->after('notification_channel_overrides');
            $table->boolean('notification_overdue')->nullable()->after('notification_reminders');
            $table->unsignedInteger('notification_overdue_frequency')->nullable()->after('notification_overdue');
            $table->json('notification_sent')->nullable()->after('notification_overdue_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['notification_reminders', 'notification_overdue', 'notification_overdue_frequency']);
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['notification_reminders', 'notification_overdue', 'notification_overdue_frequency', 'notification_sent']);
        });
    }
};
