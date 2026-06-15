<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('follow_up_items') || ! Schema::hasTable('supplier_po_lines')) {
            return;
        }

        $defaultAssigneeId = DB::table('users')
            ->join('user_roles', 'users.id', '=', 'user_roles.user_id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('users.status', 'active')
            ->where('roles.slug', 'follow-up')
            ->orderBy('users.id')
            ->value('users.id');

        $now = now();

        DB::table('supplier_po_lines')
            ->orderBy('id')
            ->chunkById(200, function ($lines) use ($defaultAssigneeId, $now): void {
                foreach ($lines as $line) {
                    $exists = DB::table('follow_up_items')
                        ->where('supplier_po_id', $line->supplier_po_id)
                        ->where('quotation_item_id', $line->quotation_item_id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('follow_up_items')->insert([
                        'supplier_po_line_id' => $line->id,
                        'supplier_po_id' => $line->supplier_po_id,
                        'quotation_id' => $line->quotation_id,
                        'buyer_po_id' => $line->buyer_po_id,
                        'quotation_item_id' => $line->quotation_item_id,
                        'assigned_to' => $defaultAssigneeId,
                        'status' => 'awaiting_acknowledgement',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        //
    }
};
