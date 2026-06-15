<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoterms', function (Blueprint $table) {
            foreach ([
                'delivery_responsibility',
                'shipping_documents_required',
                'agent_required',
            ] as $column) {
                if (Schema::hasColumn('incoterms', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('active')->after('password');
            }
        });
    }

    public function down(): void
    {
        Schema::table('incoterms', function (Blueprint $table) {
            if (! Schema::hasColumn('incoterms', 'delivery_responsibility')) {
                $table->string('delivery_responsibility')->default('internal')->after('description');
            }

            if (! Schema::hasColumn('incoterms', 'shipping_documents_required')) {
                $table->boolean('shipping_documents_required')->default(true)->after('delivery_responsibility');
            }

            if (! Schema::hasColumn('incoterms', 'agent_required')) {
                $table->boolean('agent_required')->default(false)->after('shipping_documents_required');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
