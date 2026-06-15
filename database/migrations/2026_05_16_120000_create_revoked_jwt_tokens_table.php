<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('revoked_jwt_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('jti')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('expires_at')->index();
            $table->dateTime('revoked_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('revoked_jwt_tokens');
    }
};
