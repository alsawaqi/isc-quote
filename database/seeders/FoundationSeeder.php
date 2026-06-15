<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FoundationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $permissions = collect([
            ...$this->actionPermissions('countries', 'Country'),
            ...$this->actionPermissions('designations', 'Designation'),
            ...$this->actionPermissions('companies', 'Company'),
            ...$this->actionPermissions('contacts', 'Contact'),
            ...$this->actionPermissions('incoterms', 'Incoterm'),
            ...$this->actionPermissions('manufacturers', 'Manufacturer'),
            ...$this->actionPermissions('suppliers', 'Supplier'),
            ['group' => 'Users', 'name' => 'Manage Users', 'slug' => 'manage-users'],
            ['group' => 'Users', 'name' => 'Manage Fixed Roles', 'slug' => 'manage-roles'],
            ['group' => 'Sales', 'name' => 'Create Quotations', 'slug' => 'create-quotations'],
            ['group' => 'Sales', 'name' => 'Create Supplier POs', 'slug' => 'create-supplier-pos'],
            ['group' => 'Follow-Up', 'name' => 'Manage Follow-Ups', 'slug' => 'manage-follow-ups'],
        ])->map(fn (array $permission) => [
            'name' => $permission['name'],
            'slug' => $permission['slug'],
            'group' => $permission['group'],
            'description' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('permissions')->upsert($permissions, ['slug'], ['name', 'group', 'updated_at']);
        DB::table('permissions')->whereNotIn('slug', collect($permissions)->pluck('slug'))->delete();

        $roles = [
            [
                'name' => 'Admin',
                'description' => 'Can manage users, roles, permissions, and all master data.',
            ],
            [
                'name' => 'Salesperson',
                'description' => 'Can create quotations, record buyer POs, and create supplier or manufacturer POs.',
            ],
            [
                'name' => 'Follow-Up',
                'description' => 'Can track supplier acknowledgements, delivery dates, documents, logistics, and comments.',
            ],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['slug' => Str::slug($role['name'])],
                [
                    'name' => $role['name'],
                    'description' => $role['description'],
                    'is_system' => true,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $allPermissionIds = DB::table('permissions')->pluck('id')->all();
        $salesPermissionIds = DB::table('permissions')
            ->whereIn('slug', ['create-quotations', 'create-supplier-pos'])
            ->pluck('id')
            ->all();
        $followUpPermissionIds = DB::table('permissions')
            ->where('slug', 'manage-follow-ups')
            ->pluck('id')
            ->all();

        $this->syncRolePermissions('admin', $allPermissionIds, $now);
        $this->syncRolePermissions('salesperson', $salesPermissionIds, $now);
        $this->syncRolePermissions('follow-up', $followUpPermissionIds, $now);

        $this->seedReferenceData($now);
    }

    /**
     * @return array<int, array{group: string, name: string, slug: string}>
     */
    private function actionPermissions(string $resource, string $singularLabel): array
    {
        return [
            ['group' => 'Master Data', 'name' => "View {$singularLabel}s", 'slug' => "view-{$resource}"],
            ['group' => 'Master Data', 'name' => "Add {$singularLabel}", 'slug' => "create-{$resource}"],
            ['group' => 'Master Data', 'name' => "Edit {$singularLabel}", 'slug' => "update-{$resource}"],
            ['group' => 'Master Data', 'name' => "Delete {$singularLabel}", 'slug' => "delete-{$resource}"],
        ];
    }

    /**
     * @param  array<int>  $permissionIds
     */
    private function syncRolePermissions(string $roleSlug, array $permissionIds, mixed $timestamp): void
    {
        $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');

        if (! $roleId) {
            return;
        }

        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                [
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ],
                [
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            );
        }
    }

    private function seedReferenceData(mixed $timestamp): void
    {
        foreach ([
            ['name' => 'Oman', 'country_code' => 'OM', 'phone_code' => '+968'],
            ['name' => 'United Arab Emirates', 'country_code' => 'AE', 'phone_code' => '+971'],
            ['name' => 'Saudi Arabia', 'country_code' => 'SA', 'phone_code' => '+966'],
        ] as $country) {
            DB::table('countries')->updateOrInsert(
                ['country_code' => $country['country_code']],
                [
                    ...$country,
                    'status' => 'active',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            );
        }

        $omanId = DB::table('countries')->where('country_code', 'OM')->value('id');

        DB::table('companies')->updateOrInsert(
            ['company_code' => 'ISC'],
            [
                'country_id' => $omanId,
                'name' => 'Industrial Supplies Center LLC',
                'code_slug' => 'isc',
                'postal_code' => null,
                'vendor_code' => null,
                'location' => 'Muscat',
                'address' => null,
                'email' => null,
                'phone' => null,
                'vat_tin' => null,
                'company_type' => 'internal',
                'status' => 'active',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );

        foreach (['Mr.', 'Ms.', 'Mrs.', 'Dr.', 'Eng.'] as $designation) {
            DB::table('designations')->updateOrInsert(
                ['code' => Str::upper(Str::slug($designation, ''))],
                [
                    'name' => $designation,
                    'status' => 'active',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            );
        }

        foreach ([
            ['code' => 'DDP', 'name' => 'Delivered Duty Paid', 'reminder_days_before_delivery' => 40],
            ['code' => 'CPT', 'name' => 'Carriage Paid To', 'reminder_days_before_delivery' => 30],
            ['code' => 'FOB', 'name' => 'Free On Board', 'reminder_days_before_delivery' => 30],
            ['code' => 'EXW', 'name' => 'Ex Works', 'reminder_days_before_delivery' => 20],
            ['code' => 'CIF', 'name' => 'Cost, Insurance and Freight', 'reminder_days_before_delivery' => 35],
        ] as $incoterm) {
            DB::table('incoterms')->updateOrInsert(
                ['code' => $incoterm['code']],
                [
                    ...$incoterm,
                    'description' => null,
                    'status' => 'active',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            );
        }
    }
}
