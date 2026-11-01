<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete()->index();
            $table->string('label', 40);
            $table->string('recipient_name');
            $table->string('phone', 20);
            $table->text('address');
            $table->string('area_id', 64);
            $table->text('notes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX customer_addresses_one_default_per_customer ON customer_addresses (customer_id) WHERE is_default');
    }

    public function down(): void
    {
        // Partial index ikut terhapus saat table di-drop.
        Schema::dropIfExists('customer_addresses');
    }
};
