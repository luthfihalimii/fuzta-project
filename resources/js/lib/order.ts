export type BranchSummary = {
    id: number;
    code: string;
    name: string;
    address: string | null;
    phone: string | null;
    is_active: boolean;
};

export type BranchOption = {
    id: number;
    code: string;
    name: string;
};

export type OrderItemModifier = {
    modifier_name: string;
    price_delta: number;
};

export type OrderItem = {
    id: number;
    product_name: string;
    variant_name: string | null;
    quantity: number;
    unit_price: number;
    line_total: number;
    note: string | null;
    modifiers: OrderItemModifier[];
};

export type OrderPayment = {
    id: number;
    method: string;
    amount: number;
    cash_received: number | null;
    change_amount: number | null;
    status: string;
    paid_at: string | null;
    voided_at: string | null;
};

export type PosOrder = {
    id: number;
    order_number: string;
    branch_id: number;
    branch: BranchSummary | null;
    channel: string;
    table_number: string | null;
    customer_name: string | null;
    note: string | null;
    status: string;
    payment_status: string;
    subtotal: number;
    total: number;
    created_at: string | null;
    items: OrderItem[];
    payment: OrderPayment | null;
};

export type OrderRow = PosOrder & {
    can: {
        pay: boolean;
        cancel: boolean;
        void: boolean;
    };
};
