<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Manufacturer;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyOnboardingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_supplier_profiles_and_primary_contacts_are_canonicalized(): void
    {
        $migration = require database_path('migrations/2026_07_10_000000_create_company_onboarding_structure.php');
        $migration->down();

        $country = Country::query()->create([
            'name' => 'Oman',
            'country_code' => 'OM',
            'phone_code' => '+968',
            'status' => 'active',
        ]);
        $company = Company::query()->create([
            'country_id' => $country->id,
            'name' => 'Legacy Mixed Company LLC',
            'company_code' => 'LMC',
            'code_slug' => 'legacy-mixed-company',
            'company_type' => 'mixed',
            'status' => 'active',
        ]);
        $contacts = collect([
            Contact::query()->create([
                'company_id' => $company->id,
                'name' => 'First Legacy Primary',
                'email' => 'first@example.test',
                'is_primary' => true,
                'status' => 'active',
            ]),
            Contact::query()->create([
                'company_id' => $company->id,
                'name' => 'Canonical Supplier Primary',
                'email' => 'supplier@example.test',
                'is_primary' => true,
                'status' => 'active',
            ]),
            Contact::query()->create([
                'company_id' => $company->id,
                'name' => 'Duplicate Supplier Primary',
                'email' => 'duplicate@example.test',
                'is_primary' => true,
                'status' => 'active',
            ]),
        ]);
        $manufacturers = collect(['Legacy One', 'Legacy Two', 'Legacy Three'])
            ->map(fn (string $name): Manufacturer => Manufacturer::query()->create([
                'country_id' => $country->id,
                'name' => $name,
                'status' => 'active',
            ]));
        $profiles = collect([
            Supplier::query()->create([
                'company_id' => $company->id,
                'primary_contact_id' => $contacts[0]->id,
                'manufacturer_id' => $manufacturers[0]->id,
                'status' => 'inactive',
            ]),
            Supplier::query()->create([
                'company_id' => $company->id,
                'primary_contact_id' => $contacts[1]->id,
                'manufacturer_id' => $manufacturers[1]->id,
                'status' => 'active',
            ]),
            Supplier::query()->create([
                'company_id' => $company->id,
                'primary_contact_id' => $contacts[2]->id,
                'manufacturer_id' => $manufacturers[2]->id,
                'status' => 'active',
            ]),
        ]);

        $migration->up();

        $this->assertDatabaseHas('buyer_profiles', [
            'company_id' => $company->id,
            'primary_contact_id' => $contacts[0]->id,
        ]);
        $this->assertSame(1, Contact::query()->where('company_id', $company->id)->where('is_primary_buyer', true)->count());
        $this->assertSame(1, Contact::query()->where('company_id', $company->id)->where('is_primary_supplier', true)->count());
        $this->assertDatabaseHas('contacts', ['id' => $contacts[1]->id, 'is_primary_supplier' => true]);
        $this->assertSame(1, Supplier::query()->where('company_id', $company->id)->where('status', 'active')->count());
        $this->assertDatabaseHas('suppliers', ['id' => $profiles[1]->id, 'status' => 'active']);
        $this->assertDatabaseHas('suppliers', ['id' => $profiles[2]->id, 'status' => 'inactive']);

        foreach ($manufacturers as $manufacturer) {
            $this->assertDatabaseHas('supplier_manufacturer', [
                'supplier_id' => $profiles[1]->id,
                'manufacturer_id' => $manufacturer->id,
            ]);
        }
    }
}
