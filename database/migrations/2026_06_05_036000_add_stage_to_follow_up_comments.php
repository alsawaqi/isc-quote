<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_comments', function (Blueprint $table): void {
            $table->string('stage', 32)->default('acknowledgement')->index();
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_comments', function (Blueprint $table): void {
            $table->dropColumn('stage');
        });
    }
};
