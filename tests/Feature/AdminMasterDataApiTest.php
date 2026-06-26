<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Designation;
use App\Models\Manufacturer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminMasterDataApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_and_delete_a_country(): void
    {
        $token = $this->adminToken();

        $countryId = $this->withToken($token)
            ->postJson('/api/admin/countries', [
                'name' => 'Qatar',
                'country_code' => 'QA',
                'phone_code' => '+974',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Country created successfully.')
            ->assertJsonPath('data.name', 'Qatar')
            ->json('data.id');

        $this->withToken($token)
            ->putJson("/api/admin/countries/{$countryId}", [
                'name' => 'State of Qatar',
                'country_code' => 'QA',
                'phone_code' => '+974',
                'status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Country updated successfully.')
            ->assertJsonPath('data.name', 'State of Qatar')
            ->assertJsonPath('data.status', 'inactive');

        $this->withToken($token)
            ->deleteJson("/api/admin/countries/{$countryId}")
            ->assertOk()
            ->assertJsonPath('message', 'Country deleted successfully.');

        $this->assertDatabaseMissing('countries', ['id' => $countryId]);
    }

    public function test_master_data_endpoints_are_admin_only(): void
    {
        $this->seed(FoundationSeeder::class);

        $salesRole = Role::where('slug', 'salesperson')->firstOrFail();
        $salesUser = User::create([
            'name' => 'Sales User',
            'email' => 'salesperson@example.test',
            'password' => Hash::make('password'),
        ]);
        $salesUser->roles()->attach($salesRole);

        $token = $this->postJson('/api/login', [
            'email' => 'salesperson@example.test',
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)
            ->getJson('/api/admin/countries')
            ->assertForbidden();
    }

    public function test_admin_can_manage_incoterms_without_delivery_responsibility_document_or_agent_fields(): void
    {
        $token = $this->adminToken();

        $response = $this->withToken($token)
            ->postJson('/api/admin/incoterms', [
                'code' => 'DAP',
                'name' => 'Delivered at Place',
                'description' => 'Delivery at named destination.',
                'reminder_days_before_delivery' => 25,
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Incoterm created successfully.')
            ->assertJsonMissingPath('data.delivery_responsibility')
            ->assertJsonMissingPath('data.shipping_documents_required')
            ->assertJsonMissingPath('data.agent_required');

        $this->assertSame([
            'id',
            'code',
            'name',
            'description',
            'reminder_days_before_delivery',
            'status',
            'created_at',
            'updated_at',
        ], array_keys($response->json('data')));
    }

    public function test_admin_can_manage_uoms_and_currencies_for_commercial_forms(): void
    {
        $token = $this->adminToken();

        $uomId = $this->withToken($token)
            ->postJson('/api/admin/uoms', [
                'code' => 'BOX',
                'name' => 'Box',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'UOM created successfully.')
            ->assertJsonPath('data.code', 'BOX')
            ->assertJsonPath('data.name', 'Box')
            ->json('data.id');

        $this->withToken($token)
            ->putJson("/api/admin/uoms/{$uomId}", [
                'code' => 'BOX',
                'name' => 'Box / Carton',
                'status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Box / Carton')
            ->assertJsonPath('data.status', 'inactive');

        $currencyId = $this->withToken($token)
            ->postJson('/api/admin/currencies', [
                'code' => 'AED',
                'name' => 'UAE Dirham',
                'exchange_rate' => '9.550000',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Currency created successfully.')
            ->assertJsonPath('data.code', 'AED')
            ->assertJsonPath('data.exchange_rate', '9.550000')
            ->json('data.id');

        $this->withToken($token)
            ->putJson("/api/admin/currencies/{$currencyId}", [
                'code' => 'AED',
                'name' => 'UAE Dirham',
                'exchange_rate' => '9.600000',
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.exchange_rate', '9.600000');

        $this->withToken($token)
            ->getJson('/api/admin/master-data/options')
            ->assertOk()
            ->assertJsonFragment(['code' => 'BOX', 'name' => 'Box / Carton'])
            ->assertJsonFragment(['code' => 'AED', 'name' => 'UAE Dirham', 'exchange_rate' => '9.600000']);
    }

    public function test_admin_can_manage_manufacturers_as_country_named_records(): void
    {
        $token = $this->adminToken();
        $country = Country::create([
            'name' => 'Germany',
            'country_code' => 'DE',
            'phone_code' => '+49',
        ]);

        $response = $this->withToken($token)
            ->postJson('/api/admin/manufacturers', [
                'country_id' => $country->id,
                'name' => 'ABB Manufacturing',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Manufacturer created successfully.')
            ->assertJsonPath('data.name', 'ABB Manufacturing')
            ->assertJsonPath('data.country_id', $country->id)
            ->assertJsonPath('data.country_name', 'Germany')
            ->assertJsonMissingPath('data.company_id')
            ->assertJsonMissingPath('data.company_name')
            ->assertJsonMissingPath('data.primary_contact_id')
            ->assertJsonMissingPath('data.primary_contact_name');

        $this->assertSame([
            'id',
            'country_id',
            'country_name',
            'name',
            'status',
            'created_at',
            'updated_at',
        ], array_keys($response->json('data')));
    }

    public function test_admin_can_optionally_link_supplier_to_manufacturer(): void
    {
        $token = $this->adminToken();
        $country = Country::create([
            'name' => 'Germany',
            'country_code' => 'DE',
            'phone_code' => '+49',
            'status' => 'active',
        ]);
        $designation = Designation::create([
            'name' => 'Tech.',
            'code' => 'TECH',
            'status' => 'active',
        ]);
        $company = Company::create([
            'country_id' => $country->id,
            'name' => 'ABB LLC',
            'company_code' => 'ABB',
            'code_slug' => 'abb',
            'company_type' => 'supplier',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'company_id' => $company->id,
            'designation_id' => $designation->id,
            'name' => 'Omid Nilchian',
            'email' => 'omid@example.test',
            'status' => 'active',
        ]);
        $manufacturer = Manufacturer::create([
            'country_id' => $country->id,
            'name' => 'ABB Manufacturing',
            'status' => 'active',
        ]);

        $response = $this->withToken($token)
            ->postJson('/api/admin/suppliers', [
                'company_id' => $company->id,
                'primary_contact_id' => $contact->id,
                'manufacturer_id' => $manufacturer->id,
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Supplier created successfully.')
            ->assertJsonPath('data.company_name', 'ABB LLC')
            ->assertJsonPath('data.manufacturer_id', $manufacturer->id)
            ->assertJsonPath('data.manufacturer_name', 'ABB Manufacturing');

        $this->assertDatabaseHas('suppliers', [
            'id' => $response->json('data.id'),
            'manufacturer_id' => $manufacturer->id,
        ]);

        $this->withToken($token)
            ->getJson('/api/admin/suppliers')
            ->assertOk()
            ->assertJsonPath('options.manufacturers.0.id', $manufacturer->id);
    }

    public function test_country_validation_errors_return_field_messages(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)
            ->postJson('/api/admin/countries', [
                'name' => '',
                'country_code' => 'OM',
                'status' => 'active',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'country_code']);
    }

    private function adminToken(): string
    {
        $this->seed(FoundationSeeder::class);

        $adminRole = Role::where('slug', 'admin')->firstOrFail();
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin-user@example.test',
            'password' => Hash::make('password'),
        ]);
        $admin->roles()->attach($adminRole);

        return $this->postJson('/api/login', [
            'email' => 'admin-user@example.test',
            'password' => 'password',
        ])->json('token');
    }
}
