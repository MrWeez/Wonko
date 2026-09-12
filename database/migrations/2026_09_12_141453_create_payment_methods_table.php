<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('composite');
            $table->foreignId('primary_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('secondary_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('auto_split_balance')->default(true);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'name']);
            $table->index('primary_account_id');
            $table->index('secondary_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
