<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('placed_by_id')->constrained('users')->restrictOnDelete();
            $table->string('channel');
            $table->string('table_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->text('note')->nullable();
            $table->string('status')->default('queued')->index();
            $table->string('payment_status')->default('unpaid')->index();
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('total')->default(0);
            $table->string('idempotency_key');
            $table->timestamps();

            $table->unique(['branch_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
