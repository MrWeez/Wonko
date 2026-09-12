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
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('category_id')->constrained('payment_methods')->nullOnDelete();
            $table->uuid('split_group_id')->nullable()->after('related_transaction_id');

            $table->index(['user_id', 'split_group_id']);
            $table->index('payment_method_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('category_id')->constrained('payment_methods')->nullOnDelete();
            $table->index('payment_method_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'split_group_id']);
            $table->dropColumn('split_group_id');
            $table->dropConstrainedForeignId('payment_method_id');
        });
    }
};
