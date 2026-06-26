<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_items', function (Blueprint $table): void {
            $table->uuid('follow_up_group_key')->nullable()->after('quotation_item_id');
            $table->string('follow_up_group_name')->nullable()->after('follow_up_group_key');
            $table->string('follow_up_group_mode', 24)->default('individual')->after('follow_up_group_name');
            $table->index(['quotation_id', 'follow_up_group_key']);
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_items', function (Blueprint $table): void {
            $table->dropIndex(['quotation_id', 'follow_up_group_key']);
            $table->dropColumn(['follow_up_group_key', 'follow_up_group_name', 'follow_up_group_mode']);
        });
    }
};
