<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->text('cancel_reason')->nullable()->change();
        });

        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->text('discrepancy_reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->string('cancel_reason')->nullable()->change();
        });

        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->string('discrepancy_reason')->nullable()->change();
        });
    }
};
