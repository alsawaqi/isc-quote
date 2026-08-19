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
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CompanyManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_load_company_management_options_and_directory(): void
    {
        $context = $this->managementContext();
        $manufacturer = $this->manufacturer($context['country'], 'Directory Option Manufacturing');

        $this->withBearerToken($context['admin'])
            ->getJson('/api/company-management/options')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['countries', 'designations', 'manufacturers'],
            ])
            ->assertJsonFragment([
                'id' => $manufacturer->id,
                'name' => 'Directory Option Manufacturing',
            ]);

        $this->withBearerToken($context['admin'])
            ->postJson('/api/company-management', $this->buyerPayload($context))
            ->assertCreated();

        $this->withBearerToken($context['admin'])
            ->getJson('/api/company-management?search=Oman%20Energy%20Procurement')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta'])
            ->assertJsonFragment([
                'name' => 'Oman Energy Procurement LLC',
                'company_code' => 'OEP',
            ]);
    }

    public function test_admin_can_create_a_buyer_company_with_multiple_contacts(): void
    {
        $context = $this->managementContext();

        $response = $this->withBearerToken($context['admin'])
            ->postJson('/api/company-management', $this->buyerPayload($context))
            ->assertCreated()
            ->assertJsonPath('data.company.name', 'Oman Energy Procurement LLC')
            ->assertJsonPath('data.company.company_code', 'OEP')
            ->assertJsonPath('data.roles.is_internal', false)
            ->assertJsonPath('data.roles.is_buyer', true)
            ->assertJsonPath('data.roles.is_supplier', false)
            ->assertJsonPath('data.supplier_profile', null);

        $this->assertNotNull($response->json('data.buyer_profile'));
        $this->assertCount(2, $response->json('data.contacts'));
        $this->assertEqualsCanonicalizing(
            ['aisha@example.test', 'salim@example.test'],
            collect($response->json('data.contacts'))->pluck('email')->all(),
        );

        $primaryBuyer = collect($response->json('data.contacts'))
            ->firstWhere('is_primary_buyer', true);

        $this->assertSame('aisha@example.test', $primaryBuyer['email'] ?? null);
        $this->assertTrue((bool) ($primaryBuyer['serves_buyer'] ?? false));
        $this->assertFalse((bool) ($primaryBuyer['serves_supplier'] ?? true));

        $this->assertDatabaseHas('companies', [
            'id' => $response->json('data.company.id'),
            'name' => 'Oman Energy Procurement LLC',
            'company_code' => 'OEP',
            'company_type' => 'buyer',
        ]);
        $this->assertDatabaseHas('buyer_profiles', [
            'company_id' => $response->json('data.company.id'),
            'primary_contact_id' => $primaryBuyer['id'],
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('suppliers', [
            'company_id' => $response->json('data.company.id'),
        ]);
        $this->assertDatabaseCount('contacts', 2);
    }

    public function test_admin_can_create_a_supplier_with_manufacturers_factories_and_scoped_contacts(): void
    {
        $context = $this->managementContext();
        $manufacturerOne = $this->manufacturer($context['country'], 'Gulf Valve Manufacturing');
        $manufacturerTwo = $this->manufacturer($context['country'], 'Arabian Pump Works');

        $payload = $this->supplierPayload(
            $context,
            [$manufacturerOne->id, $manufacturerTwo->id],
        );

        $response = $this->withBearerToken($context['admin'])
            ->postJson('/api/company-management', $payload)
            ->assertCreated()
            ->assertJsonPath('data.roles.is_internal', false)
            ->assertJsonPath('data.roles.is_buyer', false)
            ->assertJsonPath('data.roles.is_supplier', true)
            ->assertJsonPath('data.buyer_profile', null);

        $this->assertNotNull($response->json('data.supplier_profile'));
        $this->assertCount(2, $response->json('data.manufacturers'));
        $this->assertCount(2, $response->json('data.locations'));
        $this->assertCount(2, $response->json('data.contacts'));

        $contacts = collect($response->json('data.contacts'));
        $allFactoriesContact = $contacts->firstWhere('email', 'layla@example.test');
        $specificFactoryContact = $contacts->firstWhere('email', 'hamad@example.test');
        $soharFactory = collect($response->json('data.locations'))->firstWhere('name', 'Sohar Factory');

        $this->assertTrue((bool) ($allFactoriesContact['all_locations'] ?? false));
        $this->assertTrue((bool) ($allFactoriesContact['is_primary_supplier'] ?? false));
        $this->assertSame($allFactoriesContact['id'], $response->json('data.supplier_profile.primary_contact_id'));
        $this->assertFalse((bool) ($specificFactoryContact['all_locations'] ?? true));
        $this->assertSame([$soharFactory['id']], $specificFactoryContact['location_ids'] ?? []);
        $this->assertSame(
            ['Sohar Factory'],
            collect($specificFactoryContact['locations'] ?? [])->pluck('name')->all(),
        );

        $this->assertDatabaseHas('companies', [
            'id' => $response->json('data.company.id'),
            'company_code' => 'GSI',
            'company_type' => 'supplier',
        ]);
        $supplierId = $response->json('data.supplier_profile.id');
        $this->assertDatabaseHas('supplier_manufacturer', [
            'supplier_id' => $supplierId,
            'manufacturer_id' => $manufacturerOne->id,
        ]);
        $this->assertDatabaseHas('supplier_manufacturer', [
            'supplier_id' => $supplierId,
            'manufacturer_id' => $manufacturerTwo->id,
        ]);
        $this->assertDatabaseHas('contact_company_location', [
            'contact_id' => $specificFactoryContact['id'],
            'company_location_id' => $soharFactory['id'],
        ]);
        $this->assertDatabaseMissing('contact_company_location', [
            'contact_id' => $allFactoriesContact['id'],
        ]);
    }

    public function test_admin_can_create_a_company_with_buyer_and_supplier_profiles(): void
    {
        $context = $this->managementContext();
        $manufacturer = $this->manufacturer($context['country'], 'Integrated Equipment Manufacturing');
        $payload = $this->supplierPayload($context, [$manufacturer->id]);
        $payload['company']['name'] = 'Integrated Trading and Services LLC';
        $payload['company']['company_code'] = 'ITS';
        $payload['company']['code_slug'] = 'integrated-trading-services';
        $payload['roles']['is_buyer'] = true;
        $payload['contacts'][0]['serves_buyer'] = true;
        $payload['contacts'][0]['is_primary_buyer'] = true;

        $response = $this->withBearerToken($context['admin'])
            ->postJson('/api/company-management', $payload)
            ->assertCreated()
            ->assertJsonPath('data.roles.is_buyer', true)
            ->assertJsonPath('data.roles.is_supplier', true);

        $this->assertNotNull($response->json('data.buyer_profile'));
        $this->assertNotNull($response->json('data.supplier_profile'));
        $this->assertDatabaseHas('companies', [
            'id' => $response->json('data.company.id'),
            'company_type' => 'mixed',
        ]);
        $this->assertDatabaseHas('buyer_profiles', [
            'company_id' => $response->json('data.company.id'),
        ]);
        $this->assertDatabaseHas('suppliers', [
            'company_id' => $response->json('data.company.id'),
        ]);
    }

    public function test_company_detail_returns_roles_profiles_contacts_locations_and_history(): void
    {
        $context = $this->managementContext();
        $manufacturer = $this->manufacturer($context['country'], 'Profile History Manufacturing');
        $payload = $this->supplierPayload($context, [$manufacturer->id]);
        $payload['company']['name'] = 'Profile History Company LLC';
        $payload['company']['company_code'] = 'PHC';
        $payload['company']['code_slug'] = 'profile-history-company';
        $payload['roles']['is_buyer'] = true;
        $payload['contacts'][0]['serves_buyer'] = true;
        $payload['contacts'][0]['is_primary_buyer'] = true;

        $companyId = $this->withBearerToken($context['admin'])
            ->postJson('/api/company-management', $payload)
            ->assertCreated()
            ->json('data.company.id');

        $this->withBearerToken($context['admin'])
            ->getJson("/api/company-management/{$companyId}")
            ->assertOk()
            ->assertJsonPath('data.company.id', $companyId)
            ->assertJsonPath('data.roles.is_buyer', true)
            ->assertJsonPath('data.roles.is_supplier', true)
            ->assertJsonStructure([
                'data' => [
                    'company',
                    'roles' => ['is_internal', 'is_buyer', 'is_supplier'],
                    'buyer_profile',
                    'supplier_profile',
                    'manufacturers',
                    'locations',
                    'contacts',
                    'history' => [
                        'counts',
                        'quotations',
                        'buyer_pos',
                        'supplier_pos',
                    ],
                ],
            ]);
    }

    public function test_admin_can_update_a_company_and_add_another_factory_and_contact(): void
    {
        $context = $this->managementContext();
        $manufacturer = $this->manufacturer($context['country'], 'Expansion Manufacturing');
        $payload = $this->supplierPayload($context, [$manufacturer->id]);
        $payload['locations'] = [$payload['locations'][0]];
        $payload['contacts'] = [$payload['contacts'][0]];

        $created = $this->withBearerToken($context['admin'])
            ->postJson('/api/company-management', $payload)
            ->assertCreated()
            ->json('data');

        $companyId = $created['company']['id'];
        $existingLocation = $created['locations'][0];
        $existingContact = $created['contacts'][0];

        $payload['company']['phone'] = '+968 2400 9999';
        $payload['locations'] = [
            [
                'id' => $existingLocation['id'],
                'client_key' => 'factory-muscat',
                'name' => 'Muscat Factory',
                'location' => 'Rusayl Industrial Estate',
                'location_type' => 'factory',
                'country_id' => $context['country']->id,
                'address' => 'Road 12, Rusayl',
                'manufacturer_id' => $manufacturer->id,
                'status' => 'active',
            ],
            [
                'client_key' => 'factory-duqm',
                'name' => 'Duqm Factory',
                'location' => 'Duqm Special Economic Zone',
                'location_type' => 'factory',
                'country_id' => $context['country']->id,
                'address' => 'Heavy Industries Area, Duqm',
                'manufacturer_id' => $manufacturer->id,
                'status' => 'active',
            ],
        ];
        $payload['contacts'] = [
            [
                ...$payload['contacts'][0],
                'id' => $existingContact['id'],
            ],
            [
                'designation_id' => $context['designation']->id,
                'name' => 'Maha Al Balushi',
                'job_title' => 'Duqm Factory Coordinator',
                'mobile' => '+968 9900 0033',
                'telephone' => '+968 2400 0033',
                'extension' => '303',
                'email' => 'maha@example.test',
                'fax' => null,
                'status' => 'active',
                'serves_buyer' => false,
                'serves_supplier' => true,
                'is_primary_buyer' => false,
                'is_primary_supplier' => false,
                'all_locations' => false,
                'location_keys' => ['factory-duqm'],
            ],
        ];

        $response = $this->withBearerToken($context['admin'])
            ->putJson("/api/company-management/{$companyId}", $payload)
            ->assertOk()
            ->assertJsonPath('data.company.phone', '+968 2400 9999');

        $this->assertCount(2, $response->json('data.locations'));
        $this->assertCount(2, $response->json('data.contacts'));

        $duqmFactory = collect($response->json('data.locations'))->firstWhere('name', 'Duqm Factory');
        $duqmContact = collect($response->json('data.contacts'))->firstWhere('email', 'maha@example.test');

        $this->assertSame([$duqmFactory['id']], $duqmContact['location_ids'] ?? []);
        $this->assertDatabaseHas('contact_company_location', [
            'contact_id' => $duqmContact['id'],
            'company_location_id' => $duqmFactory['id'],
        ]);
    }

    public function test_authenticated_user_without_company_permissions_cannot_use_company_management(): void
    {
        $context = $this->managementContext();
        $salesperson = $this->userWithRole(
            'Unprivileged Salesperson',
            'unprivileged-sales@example.test',
            'salesperson',
        );

        $this->withBearerToken($salesperson)
            ->getJson('/api/company-management/options')
            ->assertForbidden();

        $this->withBearerToken($salesperson)
            ->getJson('/api/company-management')
            ->assertForbidden();

        $this->withBearerToken($salesperson)
            ->postJson('/api/company-management', $this->buyerPayload($context))
            ->assertForbidden();
    }

    public function test_invalid_contact_factory_key_is_rejected_without_creating_partial_company_data(): void
    {
        $context = $this->managementContext();
        $manufacturer = $this->manufacturer($context['country'], 'Transactional Manufacturing');
        $payload = $this->supplierPayload($context, [$manufacturer->id]);
        $payload['company']['name'] = 'Transactional Supplier LLC';
        $payload['company']['company_code'] = 'TSL';
        $payload['company']['code_slug'] = 'transactional-supplier';
        $payload['contacts'][1]['location_keys'] = ['factory-not-in-payload'];

        $this->withBearerToken($context['admin'])
            ->postJson('/api/company-management', $payload)
            ->assertUnprocessable();

        $this->assertDatabaseMissing('companies', ['company_code' => 'TSL']);
        $this->assertDatabaseMissing('contacts', ['email' => 'layla@example.test']);
        $this->assertDatabaseMissing('contacts', ['email' => 'hamad@example.test']);
    }

    public function test_company_identifiers_are_normalized_before_unique_validation(): void
    {
        $context = $this->managementContext();
        $payload = $this->buyerPayload($context);
        $payload['company']['name'] = 'Automatic Code Slug LLC';
        $payload['company']['company_code'] = ' acd ';
        unset($payload['company']['code_slug']);

        $this->withBearerToken($context['admin'])
            ->postJson('/api/company-management', $payload)
            ->assertCreated()
            ->assertJsonPath('data.company.company_code', 'ACD')
            ->assertJsonPath('data.company.code_slug', 'acd');

        $payload['company']['name'] = 'Conflicting Normalized Slug LLC';
        $payload['company']['company_code'] = 'A-C-D';
        $payload['company']['code_slug'] = ' ACD ';

        $this->withBearerToken($context['admin'])
            ->postJson('/api/company-management', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['company.code_slug']);
    }

    public function test_primary_contacts_are_active_and_defaulted_consistently(): void
    {
        $context = $this->managementContext();
        $payload = $this->buyerPayload($context);
        $payload['contacts'][0]['status'] = 'inactive';
        $payload['contacts'][0]['is_primary_buyer'] = false;
        $payload['contacts'][1]['is_primary_buyer'] = false;

        $response = $this->withBearerToken($context['admin'])
            ->postJson('/api/company-management', $payload)
            ->assertCreated();

        $activeContact = collect($response->json('data.contacts'))->firstWhere('email', 'salim@example.test');
        $this->assertTrue((bool) $activeContact['is_primary_buyer']);
        $this->assertSame($activeContact['id'], $response->json('data.buyer_profile.primary_contact_id'));

        $invalid = $this->buyerPayload($context);
        $invalid['company']['name'] = 'Inactive Contact Buyer LLC';
        $invalid['company']['company_code'] = 'ICB';
        $invalid['company']['code_slug'] = 'inactive-contact-buyer';
        $invalid['contacts'][0]['status'] = 'inactive';
        $invalid['contacts'][1]['status'] = 'inactive';

        $this->withBearerToken($context['admin'])
            ->postJson('/api/company-management', $invalid)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contacts']);
    }

    public function test_factory_manufacturer_must_be_linked_to_the_supplier(): void
    {
        $context = $this->managementContext();
        $linkedManufacturer = $this->manufacturer($context['country'], 'Linked Factory Manufacturing');
        $unlinkedManufacturer = $this->manufacturer($context['country'], 'Unlinked Factory Manufacturing');
        $payload = $this->supplierPayload($context, [$linkedManufacturer->id]);
        $payload['locations'][0]['manufacturer_id'] = $unlinkedManufacturer->id;

        $this->withBearerToken($context['admin'])
            ->postJson('/api/company-management', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['locations.0.manufacturer_id']);
    }

    public function test_omitted_saved_factories_and_contacts_are_deactivated_not_deleted(): void
    {
        $context = $this->managementContext();
        $manufacturer = $this->manufacturer($context['country'], 'Historical Factory Manufacturing');
        $payload = $this->supplierPayload($context, [$manufacturer->id]);

        $created = $this->withBearerToken($context['admin'])
            ->postJson('/api/company-management', $payload)
            ->assertCreated()
            ->json('data');

        $keptLocation = collect($created['locations'])->firstWhere('name', 'Muscat Factory');
        $omittedLocation = collect($created['locations'])->firstWhere('name', 'Sohar Factory');
        $keptContact = collect($created['contacts'])->firstWhere('email', 'layla@example.test');
        $omittedContact = collect($created['contacts'])->firstWhere('email', 'hamad@example.test');
        $payload['locations'][0]['id'] = $keptLocation['id'];
        $payload['locations'] = [$payload['locations'][0]];
        $payload['contacts'][0]['id'] = $keptContact['id'];
        $payload['contacts'] = [$payload['contacts'][0]];

        $response = $this->withBearerToken($context['admin'])
            ->putJson("/api/company-management/{$created['company']['id']}", $payload)
            ->assertOk();

        $this->assertCount(2, $response->json('data.locations'));
        $this->assertCount(2, $response->json('data.contacts'));
        $this->assertDatabaseHas('company_locations', ['id' => $omittedLocation['id'], 'status' => 'inactive']);
        $this->assertDatabaseHas('contacts', ['id' => $omittedContact['id'], 'status' => 'inactive']);
    }

    public function test_inactive_manufacturer_history_can_be_retained_and_supplier_role_removed_cleanly(): void
    {
        $context = $this->managementContext();
        $manufacturer = $this->manufacturer($context['country'], 'Retained Historical Manufacturer');
        $payload = $this->supplierPayload($context, [$manufacturer->id]);
        $created = $this->withBearerToken($context['admin'])
            ->postJson('/api/company-management', $payload)
            ->assertCreated()
            ->json('data');

        foreach ($payload['locations'] as $index => &$location) {
            $location['id'] = $created['locations'][$index]['id'];
        }
        unset($location);
        foreach ($payload['contacts'] as $index => &$contact) {
            $contact['id'] = $created['contacts'][$index]['id'];
        }
        unset($contact);

        $manufacturer->update(['status' => 'inactive']);

        $this->withBearerToken($context['admin'])
            ->putJson("/api/company-management/{$created['company']['id']}", $payload)
            ->assertOk()
            ->assertJsonPath('data.manufacturers.0.status', 'inactive');

        $payload['roles'] = ['is_internal' => false, 'is_buyer' => true, 'is_supplier' => false];
        $payload['manufacturer_ids'] = [];
        foreach ($payload['locations'] as &$location) {
            $location['status'] = 'inactive';
        }
        unset($location);
        $payload['contacts'][0]['serves_buyer'] = true;
        $payload['contacts'][0]['is_primary_buyer'] = true;

        $response = $this->withBearerToken($context['admin'])
            ->putJson("/api/company-management/{$created['company']['id']}", $payload)
            ->assertOk()
            ->assertJsonPath('data.roles.is_buyer', true)
            ->assertJsonPath('data.roles.is_supplier', false)
            ->assertJsonPath('data.supplier_profile', null);

        $this->assertSame([], $response->json('data.manufacturers'));
        $this->assertCount(2, $response->json('data.locations'));
        $this->assertDatabaseHas('suppliers', [
            'company_id' => $created['company']['id'],
            'status' => 'inactive',
        ]);
    }

    public function test_legacy_company_contact_and_supplier_mutations_are_read_only(): void
    {
        $context = $this->managementContext();

        foreach (['companies', 'contacts', 'suppliers'] as $resource) {
            $this->withBearerToken($context['admin'])
                ->postJson("/api/admin/{$resource}", [])
                ->assertConflict()
                ->assertJsonPath('message', 'Manage companies, contacts, and supplier details through the company profile.');
        }
    }

    public function test_quotation_options_recognize_buyer_profile_when_legacy_company_type_is_stale(): void
    {
        $context = $this->managementContext();
        $companyId = $this->withBearerToken($context['admin'])
            ->postJson('/api/company-management', $this->buyerPayload($context))
            ->assertCreated()
            ->json('data.company.id');

        Company::query()->whereKey($companyId)->update(['company_type' => 'supplier']);

        $salesperson = $this->userWithRole(
            'Quotation Salesperson',
            'quotation-sales@example.test',
            'salesperson',
        );
        $internalCompany = Company::query()->where('company_code', 'ISC')->firstOrFail();
        $salesContact = Contact::query()->create([
            'company_id' => $internalCompany->id,
            'name' => $salesperson->name,
            'email' => $salesperson->email,
            'serves_supplier' => true,
            'is_primary_supplier' => true,
            'all_locations' => true,
            'is_primary' => true,
            'status' => 'active',
        ]);
        $salesperson->forceFill(['contact_id' => $salesContact->id])->save();
        Supplier::query()->create([
            'company_id' => $internalCompany->id,
            'primary_contact_id' => $salesContact->id,
            'status' => 'active',
        ]);
        $response = $this->withBearerToken($salesperson)
            ->getJson('/api/quotations/create-options')
            ->assertOk();

        $this->assertContains($companyId, collect($response->json('buyers'))->pluck('id')->all());
    }

    /**
     * @return array{admin: User, country: Country, designation: Designation}
     */
    private function managementContext(): array
    {
        $this->seed(FoundationSeeder::class);

        return [
            'admin' => $this->userWithRole('Company Admin', 'company-admin@example.test', 'admin'),
            'country' => Country::query()->where('country_code', 'OM')->firstOrFail(),
            'designation' => Designation::query()->where('name', 'Mr.')->firstOrFail(),
        ];
    }

    /**
     * @param  array{admin: User, country: Country, designation: Designation}  $context
     * @return array<string, mixed>
     */
    private function buyerPayload(array $context): array
    {
        return [
            'company' => [
                'country_id' => $context['country']->id,
                'name' => 'Oman Energy Procurement LLC',
                'company_code' => 'OEP',
                'code_slug' => 'oman-energy-procurement',
                'postal_code' => '112',
                'vendor_code' => 'BUY-001',
                'location' => 'Muscat',
                'address' => 'Al Khuwair, Muscat',
                'email' => 'info@oep.example.test',
                'phone' => '+968 2400 1000',
                'vat_tin' => 'OM110000001',
                'status' => 'active',
            ],
            'roles' => [
                'is_internal' => false,
                'is_buyer' => true,
                'is_supplier' => false,
            ],
            'manufacturer_ids' => [],
            'locations' => [],
            'contacts' => [
                [
                    'designation_id' => $context['designation']->id,
                    'name' => 'Aisha Al Harthy',
                    'job_title' => 'Procurement Manager',
                    'mobile' => '+968 9900 0011',
                    'telephone' => '+968 2400 1011',
                    'extension' => '101',
                    'email' => 'aisha@example.test',
                    'fax' => null,
                    'status' => 'active',
                    'serves_buyer' => true,
                    'serves_supplier' => false,
                    'is_primary_buyer' => true,
                    'is_primary_supplier' => false,
                    'all_locations' => false,
                    'location_keys' => [],
                ],
                [
                    'designation_id' => $context['designation']->id,
                    'name' => 'Salim Al Lawati',
                    'job_title' => 'Accounts Officer',
                    'mobile' => '+968 9900 0022',
                    'telephone' => '+968 2400 1022',
                    'extension' => '102',
                    'email' => 'salim@example.test',
                    'fax' => null,
                    'status' => 'active',
                    'serves_buyer' => true,
                    'serves_supplier' => false,
                    'is_primary_buyer' => false,
                    'is_primary_supplier' => false,
                    'all_locations' => false,
                    'location_keys' => [],
                ],
            ],
        ];
    }

    /**
     * @param  array{admin: User, country: Country, designation: Designation}  $context
     * @param  array<int, int>  $manufacturerIds
     * @return array<string, mixed>
     */
    private function supplierPayload(array $context, array $manufacturerIds): array
    {
        return [
            'company' => [
                'country_id' => $context['country']->id,
                'name' => 'Gulf Source Industries LLC',
                'company_code' => 'GSI',
                'code_slug' => 'gulf-source-industries',
                'postal_code' => '322',
                'vendor_code' => 'SUP-001',
                'location' => 'Sohar',
                'address' => 'Sohar Industrial Port',
                'email' => 'info@gsi.example.test',
                'phone' => '+968 2600 1000',
                'vat_tin' => 'OM220000001',
                'status' => 'active',
            ],
            'roles' => [
                'is_internal' => false,
                'is_buyer' => false,
                'is_supplier' => true,
            ],
            'manufacturer_ids' => $manufacturerIds,
            'locations' => [
                [
                    'client_key' => 'factory-muscat',
                    'name' => 'Muscat Factory',
                    'location' => 'Rusayl Industrial Estate',
                    'location_type' => 'factory',
                    'country_id' => $context['country']->id,
                    'address' => 'Road 12, Rusayl',
                    'manufacturer_id' => $manufacturerIds[0] ?? null,
                    'status' => 'active',
                ],
                [
                    'client_key' => 'factory-sohar',
                    'name' => 'Sohar Factory',
                    'location' => 'Sohar Industrial Port',
                    'location_type' => 'factory',
                    'country_id' => $context['country']->id,
                    'address' => 'Block B, Sohar',
                    'manufacturer_id' => $manufacturerIds[1] ?? $manufacturerIds[0] ?? null,
                    'status' => 'active',
                ],
            ],
            'contacts' => [
                [
                    'designation_id' => $context['designation']->id,
                    'name' => 'Layla Al Riyami',
                    'job_title' => 'Sales Director',
                    'mobile' => '+968 9900 0101',
                    'telephone' => '+968 2600 1101',
                    'extension' => '201',
                    'email' => 'layla@example.test',
                    'fax' => null,
                    'status' => 'active',
                    'serves_buyer' => false,
                    'serves_supplier' => true,
                    'is_primary_buyer' => false,
                    'is_primary_supplier' => true,
                    'all_locations' => true,
                    'location_keys' => [],
                ],
                [
                    'designation_id' => $context['designation']->id,
                    'name' => 'Hamad Al Habsi',
                    'job_title' => 'Sohar Factory Coordinator',
                    'mobile' => '+968 9900 0202',
                    'telephone' => '+968 2600 1202',
                    'extension' => '202',
                    'email' => 'hamad@example.test',
                    'fax' => null,
                    'status' => 'active',
                    'serves_buyer' => false,
                    'serves_supplier' => true,
                    'is_primary_buyer' => false,
                    'is_primary_supplier' => false,
                    'all_locations' => false,
                    'location_keys' => ['factory-sohar'],
                ],
            ],
        ];
    }

    private function manufacturer(Country $country, string $name): Manufacturer
    {
        return Manufacturer::query()->create([
            'country_id' => $country->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function userWithRole(string $name, string $email, string $roleSlug): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->firstOrFail());

        return $user;
    }

    private function withBearerToken(User $user): self
    {
        $token = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('token');

        return $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/json');
    }
}
