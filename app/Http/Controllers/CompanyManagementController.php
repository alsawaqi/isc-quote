<?php

namespace App\Http\Controllers;

use App\Models\BuyerPo;
use App\Models\BuyerProfile;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Designation;
use App\Models\FollowUpItem;
use App\Models\Invoice;
use App\Models\Manufacturer;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\SupplierPo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CompanyManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompanies($request, 'view');

        $search = Str::limit(trim((string) $request->query('search', '')), 120, '');
        $role = (string) $request->query('role', 'all');
        $role = in_array($role, ['all', 'internal', 'buyer', 'supplier', 'mixed'], true) ? $role : 'all';

        $query = Company::query()
            ->with(['country', 'buyerProfile', 'suppliers.manufacturers'])
            ->withCount([
                'contacts as contacts_count' => fn (Builder $contacts) => $contacts->where('status', 'active'),
                'locations as locations_count' => fn (Builder $locations) => $locations
                    ->where('status', 'active')
                    ->where('location_type', 'factory'),
            ]);

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->where('name', 'like', $like)
                    ->orWhere('company_code', 'like', $like)
                    ->orWhere('vendor_code', 'like', $like)
                    ->orWhere('location', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        $this->applyRoleFilter($query, $role);

        $perPage = min(max($request->integer('per_page', 50), 10), 100);
        $paginator = $query->orderBy('name')->paginate($perPage);
        $companies = $paginator->getCollection()
            ->map(fn (Company $company): array => $this->summary($company))
            ->values();

        return response()->json([
            'data' => $companies,
            'meta' => [
                'total' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'role' => $role,
                'search' => $search,
            ],
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $this->authorizeCompanies($request, 'view');

        return response()->json([
            'data' => [
                'countries' => Country::query()
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->get(['id', 'name', 'country_code']),
                'designations' => Designation::query()
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->get(['id', 'name', 'code']),
                'manufacturers' => Manufacturer::query()
                    ->with('country:id,name')
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->get(['id', 'country_id', 'name'])
                    ->map(fn (Manufacturer $manufacturer): array => [
                        'id' => $manufacturer->id,
                        'name' => $manufacturer->name,
                        'country_id' => $manufacturer->country_id,
                        'country_name' => $manufacturer->country?->name,
                        'status' => $manufacturer->status,
                    ])
                    ->values(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompanies($request, 'create');
        $validated = $this->validatedAggregate($request);

        $company = DB::transaction(fn (): Company => $this->persistAggregate(new Company, $validated));

        return response()->json([
            'message' => 'Company profile created successfully.',
            'data' => $this->profile($company),
        ], 201);
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        $this->authorizeCompanies($request, 'view');

        return response()->json(['data' => $this->profile($company)]);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $this->authorizeCompanies($request, 'update');
        $validated = $this->validatedAggregate($request, $company);

        $company = DB::transaction(fn (): Company => $this->persistAggregate($company, $validated));

        return response()->json([
            'message' => 'Company profile updated successfully.',
            'data' => $this->profile($company),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAggregate(Request $request, ?Company $company = null): array
    {
        $companyId = $company?->id;
        $currentManufacturerIds = $company
            ? Supplier::query()
                ->where('company_id', $company->id)
                ->with('manufacturers:id')
                ->get()
                ->flatMap(fn (Supplier $supplier) => $supplier->manufacturers->pluck('id')->push($supplier->manufacturer_id))
                ->filter()
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all()
            : [];

        $companyInput = $request->input('company');

        if (is_array($companyInput)) {
            $companyCode = Str::upper(trim((string) ($companyInput['company_code'] ?? '')));
            $requestedSlug = trim((string) ($companyInput['code_slug'] ?? ''));
            $companyInput['company_code'] = $companyCode;
            $companyInput['code_slug'] = Str::slug($requestedSlug !== '' ? $requestedSlug : $companyCode);
            $request->merge(['company' => $companyInput]);
        }

        $validated = $request->validate([
            'company' => ['required', 'array'],
            'company.country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'company.name' => ['required', 'string', 'max:255'],
            'company.company_code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('companies', 'company_code')->ignore($companyId),
            ],
            'company.code_slug' => [
                'required',
                'string',
                'max:64',
                Rule::unique('companies', 'code_slug')->ignore($companyId),
            ],
            'company.postal_code' => ['nullable', 'string', 'max:32'],
            'company.vendor_code' => ['nullable', 'string', 'max:64'],
            'company.location' => ['nullable', 'string', 'max:255'],
            'company.address' => ['nullable', 'string'],
            'company.email' => ['nullable', 'email', 'max:255'],
            'company.phone' => ['nullable', 'string', 'max:255'],
            'company.vat_tin' => ['nullable', 'string', 'max:255'],
            'company.status' => ['required', Rule::in(['active', 'inactive'])],

            'roles' => ['required', 'array'],
            'roles.is_internal' => ['required', 'boolean'],
            'roles.is_buyer' => ['required', 'boolean'],
            'roles.is_supplier' => ['required', 'boolean'],

            'manufacturer_ids' => ['present', 'array'],
            'manufacturer_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('manufacturers', 'id')->where(fn ($query) => $query
                    ->where(function ($manufacturer) use ($currentManufacturerIds): void {
                        $manufacturer->where('status', 'active');

                        if ($currentManufacturerIds !== []) {
                            $manufacturer->orWhereIn('id', $currentManufacturerIds);
                        }
                    })),
            ],

            'locations' => ['present', 'array', 'max:100'],
            'locations.*.id' => ['nullable', 'integer', 'exists:company_locations,id'],
            'locations.*.client_key' => ['required', 'string', 'max:100', 'distinct'],
            'locations.*.name' => ['required', 'string', 'max:255'],
            'locations.*.location' => ['required', 'string', 'max:255'],
            'locations.*.location_type' => ['nullable', Rule::in(['factory', 'branch', 'office', 'warehouse'])],
            'locations.*.country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'locations.*.manufacturer_id' => ['nullable', 'integer', 'exists:manufacturers,id'],
            'locations.*.address' => ['nullable', 'string'],
            'locations.*.status' => ['required', Rule::in(['active', 'inactive'])],

            'contacts' => ['required', 'array', 'min:1', 'max:100'],
            'contacts.*.id' => ['nullable', 'integer', 'exists:contacts,id'],
            'contacts.*.designation_id' => ['nullable', 'integer', 'exists:designations,id'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.job_title' => ['nullable', 'string', 'max:255'],
            'contacts.*.mobile' => ['nullable', 'string', 'max:255'],
            'contacts.*.telephone' => ['nullable', 'string', 'max:255'],
            'contacts.*.extension' => ['nullable', 'string', 'max:24'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.fax' => ['nullable', 'string', 'max:255'],
            'contacts.*.status' => ['required', Rule::in(['active', 'inactive'])],
            'contacts.*.serves_buyer' => ['required', 'boolean'],
            'contacts.*.serves_supplier' => ['required', 'boolean'],
            'contacts.*.all_locations' => ['required', 'boolean'],
            'contacts.*.is_primary_buyer' => ['required', 'boolean'],
            'contacts.*.is_primary_supplier' => ['required', 'boolean'],
            'contacts.*.location_keys' => ['present', 'array'],
            'contacts.*.location_keys.*' => ['string', 'max:100', 'distinct'],
        ]);

        $roles = $this->normalizedRoles($validated['roles']);

        if (! $roles['is_supplier']) {
            $validated['manufacturer_ids'] = [];
        }

        foreach ($validated['contacts'] as &$contact) {
            if ($contact['status'] !== 'active') {
                $contact['is_primary_buyer'] = false;
                $contact['is_primary_supplier'] = false;
            }

            if (! $roles['is_buyer']) {
                $contact['serves_buyer'] = false;
                $contact['is_primary_buyer'] = false;
            }

            if (! $roles['is_supplier'] || ! $contact['serves_supplier']) {
                $contact['serves_supplier'] = false;
                $contact['is_primary_supplier'] = false;
                $contact['all_locations'] = false;
                $contact['location_keys'] = [];
            }
        }
        unset($contact);

        $contacts = collect($validated['contacts']);
        $activeContacts = $contacts->where('status', 'active');

        if (! $roles['is_buyer'] && ! $roles['is_supplier']) {
            throw ValidationException::withMessages([
                'roles' => 'Select at least one company capability.',
            ]);
        }

        if ($roles['is_supplier'] && ! $roles['is_internal'] && empty($validated['manufacturer_ids'])) {
            throw ValidationException::withMessages([
                'manufacturer_ids' => 'Select at least one manufacturer for an external supplier.',
            ]);
        }

        if ($roles['is_buyer'] && ! $activeContacts->contains(fn (array $contact): bool => (bool) $contact['serves_buyer'])) {
            throw ValidationException::withMessages([
                'contacts' => 'Add at least one active contact who serves the buyer role.',
            ]);
        }

        if ($roles['is_supplier'] && ! $activeContacts->contains(fn (array $contact): bool => (bool) $contact['serves_supplier'])) {
            throw ValidationException::withMessages([
                'contacts' => 'Add at least one active contact who serves the supplier role.',
            ]);
        }

        if ($activeContacts->where('serves_buyer', true)->where('is_primary_buyer', true)->count() > 1) {
            throw ValidationException::withMessages([
                'contacts' => 'Select only one primary buyer contact.',
            ]);
        }

        if ($activeContacts->where('serves_supplier', true)->where('is_primary_supplier', true)->count() > 1) {
            throw ValidationException::withMessages([
                'contacts' => 'Select only one primary supplier contact.',
            ]);
        }

        $locationKeys = collect($validated['locations'])->pluck('client_key')->all();
        $manufacturerIds = array_map('intval', $validated['manufacturer_ids']);

        foreach ($validated['locations'] as $index => $location) {
            if (
                $location['status'] === 'active'
                && ($location['manufacturer_id'] ?? null) !== null
                && ! in_array((int) $location['manufacturer_id'], $manufacturerIds, true)
            ) {
                throw ValidationException::withMessages([
                    "locations.{$index}.manufacturer_id" => 'The factory manufacturer must be linked to this supplier.',
                ]);
            }
        }

        $activeLocationKeys = collect($validated['locations'])
            ->where('status', 'active')
            ->pluck('client_key')
            ->all();

        foreach ($validated['contacts'] as $index => $contact) {
            $unknownKeys = array_values(array_diff($contact['location_keys'], $locationKeys));

            if ($unknownKeys !== []) {
                throw ValidationException::withMessages([
                    "contacts.{$index}.location_keys" => 'A selected factory does not belong to this company payload.',
                ]);
            }

            if (
                $roles['is_supplier']
                && $contact['status'] === 'active'
                && $contact['serves_supplier']
                && ! $contact['all_locations']
                && $activeLocationKeys !== []
                && array_intersect($contact['location_keys'], $activeLocationKeys) === []
            ) {
                throw ValidationException::withMessages([
                    "contacts.{$index}.location_keys" => 'Select at least one factory or choose all factories.',
                ]);
            }
        }

        if ($company) {
            $invalidLocation = collect($validated['locations'])
                ->whereNotNull('id')
                ->contains(fn (array $location): bool => ! $company->locations()->whereKey($location['id'])->exists());
            $invalidContact = $contacts
                ->whereNotNull('id')
                ->contains(fn (array $contact): bool => ! $company->contacts()->whereKey($contact['id'])->exists());

            if ($invalidLocation || $invalidContact) {
                throw ValidationException::withMessages([
                    'company' => 'A contact or factory does not belong to this company.',
                ]);
            }
        }

        $validated['roles'] = $roles;

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function persistAggregate(Company $company, array $validated): Company
    {
        $companyData = $validated['company'];
        $roles = $validated['roles'];
        $companyData['name'] = trim((string) $companyData['name']);
        $companyData['company_code'] = Str::upper(trim((string) $companyData['company_code']));
        $companyData['code_slug'] = Str::slug((string) ($companyData['code_slug'] ?: $companyData['company_code']));
        $companyData['company_type'] = $this->companyType($roles);
        $company->fill($companyData)->save();

        [, $locationsByKey] = $this->persistLocations($company, $validated['locations']);
        $contacts = $this->persistContacts($company, $validated['contacts'], $locationsByKey);

        $buyerPrimary = $this->primaryContact($contacts, 'is_primary_buyer', 'serves_buyer');
        $supplierPrimary = $this->primaryContact($contacts, 'is_primary_supplier', 'serves_supplier');

        if ($roles['is_buyer']) {
            BuyerProfile::query()->updateOrCreate(
                ['company_id' => $company->id],
                [
                    'primary_contact_id' => $buyerPrimary?->id,
                    'status' => $company->status,
                ]
            );
        } else {
            BuyerProfile::query()->where('company_id', $company->id)->delete();
        }

        if ($roles['is_supplier']) {
            $supplier = Supplier::query()
                ->where('company_id', $company->id)
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->first()
                ?? new Supplier(['company_id' => $company->id]);
            $supplier->fill([
                'primary_contact_id' => $supplierPrimary?->id,
                'manufacturer_id' => Arr::first($validated['manufacturer_ids']),
                'status' => $company->status,
            ])->save();
            $supplier->manufacturers()->sync($validated['manufacturer_ids']);

            Supplier::query()
                ->where('company_id', $company->id)
                ->whereKeyNot($supplier->id)
                ->update(['status' => 'inactive']);
        } else {
            Supplier::query()->where('company_id', $company->id)->update(['status' => 'inactive']);
        }

        return $company->fresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $locations
     * @return array{0: array<int, int>, 1: array<string, int>}
     */
    private function persistLocations(Company $company, array $locations): array
    {
        $ids = [];
        $byKey = [];

        foreach ($locations as $locationData) {
            $location = ! empty($locationData['id'])
                ? $company->locations()->whereKey($locationData['id'])->firstOrFail()
                : new CompanyLocation(['company_id' => $company->id]);
            $location->fill([
                'manufacturer_id' => $locationData['manufacturer_id'] ?? null,
                'country_id' => $locationData['country_id'] ?? null,
                'location_type' => $locationData['location_type'] ?? 'factory',
                'name' => trim((string) $locationData['name']),
                'location' => trim((string) $locationData['location']),
                'address' => $locationData['address'] ?? null,
                'status' => $locationData['status'],
            ])->save();

            $ids[] = $location->id;
            $byKey[(string) $locationData['client_key']] = $location->id;
        }

        $obsolete = $company->locations()->when($ids !== [], fn (Builder $query) => $query->whereNotIn('id', $ids));
        $obsolete->update(['status' => 'inactive']);

        return [$ids, $byKey];
    }

    /**
     * @param  array<int, array<string, mixed>>  $contacts
     * @param  array<string, int>  $locationsByKey
     * @return Collection<int, Contact>
     */
    private function persistContacts(Company $company, array $contacts, array $locationsByKey): Collection
    {
        $saved = collect();
        $ids = [];
        $contactPayload = collect($contacts);
        $buyerPrimaryIndex = $contactPayload->search(fn (array $contact): bool => $contact['status'] === 'active'
            && (bool) $contact['serves_buyer']
            && (bool) $contact['is_primary_buyer']);
        $supplierPrimaryIndex = $contactPayload->search(fn (array $contact): bool => $contact['status'] === 'active'
            && (bool) $contact['serves_supplier']
            && (bool) $contact['is_primary_supplier']);

        if ($buyerPrimaryIndex === false) {
            $buyerPrimaryIndex = $contactPayload->search(fn (array $contact): bool => $contact['status'] === 'active'
                && (bool) $contact['serves_buyer']);
        }

        if ($supplierPrimaryIndex === false) {
            $supplierPrimaryIndex = $contactPayload->search(fn (array $contact): bool => $contact['status'] === 'active'
                && (bool) $contact['serves_supplier']);
        }

        foreach ($contacts as $index => $contactData) {
            $contact = ! empty($contactData['id'])
                ? $company->contacts()->whereKey($contactData['id'])->firstOrFail()
                : new Contact(['company_id' => $company->id]);

            $isPrimaryBuyer = $buyerPrimaryIndex !== false && $buyerPrimaryIndex === $index;
            $isPrimarySupplier = $supplierPrimaryIndex !== false && $supplierPrimaryIndex === $index;

            $contact->fill([
                'designation_id' => $contactData['designation_id'] ?? null,
                'name' => trim((string) $contactData['name']),
                'job_title' => $contactData['job_title'] ?? null,
                'mobile' => $contactData['mobile'] ?? null,
                'telephone' => $contactData['telephone'] ?? null,
                'extension' => $contactData['extension'] ?? null,
                'email' => $contactData['email'] ?? null,
                'fax' => $contactData['fax'] ?? null,
                'serves_buyer' => (bool) $contactData['serves_buyer'],
                'serves_supplier' => (bool) $contactData['serves_supplier'],
                'all_locations' => (bool) $contactData['all_locations'],
                'is_primary_buyer' => $isPrimaryBuyer,
                'is_primary_supplier' => $isPrimarySupplier,
                'is_primary' => $isPrimaryBuyer || $isPrimarySupplier,
                'status' => $contactData['status'],
            ])->save();

            $locationIds = $contactData['all_locations']
                ? []
                : collect($contactData['location_keys'])
                    ->map(fn (string $key): ?int => $locationsByKey[$key] ?? null)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

            DB::table('contact_company_location')->where('contact_id', $contact->id)->delete();

            foreach ($locationIds as $locationId) {
                DB::table('contact_company_location')->insert([
                    'contact_id' => $contact->id,
                    'company_location_id' => $locationId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $ids[] = $contact->id;
            $saved->push($contact);
        }

        $omitted = $company->contacts()->when($ids !== [], fn (Builder $query) => $query->whereNotIn('id', $ids));
        $omitted->update([
            'serves_buyer' => false,
            'serves_supplier' => false,
            'is_primary_buyer' => false,
            'is_primary_supplier' => false,
            'is_primary' => false,
            'status' => 'inactive',
        ]);

        return $saved;
    }

    /**
     * @param  Collection<int, Contact>  $contacts
     */
    private function primaryContact(Collection $contacts, string $primaryField, string $roleField): ?Contact
    {
        return $contacts->first(fn (Contact $contact): bool => (bool) $contact->{$primaryField} && (bool) $contact->{$roleField})
            ?? $contacts->first(fn (Contact $contact): bool => (bool) $contact->{$roleField} && $contact->status === 'active');
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(Company $company): array
    {
        $company->load([
            'country',
            'buyerProfile.primaryContact',
            'suppliers.primaryContact',
            'suppliers.manufacturers.country',
            'locations.country',
            'locations.manufacturer',
            'contacts.designation',
            'contacts.locations',
        ]);

        $roles = $this->rolesFor($company);
        $supplier = $roles['is_supplier']
            ? $company->suppliers->sortByDesc(fn (Supplier $item): int => $item->status === 'active' ? 1 : 0)->first()
            : null;
        $manufacturers = $supplier?->manufacturers ?? collect();

        if ($manufacturers->isEmpty() && $supplier?->manufacturer) {
            $manufacturers = collect([$supplier->manufacturer]);
        }

        return [
            'company' => [
                'id' => $company->id,
                'country_id' => $company->country_id,
                'country_name' => $company->country?->name,
                'name' => $company->name,
                'company_code' => $company->company_code,
                'code_slug' => $company->code_slug,
                'postal_code' => $company->postal_code,
                'vendor_code' => $company->vendor_code,
                'location' => $company->location,
                'address' => $company->address,
                'email' => $company->email,
                'phone' => $company->phone,
                'vat_tin' => $company->vat_tin,
                'company_type' => $company->company_type,
                'status' => $company->status,
                'created_at' => $company->created_at?->toDateTimeString(),
                'updated_at' => $company->updated_at?->toDateTimeString(),
            ],
            'roles' => $roles,
            'buyer_profile' => $company->buyerProfile ? [
                'id' => $company->buyerProfile->id,
                'primary_contact_id' => $company->buyerProfile->primary_contact_id,
                'primary_contact_name' => $company->buyerProfile->primaryContact?->name,
                'status' => $company->buyerProfile->status,
            ] : null,
            'supplier_profile' => $supplier ? [
                'id' => $supplier->id,
                'primary_contact_id' => $supplier->primary_contact_id,
                'primary_contact_name' => $supplier->primaryContact?->name,
                'status' => $supplier->status,
            ] : null,
            'manufacturers' => $manufacturers->map(fn (Manufacturer $manufacturer): array => [
                'id' => $manufacturer->id,
                'name' => $manufacturer->name,
                'country_id' => $manufacturer->country_id,
                'country_name' => $manufacturer->country?->name,
                'status' => $manufacturer->status,
            ])->values(),
            'locations' => $company->locations->map(fn (CompanyLocation $location): array => [
                'id' => $location->id,
                'client_key' => "location-{$location->id}",
                'company_id' => $location->company_id,
                'manufacturer_id' => $location->manufacturer_id,
                'manufacturer_name' => $location->manufacturer?->name,
                'country_id' => $location->country_id,
                'country_name' => $location->country?->name,
                'location_type' => $location->location_type,
                'name' => $location->name,
                'location' => $location->location,
                'address' => $location->address,
                'status' => $location->status,
            ])->values(),
            'contacts' => $company->contacts->map(fn (Contact $contact): array => [
                'id' => $contact->id,
                'designation_id' => $contact->designation_id,
                'designation_name' => $contact->designation?->name,
                'name' => $contact->name,
                'job_title' => $contact->job_title,
                'mobile' => $contact->mobile,
                'telephone' => $contact->telephone,
                'extension' => $contact->extension,
                'email' => $contact->email,
                'fax' => $contact->fax,
                'status' => $contact->status,
                'serves_buyer' => (bool) $contact->serves_buyer,
                'serves_supplier' => (bool) $contact->serves_supplier,
                'all_locations' => (bool) $contact->all_locations,
                'is_primary_buyer' => (bool) $contact->is_primary_buyer,
                'is_primary_supplier' => (bool) $contact->is_primary_supplier,
                'location_ids' => $contact->locations->pluck('id')->values(),
                'location_keys' => $contact->locations->map(fn (CompanyLocation $location): string => "location-{$location->id}")->values(),
                'locations' => $contact->locations->map(fn (CompanyLocation $location): array => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'location' => $location->location,
                ])->values(),
            ])->values(),
            'history' => $this->history($company),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function history(Company $company): array
    {
        $quotationQuery = Quotation::query()
            ->where(fn (Builder $query) => $query
                ->where('buyer_company_id', $company->id)
                ->orWhere('supplier_company_id', $company->id));
        $quotationIds = (clone $quotationQuery)->select('id');
        $buyerPoQuery = BuyerPo::query()->where('buyer_company_id', $company->id);
        $supplierPoQuery = SupplierPo::query()
            ->with('companyLocation:id,name,location')
            ->where(fn (Builder $query) => $query
                ->where('supplier_company_id', $company->id)
                ->orWhere('buyer_company_id', $company->id));
        $followUpQuery = FollowUpItem::query()->whereIn('quotation_id', clone $quotationIds);
        $invoiceQuery = Invoice::query()->whereHas('followUpItem', fn (Builder $query) => $query->whereIn('quotation_id', clone $quotationIds));

        return [
            'counts' => [
                'quotations' => (clone $quotationQuery)->count(),
                'buyer_pos' => (clone $buyerPoQuery)->count(),
                'supplier_pos' => (clone $supplierPoQuery)->count(),
                'follow_up_items' => (clone $followUpQuery)->count(),
                'invoices' => (clone $invoiceQuery)->count(),
            ],
            'quotations' => (clone $quotationQuery)
                ->latest('created_at')
                ->limit(10)
                ->get(['id', 'quotation_reference', 'buyer_company_id', 'supplier_company_id', 'status', 'created_at'])
                ->map(fn (Quotation $quotation): array => [
                    'id' => $quotation->id,
                    'reference' => $quotation->quotation_reference,
                    'role' => $quotation->buyer_company_id === $company->id ? 'buyer' : 'supplier',
                    'status' => $quotation->status,
                    'created_at' => $quotation->created_at?->toDateTimeString(),
                ])->values(),
            'buyer_pos' => (clone $buyerPoQuery)
                ->latest('po_date')
                ->limit(10)
                ->get(['id', 'po_number', 'po_date', 'po_value', 'currency', 'status'])
                ->map(fn (BuyerPo $buyerPo): array => [
                    'id' => $buyerPo->id,
                    'reference' => $buyerPo->po_number,
                    'date' => $buyerPo->po_date?->toDateString(),
                    'value' => (string) $buyerPo->po_value,
                    'currency' => $buyerPo->currency,
                    'status' => $buyerPo->status,
                ])->values(),
            'supplier_pos' => (clone $supplierPoQuery)
                ->latest('created_at')
                ->limit(10)
                ->get(['id', 'po_reference', 'supplier_company_id', 'buyer_company_id', 'company_location_id', 'total_amount', 'accepted_invoice_currency', 'status', 'created_at'])
                ->map(fn (SupplierPo $supplierPo): array => [
                    'id' => $supplierPo->id,
                    'reference' => $supplierPo->po_reference,
                    'role' => $supplierPo->supplier_company_id === $company->id ? 'supplier' : 'buyer',
                    'factory_id' => $supplierPo->company_location_id,
                    'factory_name' => $supplierPo->companyLocation?->name,
                    'factory_location' => $supplierPo->companyLocation?->location,
                    'value' => (string) $supplierPo->total_amount,
                    'currency' => $supplierPo->accepted_invoice_currency,
                    'status' => $supplierPo->status,
                    'created_at' => $supplierPo->created_at?->toDateTimeString(),
                ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Company $company): array
    {
        $roles = $this->rolesFor($company);
        $manufacturerNames = $company->suppliers
            ->where('status', 'active')
            ->flatMap(fn (Supplier $supplier) => $supplier->manufacturers)
            ->pluck('name')
            ->unique()
            ->values();

        return [
            'id' => $company->id,
            'name' => $company->name,
            'company_code' => $company->company_code,
            'country_name' => $company->country?->name,
            'location' => $company->location,
            'email' => $company->email,
            'phone' => $company->phone,
            'status' => $company->status,
            'roles' => $roles,
            'manufacturer_names' => $manufacturerNames,
            'contacts_count' => (int) ($company->contacts_count ?? 0),
            'locations_count' => (int) ($company->locations_count ?? 0),
        ];
    }

    /**
     * @return array{is_internal: bool, is_buyer: bool, is_supplier: bool, label: string}
     */
    private function rolesFor(Company $company): array
    {
        $isInternal = $company->company_type === 'internal';
        $isBuyer = $company->buyerProfile !== null || in_array($company->company_type, ['buyer', 'mixed', 'internal'], true);
        $isSupplier = $company->suppliers->contains(fn (Supplier $supplier): bool => $supplier->status === 'active')
            || in_array($company->company_type, ['supplier', 'manufacturer', 'mixed', 'internal'], true);

        return [
            'is_internal' => $isInternal,
            'is_buyer' => $isBuyer,
            'is_supplier' => $isSupplier,
            'label' => match (true) {
                $isInternal => 'Internal Company',
                $isBuyer && $isSupplier => 'Buyer & Supplier',
                $isSupplier => 'Supplier',
                $isBuyer => 'Buyer',
                default => 'Unclassified ('.Str::headline($company->company_type).')',
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $roles
     * @return array{is_internal: bool, is_buyer: bool, is_supplier: bool}
     */
    private function normalizedRoles(array $roles): array
    {
        $isInternal = (bool) $roles['is_internal'];

        return [
            'is_internal' => $isInternal,
            'is_buyer' => $isInternal || (bool) $roles['is_buyer'],
            'is_supplier' => $isInternal || (bool) $roles['is_supplier'],
        ];
    }

    /**
     * @param  array{is_internal: bool, is_buyer: bool, is_supplier: bool}  $roles
     */
    private function companyType(array $roles): string
    {
        return match (true) {
            $roles['is_internal'] => 'internal',
            $roles['is_buyer'] && $roles['is_supplier'] => 'mixed',
            $roles['is_supplier'] => 'supplier',
            default => 'buyer',
        };
    }

    private function applyRoleFilter(Builder $query, string $role): void
    {
        match ($role) {
            'internal' => $query->where('company_type', 'internal'),
            'buyer' => $query->where(fn (Builder $builder) => $builder
                ->whereHas('buyerProfile')
                ->orWhereIn('company_type', ['buyer', 'mixed', 'internal'])),
            'supplier' => $query->where(fn (Builder $builder) => $builder
                ->whereHas('suppliers', fn (Builder $supplier) => $supplier->where('status', 'active'))
                ->orWhereIn('company_type', ['supplier', 'manufacturer', 'mixed', 'internal'])),
            'mixed' => $query
                ->where(fn (Builder $builder) => $builder
                    ->whereHas('buyerProfile')
                    ->orWhereIn('company_type', ['mixed', 'internal']))
                ->where(fn (Builder $builder) => $builder
                    ->whereHas('suppliers', fn (Builder $supplier) => $supplier->where('status', 'active'))
                    ->orWhereIn('company_type', ['mixed', 'internal'])),
            default => null,
        };
    }

    private function authorizeCompanies(Request $request, string $action): void
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if ($user->hasRole('admin')) {
            return;
        }

        if ($action === 'view') {
            foreach (['view', 'create', 'update', 'delete'] as $availableAction) {
                if ($user->hasPermission("{$availableAction}-companies")) {
                    return;
                }
            }
        } elseif ($user->hasPermission("{$action}-companies")) {
            return;
        }

        abort(403);
    }
}
