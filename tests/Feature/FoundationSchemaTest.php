<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Database\Seeders\FoundationSeeder;
use Tests\TestCase;

class FoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_master_data_tables_needed_before_quotation_work_starts(): void
    {
        foreach ([
            'roles',
            'permissions',
            'role_permissions',
            'user_roles',
            'countries',
            'designations',
            'incoterms',
            'companies',
            'contacts',
            'manufacturers',
            'suppliers',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");
        }
    }

    public function test_it_stores_company_identity_and_address_fields_used_by_document_references(): void
    {
        foreach ([
            'country_id',
            'name',
            'company_code',
            'code_slug',
            'postal_code',
            'vendor_code',
            'location',
            'email',
            'phone',
            'vat_tin',
            'company_type',
            'status',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('companies', $column), "Missing companies.{$column}.");
        }
    }

    public function test_it_stores_contacts_against_one_company_with_designation_and_communication_details(): void
    {
        foreach ([
            'company_id',
            'designation_id',
            'name',
            'job_title',
            'mobile',
            'telephone',
            'extension',
            'email',
            'fax',
            'is_primary',
            'status',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('contacts', $column), "Missing contacts.{$column}.");
        }
    }

    public function test_manufacturers_store_only_name_country_and_status_master_data(): void
    {
        foreach ([
            'id',
            'country_id',
            'name',
            'status',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('manufacturers', $column), "Missing manufacturers.{$column}.");
        }

        foreach ([
            'company_id',
            'primary_contact_id',
        ] as $column) {
            $this->assertFalse(Schema::hasColumn('manufacturers', $column), "Unexpected manufacturers.{$column}.");
        }
    }

    public function test_suppliers_can_optionally_link_to_a_manufacturer(): void
    {
        foreach ([
            'id',
            'company_id',
            'primary_contact_id',
            'manufacturer_id',
            'status',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suppliers', $column), "Missing suppliers.{$column}.");
        }
    }

    public function test_it_seeds_the_fixed_operational_roles_for_admin_salesperson_and_follow_up_work(): void
    {
        $this->seed(FoundationSeeder::class);

        $roles = DB::table('roles')->pluck('name')->all();

        $this->assertContains('Admin', $roles);
        $this->assertContains('Salesperson', $roles);
        $this->assertContains('Follow-Up', $roles);
    }

    public function test_incoterms_store_only_the_admin_master_data_fields(): void
    {
        foreach ([
            'id',
            'code',
            'name',
            'description',
            'reminder_days_before_delivery',
            'status',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('incoterms', $column), "Missing incoterms.{$column}.");
        }

        foreach ([
            'delivery_responsibility',
            'shipping_documents_required',
            'agent_required',
        ] as $column) {
            $this->assertFalse(Schema::hasColumn('incoterms', $column), "Unexpected incoterms.{$column}.");
        }
    }

    public function test_follow_up_comments_store_the_workflow_stage_for_audit_trails(): void
    {
        foreach ([
            'id',
            'follow_up_item_id',
            'user_id',
            'stage',
            'comment',
            'communication_type',
            'contacted_person',
            'next_action',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('follow_up_comments', $column), "Missing follow_up_comments.{$column}.");
        }
    }

    public function test_follow_up_audit_logs_store_each_progress_event_for_timeline_reporting(): void
    {
        $this->assertTrue(Schema::hasTable('follow_up_audit_logs'), 'Missing follow_up_audit_logs table.');

        foreach ([
            'id',
            'follow_up_item_id',
            'user_id',
            'stage',
            'action',
            'summary',
            'properties',
            'occurred_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('follow_up_audit_logs', $column), "Missing follow_up_audit_logs.{$column}.");
        }
    }
}
