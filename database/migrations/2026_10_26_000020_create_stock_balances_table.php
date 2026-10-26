<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->bigInteger('quantity')->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'ingredient_id']);
        });

        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_quantity_nonnegative CHECK (quantity >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
