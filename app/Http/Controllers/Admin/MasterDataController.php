<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Designation;
use App\Models\Incoterm;
use App\Models\Manufacturer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MasterDataController extends Controller
{
    public function index(Request $request, string $resource): JsonResponse
    {
        $config = $this->config($resource);
        $this->authorizeResource($request, $resource, 'view');

        /** @var class-string<Model> $model */
        $model = $config['model'];
        $search = trim((string) $request->query('search', ''));

        $query = $model::query();

        if ($config['with'] !== []) {
            $query->with($config['with']);
        }

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($config, $search): void {
                foreach ($config['search'] as $column) {
                    $builder->orWhere($column, 'like', "%{$search}%");
                }
            });
        }

        $records = $query
            ->orderBy((string) $config['order_by'])
            ->limit(250)
            ->get()
            ->map(fn (Model $record) => $this->transform($resource, $record))
            ->values();

        return response()->json([
            'data' => $records,
            'meta' => [
                'total' => $records->count(),
            ],
            'options' => $this->optionsPayload(),
        ]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $config = $this->config($resource);
        $this->authorizeResource($request, $resource, 'create');

        if ($resource === 'roles') {
            return response()->json([
                'message' => 'Roles are fixed. Assign permissions to users instead.',
            ], 422);
        }

        $data = $this->validated($request, $resource);
        $record = $this->persist($resource, new $config['model'], $data);

        return response()->json([
            'message' => "{$config['label']} created successfully.",
            'data' => $this->transform($resource, $record),
        ], 201);
    }

    public function update(Request $request, string $resource, int $id): JsonResponse
    {
        $config = $this->config($resource);
        $this->authorizeResource($request, $resource, 'update');

        /** @var class-string<Model> $model */
        $model = $config['model'];
        $record = $model::query()->findOrFail($id);
        $data = $this->validated($request, $resource, $id);
        $record = $this->persist($resource, $record, $data);

        return response()->json([
            'message' => "{$config['label']} updated successfully.",
            'data' => $this->transform($resource, $record),
        ]);
    }

    public function destroy(Request $request, string $resource, int $id): JsonResponse
    {
        $config = $this->config($resource);
        $this->authorizeResource($request, $resource, 'delete');

        /** @var class-string<Model> $model */
        $model = $config['model'];
        $record = $model::query()->findOrFail($id);

        if ($record instanceof User && $request->user()?->is($record)) {
            return response()->json([
                'message' => 'You cannot delete your own user account.',
            ], 422);
        }

        if ($record instanceof Role && $record->is_system) {
            return response()->json([
                'message' => 'System roles cannot be deleted.',
            ], 422);
        }

        try {
            $record->delete();
        } catch (QueryException) {
            return response()->json([
                'message' => "{$config['label']} cannot be deleted because it is linked to other records.",
            ], 409);
        }

        return response()->json([
            'message' => "{$config['label']} deleted successfully.",
        ]);
    }

    public function options(): JsonResponse
    {
        $user = request()->user();

        if (! $user || (! $user->hasRole('admin') && $user->effectivePermissionSlugs()->isEmpty())) {
            abort(403);
        }

        return response()->json([
            'data' => $this->optionsPayload(),
        ]);
    }

    private function authorizeResource(Request $request, string $resource, string $action): void
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if ($user->hasRole('admin')) {
            return;
        }

        if (in_array($resource, ['users', 'roles'], true)) {
            abort(403);
        }

        if ($action === 'view') {
            foreach (['view', 'create', 'update', 'delete'] as $availableAction) {
                if ($user->hasPermission("{$availableAction}-{$resource}")) {
                    return;
                }
            }

            abort(403);
        }

        if (! $user->hasPermission("{$action}-{$resource}")) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function config(string $resource): array
    {
        return match ($resource) {
            'countries' => [
                'model' => Country::class,
                'label' => 'Country',
                'order_by' => 'name',
                'search' => ['name', 'country_code', 'phone_code', 'status'],
                'with' => [],
            ],
            'designations' => [
                'model' => Designation::class,
                'label' => 'Designation',
                'order_by' => 'name',
                'search' => ['name', 'code', 'status'],
                'with' => [],
            ],
            'companies' => [
                'model' => Company::class,
                'label' => 'Company',
                'order_by' => 'name',
                'search' => ['name', 'company_code', 'code_slug', 'vendor_code', 'location', 'email', 'company_type', 'status'],
                'with' => ['country'],
            ],
            'contacts' => [
                'model' => Contact::class,
                'label' => 'Contact',
                'order_by' => 'name',
                'search' => ['name', 'job_title', 'mobile', 'telephone', 'email', 'status'],
                'with' => ['company', 'designation'],
            ],
            'incoterms' => [
                'model' => Incoterm::class,
                'label' => 'Incoterm',
                'order_by' => 'code',
                'search' => ['code', 'name', 'description', 'status'],
                'with' => [],
            ],
            'uoms' => [
                'model' => Uom::class,
                'label' => 'UOM',
                'order_by' => 'code',
                'search' => ['code', 'name', 'status'],
                'with' => [],
            ],
            'currencies' => [
                'model' => Currency::class,
                'label' => 'Currency',
                'order_by' => 'code',
                'search' => ['code', 'name', 'status'],
                'with' => [],
            ],
            'manufacturers' => [
                'model' => Manufacturer::class,
                'label' => 'Manufacturer',
                'order_by' => 'name',
                'search' => ['name', 'status'],
                'with' => ['country'],
            ],
            'suppliers' => [
                'model' => Supplier::class,
                'label' => 'Supplier',
                'order_by' => 'id',
                'search' => ['status'],
                'with' => ['company.country', 'primaryContact', 'manufacturer'],
            ],
            'users' => [
                'model' => User::class,
                'label' => 'User',
                'order_by' => 'name',
                'search' => ['name', 'email', 'status'],
                'with' => ['roles', 'permissions', 'contact.company', 'contact.designation'],
            ],
            'roles' => [
                'model' => Role::class,
                'label' => 'Role',
                'order_by' => 'name',
                'search' => ['name', 'slug', 'description', 'status'],
                'with' => ['permissions'],
            ],
            default => abort(404),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $resource, ?int $id = null): array
    {
        $statusRule = ['required', Rule::in(['active', 'inactive'])];
        $requiresSalespersonContact = $resource === 'users' && $id === null && $this->requestIncludesRole($request, 'salesperson');

        $rules = match ($resource) {
            'countries' => [
                'name' => ['required', 'string', 'max:255', Rule::unique('countries', 'name')->ignore($id)],
                'country_code' => ['required', 'string', 'max:8', Rule::unique('countries', 'country_code')->ignore($id)],
                'phone_code' => ['nullable', 'string', 'max:12'],
                'status' => $statusRule,
            ],
            'designations' => [
                'name' => ['required', 'string', 'max:255', Rule::unique('designations', 'name')->ignore($id)],
                'code' => ['nullable', 'string', 'max:16', Rule::unique('designations', 'code')->ignore($id)],
                'status' => $statusRule,
            ],
            'companies' => [
                'country_id' => ['nullable', 'integer', 'exists:countries,id'],
                'name' => ['required', 'string', 'max:255'],
                'company_code' => ['required', 'string', 'max:32', Rule::unique('companies', 'company_code')->ignore($id)],
                'code_slug' => ['nullable', 'string', 'max:64', Rule::unique('companies', 'code_slug')->ignore($id)],
                'postal_code' => ['nullable', 'string', 'max:32'],
                'vendor_code' => ['nullable', 'string', 'max:64'],
                'location' => ['nullable', 'string', 'max:255'],
                'address' => ['nullable', 'string'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:255'],
                'vat_tin' => ['nullable', 'string', 'max:255'],
                'company_type' => ['required', Rule::in(['internal', 'buyer', 'supplier', 'manufacturer', 'shipping_agent', 'mixed'])],
                'status' => $statusRule,
            ],
            'contacts' => [
                'company_id' => ['required', 'integer', 'exists:companies,id'],
                'designation_id' => ['nullable', 'integer', 'exists:designations,id'],
                'name' => ['required', 'string', 'max:255'],
                'job_title' => ['nullable', 'string', 'max:255'],
                'mobile' => ['nullable', 'string', 'max:255'],
                'telephone' => ['nullable', 'string', 'max:255'],
                'extension' => ['nullable', 'string', 'max:24'],
                'email' => ['nullable', 'email', 'max:255'],
                'fax' => ['nullable', 'string', 'max:255'],
                'is_primary' => ['boolean'],
                'status' => $statusRule,
            ],
            'incoterms' => [
                'code' => ['required', 'string', 'max:12', Rule::unique('incoterms', 'code')->ignore($id)],
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'reminder_days_before_delivery' => ['required', 'integer', 'min:0', 'max:365'],
                'status' => $statusRule,
            ],
            'uoms' => [
                'code' => ['required', 'string', 'max:24', Rule::unique('uoms', 'code')->ignore($id)],
                'name' => ['required', 'string', 'max:255'],
                'status' => $statusRule,
            ],
            'currencies' => [
                'code' => ['required', 'string', 'max:8', Rule::unique('currencies', 'code')->ignore($id)],
                'name' => ['required', 'string', 'max:255'],
                'exchange_rate' => ['required', 'numeric', 'min:0', 'max:999999999'],
                'status' => $statusRule,
            ],
            'manufacturers' => [
                'country_id' => ['required', 'integer', 'exists:countries,id'],
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('manufacturers', 'name')->where(fn ($query) => $query->where('country_id', $request->input('country_id')))->ignore($id),
                ],
                'status' => $statusRule,
            ],
            'suppliers' => [
                'company_id' => ['required', 'integer', 'exists:companies,id'],
                'primary_contact_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('company_id', $request->input('company_id'))),
                    Rule::unique('suppliers', 'primary_contact_id')
                        ->where(fn ($query) => $query->where('company_id', $request->input('company_id')))
                        ->ignore($id),
                ],
                'manufacturer_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('manufacturers', 'id')->where(fn ($query) => $query->where('status', 'active')),
                ],
                'status' => $statusRule,
            ],
            'users' => [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
                'password' => [$id ? 'nullable' : 'required', 'string', 'min:8'],
                'role_ids' => ['required', 'array', 'min:1'],
                'role_ids.*' => ['integer', 'exists:roles,id'],
                'direct_permission_ids' => ['array'],
                'direct_permission_ids.*' => ['integer', 'exists:permissions,id'],
                'salesperson_contact_name' => [$requiresSalespersonContact ? 'required' : 'nullable', 'string', 'max:255'],
                'salesperson_designation_id' => ['nullable', 'integer', 'exists:designations,id'],
                'salesperson_job_title' => ['nullable', 'string', 'max:255'],
                'salesperson_mobile' => ['nullable', 'string', 'max:255'],
                'salesperson_telephone' => ['nullable', 'string', 'max:255'],
                'salesperson_extension' => ['nullable', 'string', 'max:24'],
                'salesperson_contact_email' => ['nullable', 'email', 'max:255'],
                'salesperson_fax' => ['nullable', 'string', 'max:255'],
                'status' => $statusRule,
            ],
            'roles' => [
                'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($id)],
                'slug' => ['nullable', 'string', 'max:255', Rule::unique('roles', 'slug')->ignore($id)],
                'description' => ['nullable', 'string'],
                'permission_ids' => ['array'],
                'permission_ids.*' => ['integer', 'exists:permissions,id'],
                'status' => $statusRule,
            ],
            default => abort(404),
        };

        return $request->validate($rules);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(string $resource, Model $record, array $data): Model
    {
        if ($resource === 'designations' && empty($data['code'])) {
            $data['code'] = Str::upper(Str::slug((string) $data['name'], ''));
        }

        if ($resource === 'companies' && empty($data['code_slug'])) {
            $data['code_slug'] = Str::slug((string) $data['company_code']);
        }

        if ($resource === 'contacts') {
            $data['is_primary'] = (bool) ($data['is_primary'] ?? false);
        }

        if (in_array($resource, ['uoms', 'currencies'], true)) {
            $data['code'] = Str::upper(trim((string) $data['code']));
            $data['name'] = trim((string) $data['name']);
        }

        if ($record instanceof User) {
            $roleIds = Arr::pull($data, 'role_ids', []);
            $directPermissionIds = Arr::pull($data, 'direct_permission_ids', []);
            $salespersonContact = $this->extractSalespersonContact($data);

            if (empty($data['password'])) {
                unset($data['password']);
            } else {
                $data['password'] = Hash::make((string) $data['password']);
            }

            return DB::transaction(function () use ($record, $data, $roleIds, $directPermissionIds, $salespersonContact): User {
                if ($this->roleIdsIncludeSlug($roleIds, 'salesperson') && $this->hasSalespersonContactData($salespersonContact)) {
                    $contact = $this->saveSalespersonContact(
                        $record,
                        $salespersonContact,
                        (string) ($data['name'] ?? $record->name),
                        (string) ($data['email'] ?? $record->email)
                    );
                    $data['contact_id'] = $contact->id;
                }

                $record->fill($data)->save();
                $record->roles()->sync($roleIds);
                $record->permissions()->sync($directPermissionIds);

                return $record->load(['roles', 'permissions', 'contact.company', 'contact.designation']);
            });
        }

        if ($record instanceof Role) {
            $permissionIds = Arr::pull($data, 'permission_ids', []);

            if (empty($data['slug'])) {
                $data['slug'] = Str::slug((string) $data['name']);
            }

            $record->fill($data)->save();
            $record->permissions()->sync($permissionIds);

            return $record->load('permissions');
        }

        $record->fill($data)->save();

        return $this->reload($resource, $record);
    }

    private function reload(string $resource, Model $record): Model
    {
        $with = $this->config($resource)['with'];

        if ($with !== []) {
            $record->load($with);
        }

        return $record;
    }

    private function requestIncludesRole(Request $request, string $roleSlug): bool
    {
        return $this->roleIdsIncludeSlug((array) $request->input('role_ids', []), $roleSlug);
    }

    /**
     * @param  array<int, mixed>  $roleIds
     */
    private function roleIdsIncludeSlug(array $roleIds, string $roleSlug): bool
    {
        $ids = collect($roleIds)
            ->filter(fn (mixed $roleId) => is_numeric($roleId))
            ->map(fn (mixed $roleId) => (int) $roleId)
            ->values();

        if ($ids->isEmpty()) {
            return false;
        }

        return Role::query()
            ->whereIn('id', $ids)
            ->where('slug', $roleSlug)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: mixed, designation_id: mixed, job_title: mixed, mobile: mixed, telephone: mixed, extension: mixed, email: mixed, fax: mixed}
     */
    private function extractSalespersonContact(array &$data): array
    {
        return [
            'name' => Arr::pull($data, 'salesperson_contact_name'),
            'designation_id' => Arr::pull($data, 'salesperson_designation_id'),
            'job_title' => Arr::pull($data, 'salesperson_job_title'),
            'mobile' => Arr::pull($data, 'salesperson_mobile'),
            'telephone' => Arr::pull($data, 'salesperson_telephone'),
            'extension' => Arr::pull($data, 'salesperson_extension'),
            'email' => Arr::pull($data, 'salesperson_contact_email'),
            'fax' => Arr::pull($data, 'salesperson_fax'),
        ];
    }

    /**
     * @param  array<string, mixed>  $salespersonContact
     */
    private function hasSalespersonContactData(array $salespersonContact): bool
    {
        foreach ($salespersonContact as $value) {
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $salespersonContact
     */
    private function saveSalespersonContact(User $user, array $salespersonContact, string $fallbackName, string $fallbackEmail): Contact
    {
        $company = $this->defaultSupplierCompany();
        $contact = $user->contact ?: new Contact;

        $contact->fill([
            'company_id' => $company->id,
            'designation_id' => $salespersonContact['designation_id'] ?: null,
            'name' => $salespersonContact['name'] ?: $fallbackName,
            'job_title' => $salespersonContact['job_title'] ?: null,
            'mobile' => $salespersonContact['mobile'] ?: null,
            'telephone' => $salespersonContact['telephone'] ?: null,
            'extension' => $salespersonContact['extension'] ?: null,
            'email' => $salespersonContact['email'] ?: $fallbackEmail,
            'fax' => $salespersonContact['fax'] ?: null,
            'is_primary' => false,
            'status' => 'active',
        ])->save();

        Supplier::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'primary_contact_id' => $contact->id,
            ],
            ['status' => 'active']
        );

        return $contact;
    }

    private function defaultSupplierCompany(): Company
    {
        $company = Company::query()
            ->whereKey(1)
            ->where('company_type', 'internal')
            ->first()
            ?? Company::query()->where('company_code', 'ISC')->first()
            ?? Company::query()->where('company_type', 'internal')->orderBy('id')->first();

        if (! $company) {
            throw ValidationException::withMessages([
                'salesperson_contact_name' => 'Default internal supplier company is not configured.',
            ]);
        }

        return $company;
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(string $resource, Model $record): array
    {
        return match ($resource) {
            'countries' => [
                'id' => $record->id,
                'name' => $record->name,
                'country_code' => $record->country_code,
                'phone_code' => $record->phone_code,
                'status' => $record->status,
                'created_at' => $this->date($record),
                'updated_at' => $this->date($record, 'updated_at'),
            ],
            'designations' => [
                'id' => $record->id,
                'name' => $record->name,
                'code' => $record->code,
                'status' => $record->status,
                'created_at' => $this->date($record),
                'updated_at' => $this->date($record, 'updated_at'),
            ],
            'companies' => [
                'id' => $record->id,
                'country_id' => $record->country_id,
                'country_name' => $record->country?->name,
                'name' => $record->name,
                'company_code' => $record->company_code,
                'code_slug' => $record->code_slug,
                'postal_code' => $record->postal_code,
                'vendor_code' => $record->vendor_code,
                'location' => $record->location,
                'address' => $record->address,
                'email' => $record->email,
                'phone' => $record->phone,
                'vat_tin' => $record->vat_tin,
                'company_type' => $record->company_type,
                'status' => $record->status,
                'created_at' => $this->date($record),
                'updated_at' => $this->date($record, 'updated_at'),
            ],
            'contacts' => [
                'id' => $record->id,
                'company_id' => $record->company_id,
                'company_name' => $record->company?->name,
                'designation_id' => $record->designation_id,
                'designation_name' => $record->designation?->name,
                'name' => $record->name,
                'job_title' => $record->job_title,
                'mobile' => $record->mobile,
                'telephone' => $record->telephone,
                'extension' => $record->extension,
                'email' => $record->email,
                'fax' => $record->fax,
                'is_primary' => $record->is_primary,
                'status' => $record->status,
                'created_at' => $this->date($record),
                'updated_at' => $this->date($record, 'updated_at'),
            ],
            'incoterms' => [
                'id' => $record->id,
                'code' => $record->code,
                'name' => $record->name,
                'description' => $record->description,
                'reminder_days_before_delivery' => $record->reminder_days_before_delivery,
                'status' => $record->status,
                'created_at' => $this->date($record),
                'updated_at' => $this->date($record, 'updated_at'),
            ],
            'uoms' => [
                'id' => $record->id,
                'code' => $record->code,
                'name' => $record->name,
                'status' => $record->status,
                'created_at' => $this->date($record),
                'updated_at' => $this->date($record, 'updated_at'),
            ],
            'currencies' => [
                'id' => $record->id,
                'code' => $record->code,
                'name' => $record->name,
                'exchange_rate' => $record->exchange_rate,
                'status' => $record->status,
                'created_at' => $this->date($record),
                'updated_at' => $this->date($record, 'updated_at'),
            ],
            'manufacturers' => [
                'id' => $record->id,
                'country_id' => $record->country_id,
                'country_name' => $record->country?->name,
                'name' => $record->name,
                'status' => $record->status,
                'created_at' => $this->date($record),
                'updated_at' => $this->date($record, 'updated_at'),
            ],
            'suppliers' => [
                'id' => $record->id,
                'company_id' => $record->company_id,
                'company_name' => $record->company?->name,
                'company_code' => $record->company?->company_code,
                'country_name' => $record->company?->country?->name,
                'primary_contact_id' => $record->primary_contact_id,
                'primary_contact_name' => $record->primaryContact?->name,
                'manufacturer_id' => $record->manufacturer_id,
                'manufacturer_name' => $record->manufacturer?->name,
                'status' => $record->status,
                'created_at' => $this->date($record),
                'updated_at' => $this->date($record, 'updated_at'),
            ],
            'users' => [
                'id' => $record->id,
                'name' => $record->name,
                'email' => $record->email,
                'contact_id' => $record->contact_id,
                'contact_name' => $record->contact?->name,
                'supplier_company_id' => $record->contact?->company_id,
                'supplier_company_name' => $record->contact?->company?->name,
                'salesperson_contact_name' => $record->contact?->name,
                'salesperson_designation_id' => $record->contact?->designation_id,
                'salesperson_job_title' => $record->contact?->job_title,
                'salesperson_mobile' => $record->contact?->mobile,
                'salesperson_telephone' => $record->contact?->telephone,
                'salesperson_extension' => $record->contact?->extension,
                'salesperson_contact_email' => $record->contact?->email,
                'salesperson_fax' => $record->contact?->fax,
                'status' => $record->status ?? 'active',
                'role_ids' => $record->roles->pluck('id')->values()->all(),
                'role_names' => $record->roles->pluck('name')->values()->all(),
                'direct_permission_ids' => $record->permissions->pluck('id')->values()->all(),
                'direct_permission_names' => $record->permissions->pluck('name')->values()->all(),
                'created_at' => $this->date($record),
                'updated_at' => $this->date($record, 'updated_at'),
            ],
            'roles' => [
                'id' => $record->id,
                'name' => $record->name,
                'slug' => $record->slug,
                'description' => $record->description,
                'is_system' => $record->is_system,
                'status' => $record->status,
                'permission_ids' => $record->permissions->pluck('id')->values()->all(),
                'permission_names' => $record->permissions->pluck('name')->values()->all(),
                'created_at' => $this->date($record),
                'updated_at' => $this->date($record, 'updated_at'),
            ],
            default => abort(404),
        };
    }

    private function date(Model $record, string $column = 'created_at'): ?string
    {
        $value = $record->{$column};

        return $value ? $value->toDateTimeString() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsPayload(): array
    {
        return [
            'countries' => Country::query()->orderBy('name')->get(['id', 'name', 'country_code']),
            'designations' => Designation::query()->orderBy('name')->get(['id', 'name', 'code']),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'company_code', 'company_type']),
            'contacts' => Contact::query()->orderBy('name')->get(['id', 'name', 'company_id']),
            'manufacturers' => Manufacturer::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'country_id']),
            'uoms' => Uom::query()->orderBy('code')->get(['id', 'code', 'name', 'status']),
            'currencies' => Currency::query()->orderBy('code')->get(['id', 'code', 'name', 'exchange_rate', 'status']),
            'roles' => Role::query()->orderBy('name')->get(['id', 'name', 'slug']),
            'permissions' => Permission::query()->orderBy('group')->orderBy('name')->get(['id', 'name', 'group']),
        ];
    }
}
