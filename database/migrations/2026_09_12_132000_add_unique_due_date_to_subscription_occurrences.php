<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_occurrences', function (Blueprint $table): void {
            $table->unique(['subscription_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('subscription_occurrences', function (Blueprint $table): void {
            $table->dropUnique(['subscription_id', 'due_date']);
        });
    }
};
