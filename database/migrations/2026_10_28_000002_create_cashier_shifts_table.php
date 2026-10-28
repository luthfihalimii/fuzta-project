<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('window_key');
            $table->timestamp('scheduled_start_at');
            $table->timestamp('scheduled_end_at');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->bigInteger('opening_cash')->default(0);
            $table->bigInteger('cash_sales_total')->nullable();
            $table->bigInteger('qris_sales_total')->nullable();
            $table->bigInteger('cash_in_total')->nullable();
            $table->bigInteger('cash_out_total')->nullable();
            $table->bigInteger('expected_cash')->nullable();
            $table->bigInteger('counted_cash')->nullable();
            $table->bigInteger('variance')->nullable();
            $table->bigInteger('cash_deposited')->nullable();
            $table->text('closing_note')->nullable();
            $table->foreignId('closed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('close_type')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reconciliation_note')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'opened_at']);
            $table->index(['user_id', 'closed_at']);
            $table->index('closed_at');
            $table->index(['close_type', 'reconciled_at']);
            $table->unique([
                'user_id',
                'branch_id',
                'window_key',
                'scheduled_start_at',
                'scheduled_end_at',
            ], 'cashier_shifts_window_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX cashier_shifts_open_unique ON cashier_shifts (user_id, branch_id) WHERE closed_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shifts');
    }
};
