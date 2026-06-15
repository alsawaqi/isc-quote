<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Designation;
use App\Models\Manufacturer;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoundationModelRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_owns_contacts_that_can_be_filtered_for_quotation_selection(): void
    {
        $country = Country::create([
            'name' => 'Oman',
            'country_code' => 'OM',
            'phone_code' => '+968',
        ]);

        $designation = Designation::create([
            'name' => 'Mr.',
            'code' => 'MR',
        ]);

        $company = Company::create([
            'country_id' => $country->id,
            'name' => 'Occidental Of Oman, Inc',
            'company_code' => 'OXY',
            'code_slug' => 'oxy',
            'postal_code' => '130',
            'vendor_code' => '4502757812',
            'location' => 'Muscat',
            'email' => 'supplychain@example.test',
            'company_type' => 'buyer',
        ]);

        $contact = Contact::create([
            'company_id' => $company->id,
            'designation_id' => $designation->id,
            'name' => 'Moosa Ambu Ali',
            'job_title' => 'Supply Chain Management',
            'email' => 'Moosa_AmbuAli@oxy.com',
            'is_primary' => true,
        ]);

        $this->assertTrue($company->contacts->contains($contact));
        $this->assertTrue($contact->company->is($company));
        $this->assertTrue($contact->designation->is($designation));
    }

    public function test_suppliers_are_company_backed_records_and_manufacturers_are_country_named_records(): void
    {
        $country = Country::create([
            'name' => 'Oman',
            'country_code' => 'OM',
            'phone_code' => '+968',
        ]);

        $company = Company::create([
            'country_id' => $country->id,
            'name' => 'ABB LLC',
            'company_code' => 'ABB',
            'code_slug' => 'abb',
            'postal_code' => '112',
            'location' => 'Muscat',
            'company_type' => 'mixed',
        ]);

        $manufacturer = Manufacturer::create([
            'country_id' => $country->id,
            'name' => 'ABB Manufacturing',
        ]);
        $supplier = Supplier::create([
            'company_id' => $company->id,
            'manufacturer_id' => $manufacturer->id,
        ]);

        $this->assertTrue($supplier->company->is($company));
        $this->assertTrue($supplier->manufacturer->is($manufacturer));
        $this->assertTrue($manufacturer->country->is($country));
        $this->assertSame('ABB Manufacturing', $manufacturer->name);
    }

    public function test_user_can_be_assigned_a_fixed_operational_role(): void
    {
        $role = Role::create([
            'name' => 'Salesperson',
            'slug' => 'salesperson',
            'is_system' => true,
        ]);

        $user = User::factory()->create();

        $user->roles()->attach($role);

        $this->assertTrue($user->roles->contains($role));
    }
}
