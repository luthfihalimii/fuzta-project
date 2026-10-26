<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('cashier')->index();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
