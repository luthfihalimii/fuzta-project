<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->string('recipeable_type');
            $table->unsignedBigInteger('recipeable_id');
            $table->timestamps();

            $table->unique(['recipeable_type', 'recipeable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
