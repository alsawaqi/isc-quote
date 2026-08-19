<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('primary_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('supplier_manufacturer', function (Blueprint $table): void {
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['supplier_id', 'manufacturer_id']);
        });

        Schema::create('company_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manufacturer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_type', 32)->default('factory');
            $table->string('name');
            $table->string('location');
            $table->text('address')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index(['company_id', 'location_type', 'status']);
            $table->index(['company_id', 'name']);
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->boolean('serves_buyer')->default(false);
            $table->boolean('serves_supplier')->default(false);
            $table->boolean('all_locations')->default(true);
            $table->boolean('is_primary_buyer')->default(false);
            $table->boolean('is_primary_supplier')->default(false);

            $table->index(
                ['company_id', 'serves_buyer', 'is_primary_buyer'],
                'contacts_buyer_scope_index'
            );
            $table->index(
                ['company_id', 'serves_supplier', 'is_primary_supplier'],
                'contacts_supplier_scope_index'
            );
        });

        Schema::create('contact_company_location', function (Blueprint $table): void {
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_location_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['contact_id', 'company_location_id']);
        });

        Schema::table('supplier_pos', function (Blueprint $table): void {
            $table->foreignId('company_location_id')->nullable()->after('supplier_contact_id');
            $table->index('company_location_id', 'supplier_pos_company_location_id_index');
            $table->foreign('company_location_id')
                ->references('id')
                ->on('company_locations')
                ->nullOnDelete();
        });

        $this->backfillBuyerProfiles();
        $this->backfillSupplierProfiles();
        $this->backfillSupplierManufacturers();
        $this->normalizeSupplierProfiles();
        $this->backfillContactRoles();
    }

    public function down(): void
    {
        Schema::table('supplier_pos', function (Blueprint $table): void {
            $table->dropForeign(['company_location_id']);
            $table->dropIndex('supplier_pos_company_location_id_index');
            $table->dropColumn('company_location_id');
        });

        Schema::dropIfExists('contact_company_location');

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex('contacts_buyer_scope_index');
            $table->dropIndex('contacts_supplier_scope_index');
            $table->dropColumn([
                'serves_buyer',
                'serves_supplier',
                'all_locations',
                'is_primary_buyer',
                'is_primary_supplier',
            ]);
        });

        Schema::dropIfExists('company_locations');
        Schema::dropIfExists('supplier_manufacturer');
        Schema::dropIfExists('buyer_profiles');
    }

    private function backfillBuyerProfiles(): void
    {
        $now = now();

        DB::table('companies')
            ->select(['id', 'status'])
            ->whereIn('company_type', ['buyer', 'mixed', 'internal'])
            ->orderBy('id')
            ->chunkById(250, function ($companies) use ($now): void {
                $companyIds = $companies->pluck('id');
                $primaryContacts = DB::table('contacts')
                    ->select(['id', 'company_id'])
                    ->whereIn('company_id', $companyIds)
                    ->where('status', 'active')
                    ->orderByRaw('CASE WHEN is_primary = 1 THEN 0 ELSE 1 END')
                    ->orderBy('id')
                    ->get()
                    ->groupBy('company_id')
                    ->map(fn ($contacts) => $contacts->first()->id);

                $rows = $companies->map(fn ($company): array => [
                    'company_id' => $company->id,
                    'primary_contact_id' => $primaryContacts->get($company->id),
                    'status' => $company->status ?: 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('buyer_profiles')->insertOrIgnore($rows);
            });
    }

    private function backfillSupplierProfiles(): void
    {
        $now = now();

        DB::table('companies')
            ->select(['id', 'status'])
            ->whereIn('company_type', ['supplier', 'manufacturer', 'mixed', 'internal'])
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('suppliers')
                    ->whereColumn('suppliers.company_id', 'companies.id');
            })
            ->orderBy('id')
            ->chunkById(250, function ($companies) use ($now): void {
                $companyIds = $companies->pluck('id');
                $primaryContacts = DB::table('contacts')
                    ->select(['id', 'company_id'])
                    ->whereIn('company_id', $companyIds)
                    ->where('status', 'active')
                    ->orderByRaw('CASE WHEN is_primary = 1 THEN 0 ELSE 1 END')
                    ->orderBy('id')
                    ->get()
                    ->groupBy('company_id')
                    ->map(fn ($contacts) => $contacts->first()->id);

                $rows = $companies->map(fn ($company): array => [
                    'company_id' => $company->id,
                    'primary_contact_id' => $primaryContacts->get($company->id),
                    'manufacturer_id' => null,
                    'status' => $company->status ?: 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('suppliers')->insertOrIgnore($rows);
            });
    }

    private function backfillContactRoles(): void
    {
        DB::table('contacts')
            ->whereIn('company_id', function ($query): void {
                $query->select('id')
                    ->from('companies')
                    ->whereIn('company_type', ['buyer', 'mixed', 'internal']);
            })
            ->update(['serves_buyer' => true]);

        DB::table('contacts')
            ->whereIn('company_id', function ($query): void {
                $query->select('id')
                    ->from('companies')
                    ->whereIn('company_type', ['supplier', 'manufacturer', 'mixed', 'internal']);
            })
            ->update(['serves_supplier' => true]);

        DB::table('buyer_profiles')
            ->select(['id', 'primary_contact_id'])
            ->whereNotNull('primary_contact_id')
            ->orderBy('id')
            ->chunkById(250, function ($profiles): void {
                DB::table('contacts')
                    ->whereIn('id', $profiles->pluck('primary_contact_id'))
                    ->update(['is_primary_buyer' => true]);
            });

        DB::table('companies')
            ->select('id')
            ->whereIn('company_type', ['supplier', 'manufacturer', 'mixed', 'internal'])
            ->orderBy('id')
            ->chunkById(250, function ($companies): void {
                $companyIds = $companies->pluck('id');
                $contacts = DB::table('contacts')
                    ->select(['id', 'company_id'])
                    ->whereIn('company_id', $companyIds)
                    ->where('status', 'active')
                    ->orderByRaw('CASE WHEN is_primary = 1 THEN 0 ELSE 1 END')
                    ->orderBy('id')
                    ->get()
                    ->groupBy('company_id');
                $suppliers = DB::table('suppliers')
                    ->select(['id', 'company_id', 'primary_contact_id', 'status'])
                    ->whereIn('company_id', $companyIds)
                    ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                    ->orderBy('id')
                    ->get()
                    ->groupBy('company_id')
                    ->map(fn ($profiles) => $profiles->first());

                foreach ($companies as $company) {
                    $supplier = $suppliers->get($company->id);
                    $companyContacts = $contacts->get($company->id, collect());

                    if (! $supplier) {
                        continue;
                    }

                    $primaryContactId = $companyContacts->contains('id', $supplier->primary_contact_id)
                        ? $supplier->primary_contact_id
                        : $companyContacts->first()?->id;

                    DB::table('suppliers')->where('id', $supplier->id)->update([
                        'primary_contact_id' => $primaryContactId,
                        'updated_at' => now(),
                    ]);

                    if ($primaryContactId) {
                        DB::table('contacts')->where('id', $primaryContactId)->update([
                            'is_primary_supplier' => true,
                        ]);
                    }
                }
            });
    }

    private function backfillSupplierManufacturers(): void
    {
        $now = now();

        DB::table('suppliers')
            ->select(['id', 'manufacturer_id'])
            ->whereNotNull('manufacturer_id')
            ->orderBy('id')
            ->chunkById(250, function ($suppliers) use ($now): void {
                $rows = $suppliers->map(fn ($supplier): array => [
                    'supplier_id' => $supplier->id,
                    'manufacturer_id' => $supplier->manufacturer_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('supplier_manufacturer')->insertOrIgnore($rows);
            });
    }

    private function normalizeSupplierProfiles(): void
    {
        $companyIds = DB::table('suppliers')
            ->select('company_id')
            ->groupBy('company_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('company_id');

        foreach ($companyIds as $companyId) {
            $profiles = DB::table('suppliers')
                ->select(['id', 'manufacturer_id', 'status'])
                ->where('company_id', $companyId)
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->get();
            $canonical = $profiles->first();

            if (! $canonical) {
                continue;
            }

            $manufacturerIds = DB::table('supplier_manufacturer')
                ->whereIn('supplier_id', $profiles->pluck('id'))
                ->pluck('manufacturer_id')
                ->push(...$profiles->pluck('manufacturer_id')->filter()->all())
                ->filter()
                ->unique();

            foreach ($manufacturerIds as $manufacturerId) {
                DB::table('supplier_manufacturer')->insertOrIgnore([
                    'supplier_id' => $canonical->id,
                    'manufacturer_id' => $manufacturerId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('suppliers')
                ->whereIn('id', $profiles->pluck('id')->reject(fn ($id) => $id === $canonical->id))
                ->update(['status' => 'inactive', 'updated_at' => now()]);
        }
    }
};
