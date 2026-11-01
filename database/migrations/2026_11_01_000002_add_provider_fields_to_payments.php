<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider', 32)->nullable()->after('method');
            $table->string('provider_reference')->nullable()->after('provider');
            $table->text('payment_url')->nullable()->after('provider_reference');
            $table->timestamp('expires_at')->nullable()->after('payment_url');
            $table->index('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['provider_reference']);
            $table->dropColumn([
                'provider',
                'provider_reference',
                'payment_url',
                'expires_at',
            ]);
        });
    }
};
