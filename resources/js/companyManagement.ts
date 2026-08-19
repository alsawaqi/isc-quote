export interface SelectOption {
    id: number;
    name: string;
    country_code?: string | null;
    country_id?: number | null;
    country_name?: string | null;
    status?: 'active' | 'inactive';
}

export interface CompanyOptions {
    countries: SelectOption[];
    designations: SelectOption[];
    manufacturers: SelectOption[];
}

export interface CompanyRoles {
    is_internal: boolean;
    is_buyer: boolean;
    is_supplier: boolean;
    label?: string;
}

export interface CompanyCore {
    id?: number;
    country_id: number | null;
    country_name?: string | null;
    name: string;
    company_code: string;
    code_slug: string;
    postal_code: string;
    vendor_code: string;
    location: string;
    address: string;
    email: string;
    phone: string;
    vat_tin: string;
    status: 'active' | 'inactive';
}

export interface CompanyManufacturer {
    id: number;
    name: string;
    country_id?: number | null;
    country_name?: string | null;
    status?: 'active' | 'inactive';
}

export interface CompanyLocation {
    id?: number;
    client_key: string;
    location_type?: 'factory';
    name: string;
    location: string;
    address: string;
    country_id: number | null;
    country_name?: string | null;
    manufacturer_id: number | null;
    manufacturer_name?: string | null;
    status: 'active' | 'inactive';
}

export interface CompanyContact {
    id?: number;
    name: string;
    designation_id: number | null;
    designation_name?: string | null;
    job_title: string;
    mobile: string;
    telephone: string;
    extension: string;
    email: string;
    fax: string;
    status: 'active' | 'inactive';
    serves_buyer: boolean;
    serves_supplier: boolean;
    all_locations: boolean;
    is_primary_buyer: boolean;
    is_primary_supplier: boolean;
    location_keys: string[];
    location_ids?: number[];
}

export interface CompanyHistoryRecord {
    id: number;
    reference?: string | null;
    quotation_reference?: string | null;
    buyer_po_number?: string | null;
    supplier_po_number?: string | null;
    status?: string | null;
    created_at?: string | null;
    date?: string | null;
    total?: string | number | null;
    value?: string | number | null;
    currency?: string | null;
    role?: string | null;
    factory_id?: number | null;
    factory_name?: string | null;
    factory_location?: string | null;
}

export interface CompanyRoleProfile {
    id: number;
    primary_contact_id: number | null;
    primary_contact_name?: string | null;
    status: string;
}

export interface CompanyHistory {
    counts: {
        quotations: number;
        buyer_pos: number;
        supplier_pos: number;
        follow_up_items: number;
        invoices: number;
    };
    quotations: CompanyHistoryRecord[];
    buyer_pos: CompanyHistoryRecord[];
    supplier_pos: CompanyHistoryRecord[];
}

export interface CompanyProfile {
    company: CompanyCore & { id: number };
    roles: CompanyRoles;
    buyer_profile?: CompanyRoleProfile | null;
    supplier_profile?: CompanyRoleProfile | null;
    manufacturers: CompanyManufacturer[];
    locations: CompanyLocation[];
    contacts: CompanyContact[];
    history: CompanyHistory;
}

export interface CompanySummary {
    id: number;
    name: string;
    company_code: string;
    country_name: string | null;
    location: string | null;
    email: string | null;
    phone: string | null;
    status: string;
    roles: CompanyRoles;
    contacts_count: number;
    locations_count: number;
    manufacturers_count: number;
    manufacturer_names: string[];
}

export type CompanyKind = 'internal' | 'buyer' | 'supplier' | 'mixed';

export function rolesForKind(kind: CompanyKind): CompanyRoles {
    if (kind === 'internal') {
        return { is_internal: true, is_buyer: true, is_supplier: true };
    }

    if (kind === 'buyer') {
        return { is_internal: false, is_buyer: true, is_supplier: false };
    }

    if (kind === 'supplier') {
        return { is_internal: false, is_buyer: false, is_supplier: true };
    }

    return { is_internal: false, is_buyer: true, is_supplier: true };
}

export function kindForRoles(roles: CompanyRoles): CompanyKind | '' {
    if (roles.is_internal) {
        return 'internal';
    }

    if (roles.is_buyer && roles.is_supplier) {
        return 'mixed';
    }

    if (roles.is_supplier) {
        return 'supplier';
    }

    return roles.is_buyer ? 'buyer' : '';
}

export function roleLabels(roles: CompanyRoles): string[] {
    const labels: string[] = [];

    if (roles.is_internal) labels.push('Internal');
    if (roles.is_buyer) labels.push('Buyer');
    if (roles.is_supplier) labels.push('Supplier');

    return labels;
}

export function emptyCompany(): CompanyCore {
    return {
        country_id: null,
        name: '',
        company_code: '',
        code_slug: '',
        postal_code: '',
        vendor_code: '',
        location: '',
        address: '',
        email: '',
        phone: '',
        vat_tin: '',
        status: 'active',
    };
}

export function emptyHistory(): CompanyHistory {
    return {
        counts: { quotations: 0, buyer_pos: 0, supplier_pos: 0, follow_up_items: 0, invoices: 0 },
        quotations: [],
        buyer_pos: [],
        supplier_pos: [],
    };
}
