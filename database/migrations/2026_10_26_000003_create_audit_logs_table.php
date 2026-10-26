<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
