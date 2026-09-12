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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->char('currency', 3);
            $table->decimal('amount', 20, 4);
            $table->unsignedSmallInteger('period_count')->default(1);
            $table->string('period_unit');
            $table->date('next_due_date');
            $table->boolean('auto_post')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'next_due_date']);
            $table->index(['user_id', 'active']);
            $table->unique(['user_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
