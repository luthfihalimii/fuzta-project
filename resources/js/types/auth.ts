export type Role = 'owner' | 'cashier' | 'kitchen';

export type User = {
    id: number;
    name: string;
    email: string;
    role: Role;
    branch_id: number | null;
    is_active: boolean;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User | null;
};
