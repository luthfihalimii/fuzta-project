<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_branch_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_available')->default(true);
            $table->unique(['product_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_branch_availability');
    }
};
