<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_shift_id')->constrained()->restrictOnDelete();
            $table->string('direction');
            $table->bigInteger('amount');
            $table->string('reason');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key');
            $table->timestamps();

            $table->unique(['cashier_shift_id', 'idempotency_key']);
            $table->index(['cashier_shift_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
