<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('manufacturers')) {
            return;
        }

        Schema::table('manufacturers', function (Blueprint $table) {
            if (! Schema::hasColumn('manufacturers', 'country_id')) {
                $table->foreignId('country_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            }

            if (! Schema::hasColumn('manufacturers', 'name')) {
                $table->string('name')->nullable()->after('country_id');
            }
        });

        if (Schema::hasTable('companies') && Schema::hasColumn('manufacturers', 'company_id')) {
            DB::table('manufacturers')
                ->select(['id', 'company_id'])
                ->orderBy('id')
                ->get()
                ->each(function (object $manufacturer): void {
                    $company = DB::table('companies')->where('id', $manufacturer->company_id)->first();

                    DB::table('manufacturers')
                        ->where('id', $manufacturer->id)
                        ->update([
                            'country_id' => $company?->country_id,
                            'name' => $company?->name ?? 'Manufacturer '.$manufacturer->id,
                        ]);
                });
        }

        Schema::table('manufacturers', function (Blueprint $table) {
            if (Schema::hasColumn('manufacturers', 'primary_contact_id')) {
                $table->dropForeign(['primary_contact_id']);
                $table->dropColumn('primary_contact_id');
            }

            if (Schema::hasColumn('manufacturers', 'company_id')) {
                $table->dropForeign(['company_id']);
                $table->dropColumn('company_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('manufacturers')) {
            return;
        }

        Schema::table('manufacturers', function (Blueprint $table) {
            if (! Schema::hasColumn('manufacturers', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            }

            if (! Schema::hasColumn('manufacturers', 'primary_contact_id')) {
                $table->foreignId('primary_contact_id')->nullable()->after('company_id')->constrained('contacts')->nullOnDelete();
            }

            if (Schema::hasColumn('manufacturers', 'country_id')) {
                $table->dropForeign(['country_id']);
                $table->dropColumn('country_id');
            }

            if (Schema::hasColumn('manufacturers', 'name')) {
                $table->dropColumn('name');
            }
        });
    }
};
