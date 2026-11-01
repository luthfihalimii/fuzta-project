<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('public_token')->nullable()->after('placed_by_id')->unique();
            $table->string('customer_phone')->nullable()->after('public_token');
            $table->text('delivery_address')->nullable()->after('customer_phone');
            $table->string('delivery_area_id', 64)->nullable()->after('delivery_address');
            $table->bigInteger('delivery_fee')->default(0)->after('delivery_area_id');
            $table->string('biteship_order_id')->nullable()->after('delivery_fee');
            $table->string('courier_name')->nullable()->after('biteship_order_id');
            $table->string('courier_service')->nullable()->after('courier_name');
            $table->string('delivery_status', 32)->nullable()->after('courier_service');
            $table->text('tracking_url')->nullable()->after('delivery_status');
            $table->foreignId('placed_by_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn([
                'public_token',
                'customer_phone',
                'delivery_address',
                'delivery_area_id',
                'delivery_fee',
                'biteship_order_id',
                'courier_name',
                'courier_service',
                'delivery_status',
                'tracking_url',
            ]);
            // shortcut: saat rollback dibiarkan nullable karena order customer (placed_by_id NULL)
            // adalah data legal; bila perlu schema lama persis, hapus order customer manual dulu.
            $table->foreignId('placed_by_id')->nullable()->change();
        });
    }
};
