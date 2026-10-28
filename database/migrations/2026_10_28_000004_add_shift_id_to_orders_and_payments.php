<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('placed_by_id')
                ->constrained('cashier_shifts')->restrictOnDelete();
            $table->index(['shift_id', 'payment_status']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('order_id')
                ->constrained('cashier_shifts')->restrictOnDelete();
            $table->index(['shift_id', 'method', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['shift_id', 'method', 'status']);
            $table->dropConstrainedForeignId('shift_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['shift_id', 'payment_status']);
            $table->dropConstrainedForeignId('shift_id');
        });
    }
};
