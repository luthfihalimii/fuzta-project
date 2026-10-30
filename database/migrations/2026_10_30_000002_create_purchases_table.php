<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->date('received_at');
            $table->string('reference')->nullable();
            $table->bigInteger('total')->default(0);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key');
            $table->timestamps();

            $table->unique(['branch_id', 'idempotency_key']);
            $table->index(['branch_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
