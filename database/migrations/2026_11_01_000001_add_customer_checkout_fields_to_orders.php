<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        // Order customer (placed_by_id NULL) tidak mungkin ada sebelum fitur ini;
        // hapus agar placed_by_id bisa NOT NULL lagi saat rollback.
        $customerOrderIds = DB::table('orders')->whereNull('placed_by_id')->pluck('id')->all();

        if ($customerOrderIds !== []) {
            DB::table('payments')->whereIn('order_id', $customerOrderIds)->delete();
            DB::table('audit_logs')
                ->where('target_type', 'App\\Modules\\Ordering\\Models\\Order')
                ->whereIn('target_id', $customerOrderIds)
                ->delete();
            DB::table('orders')->whereIn('id', $customerOrderIds)->delete(); // items & status events cascade
        }

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
            $table->foreignId('placed_by_id')->nullable(false)->change();
        });
    }
};
