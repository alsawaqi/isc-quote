export type RoleSlug = 'admin' | 'salesperson' | 'follow-up';

export interface Role {
    id: number;
    name: string;
    slug: RoleSlug | string;
}

export interface Permission {
    id: number;
    name: string;
    slug: string;
    group: string;
}

export interface User {
    id: number;
    name: string;
    email: string;
    contact_id?: number | null;
    contact_name?: string | null;
    supplier_company_id?: number | null;
    supplier_company_name?: string | null;
    roles: Role[];
    permissions: Permission[];
    direct_permissions?: Permission[];
}

export interface NavItem {
    label: string;
    icon: string;
    path: string;
    roles: RoleSlug[];
    accent?: string;
    permissions?: string[];
}

export interface Metric {
    label: string;
    value: string;
    icon: string;
    change: string;
    note: string;
    tone: 'teal' | 'amber' | 'blue' | 'rose';
}

export interface JobRow {
    jobRef: string;
    buyer: string;
    supplier: string;
    stage: string;
    stageTone: 'blue' | 'teal' | 'amber' | 'slate';
    owner: string;
    ownerInitials: string;
    due: string;
    dueTone: 'danger' | 'warning' | 'neutral';
}

export interface AlertItem {
    jobRef: string;
    title: string;
    detail: string;
    dueLabel: string;
    dueValue: string;
    icon: string;
    tone: 'teal' | 'amber' | 'rose';
}

export interface WorkflowStage {
    label: string;
    value: string;
    icon: string;
    tone: 'teal' | 'amber' | 'blue' | 'slate';
    dashed?: boolean;
}
