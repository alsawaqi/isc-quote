<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Designation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserPermissionAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_salesperson_with_create_country_permission_can_only_create_countries(): void
    {
        $this->seed(FoundationSeeder::class);

        $salesperson = $this->userWithRole('Sales User', 'sales@example.test', 'salesperson');
        $salesperson->permissions()->attach(Permission::where('slug', 'create-countries')->firstOrFail());

        $token = $this->tokenFor('sales@example.test');

        $countryId = $this->withToken($token)
            ->postJson('/api/admin/countries', [
                'name' => 'Kuwait',
                'country_code' => 'KW',
                'phone_code' => '+965',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Country created successfully.')
            ->json('data.id');

        $this->withToken($token)
            ->putJson("/api/admin/countries/{$countryId}", [
                'name' => 'Kuwait State',
                'country_code' => 'KW',
                'phone_code' => '+965',
                'status' => 'active',
            ])
            ->assertForbidden();

        $this->withToken($token)
            ->deleteJson("/api/admin/countries/{$countryId}")
            ->assertForbidden();
    }

    public function test_user_permissions_are_returned_during_login(): void
    {
        $this->seed(FoundationSeeder::class);

        $salesperson = $this->userWithRole('Sales User', 'sales@example.test', 'salesperson');
        $salesperson->permissions()->attach(Permission::where('slug', 'create-countries')->firstOrFail());

        $this->postJson('/api/login', [
            'email' => 'sales@example.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.permissions.0.slug', 'create-countries');
    }

    public function test_admin_can_assign_direct_permissions_to_a_user(): void
    {
        $this->seed(FoundationSeeder::class);

        $admin = $this->userWithRole('Admin User', 'admin@example.test', 'admin');
        $salesperson = $this->userWithRole('Sales User', 'sales@example.test', 'salesperson');
        $permission = Permission::where('slug', 'create-countries')->firstOrFail();
        $salesRole = Role::where('slug', 'salesperson')->firstOrFail();

        $this->withToken($this->tokenFor($admin->email))
            ->putJson("/api/admin/users/{$salesperson->id}", [
                'name' => 'Sales User',
                'email' => 'sales@example.test',
                'role_ids' => [$salesRole->id],
                'direct_permission_ids' => [$permission->id],
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.direct_permission_ids.0', $permission->id);

        $this->assertTrue($salesperson->fresh()->permissions->contains($permission));
    }

    public function test_creating_salesperson_creates_default_supplier_contact(): void
    {
        $this->seed(FoundationSeeder::class);

        $admin = $this->userWithRole('Admin User', 'admin@example.test', 'admin');
        $salesRole = Role::where('slug', 'salesperson')->firstOrFail();
        $designation = Designation::where('name', 'Mr.')->firstOrFail();
        $internalCompany = Company::where('company_code', 'ISC')->firstOrFail();

        $response = $this->withToken($this->tokenFor($admin->email))
            ->postJson('/api/admin/users', [
                'name' => 'Manu Thuruthel',
                'email' => 'manu@example.test',
                'password' => 'password',
                'role_ids' => [$salesRole->id],
                'direct_permission_ids' => [],
                'salesperson_contact_name' => 'Manu Thuruthel',
                'salesperson_designation_id' => $designation->id,
                'salesperson_job_title' => 'Sales Engineer',
                'salesperson_mobile' => '+96890000001',
                'salesperson_telephone' => '+96824000000',
                'salesperson_extension' => '101',
                'salesperson_contact_email' => 'manu@example.test',
                'salesperson_fax' => '+96824000001',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.contact_name', 'Manu Thuruthel')
            ->assertJsonPath('data.supplier_company_id', $internalCompany->id);

        $contactId = $response->json('data.contact_id');

        $this->assertDatabaseHas('contacts', [
            'id' => $contactId,
            'company_id' => $internalCompany->id,
            'designation_id' => $designation->id,
            'name' => 'Manu Thuruthel',
            'job_title' => 'Sales Engineer',
            'mobile' => '+96890000001',
            'telephone' => '+96824000000',
            'extension' => '101',
            'email' => 'manu@example.test',
            'fax' => '+96824000001',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('suppliers', [
            'company_id' => $internalCompany->id,
            'primary_contact_id' => $contactId,
            'status' => 'active',
        ]);
        $this->assertSame($contactId, User::where('email', 'manu@example.test')->firstOrFail()->contact_id);
    }

    public function test_multiple_salespeople_can_be_supplier_contacts_for_default_company(): void
    {
        $this->seed(FoundationSeeder::class);

        $admin = $this->userWithRole('Admin User', 'admin@example.test', 'admin');
        $salesRole = Role::where('slug', 'salesperson')->firstOrFail();
        $internalCompany = Company::where('company_code', 'ISC')->firstOrFail();

        foreach ([
            ['name' => 'Sales One', 'email' => 'sales-one@example.test'],
            ['name' => 'Sales Two', 'email' => 'sales-two@example.test'],
        ] as $salesperson) {
            $this->withToken($this->tokenFor($admin->email))
                ->postJson('/api/admin/users', [
                    'name' => $salesperson['name'],
                    'email' => $salesperson['email'],
                    'password' => 'password',
                    'role_ids' => [$salesRole->id],
                    'direct_permission_ids' => [],
                    'salesperson_contact_name' => $salesperson['name'],
                    'salesperson_contact_email' => $salesperson['email'],
                    'status' => 'active',
                ])
                ->assertCreated();
        }

        $this->assertSame(2, Supplier::where('company_id', $internalCompany->id)->count());
        $this->assertSame(2, Contact::where('company_id', $internalCompany->id)->count());
    }

    public function test_only_the_three_fixed_roles_can_exist(): void
    {
        $this->seed(FoundationSeeder::class);

        $admin = $this->userWithRole('Admin User', 'admin@example.test', 'admin');

        $this->withToken($this->tokenFor($admin->email))
            ->postJson('/api/admin/roles', [
                'name' => 'Manager',
                'slug' => 'manager',
                'status' => 'active',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Roles are fixed. Assign permissions to users instead.');
    }

    private function userWithRole(string $name, string $email, string $roleSlug): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $user->roles()->attach(Role::where('slug', $roleSlug)->firstOrFail());

        return $user;
    }

    private function tokenFor(string $email): string
    {
        return $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'password',
        ])->json('token');
    }
}
