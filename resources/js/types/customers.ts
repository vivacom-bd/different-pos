import type { GeoChain } from "@/lib/geo";

export type CustomerRow = {
    id: string;
    uuid: string;
    customer_no: string | null;
    name: string;
    name_bn: string | null;
    shop_name: string | null;
    phone: string | null;
    group: string | null;
    /** Paisa; positive = the customer owes. */
    balance: number;
    /** Paisa; effective limit (own, else the tenant default); null = none. */
    credit_limit: number | null;
    area: string | null;
    address_line: string | null;
    post_code: string | null;
    /** Division → district → upazila → union names of the saved geo ids. */
    location: GeoChain;
    last_purchase_at: string | null;
    card_status: string | null;
    /** Security PIN state; the PIN itself never reaches the browser. */
    pin_status: "set" | "pending" | "locked";
    is_active: boolean;
};

/** Sums over the whole filtered list (not just the page). */
export type CustomerTotals = {
    count: number;
    balance: number;
    credit_limit: number;
};

/** Profile KPI tiles (server-side aggregates). Sales figures are null without `sales.view`. */
export type CustomerKpis = {
    lifetime_spend: number | null;
    visits: number | null;
    last_visit_at: string | null;
    returns: number | null;
    due: number;
    credit_limit: number | null;
    credit_used: number;
    credit_available: number | null;
};

export type CustomerDetail = {
    uuid: string;
    customer_no: string | null;
    name: string;
    name_bn: string | null;
    shop_name: string | null;
    phone: string | null;
    alt_phone: string | null;
    email: string | null;
    customer_group_id: number | null;
    group: string | null;
    /** Active group's discount in basis points (1000 = 10%). */
    group_discount_bp: number;
    credit_limit: number | null;
    balance: number;
    address_line: string | null;
    area: string | null;
    post_code: string | null;
    geo_division_id: number | null;
    geo_district_id: number | null;
    geo_upazila_id: number | null;
    geo_union_id: number | null;
    location: GeoChain;
    location_verified: boolean;
    dob: string | null;
    anniversary_date: string | null;
    notes: string | null;
    member_since: string | null;
    last_purchase_at: string | null;
    is_active: boolean;
    is_walk_in: boolean;
    pin_status: "set" | "pending" | "locked";
    pin_set_at: string | null;
    /** ISO time while locked, else null. */
    pin_locked_until: string | null;
};

export type CustomerCard = {
    id: number;
    card_code: string;
    status: string;
    issue_no: number;
    issued_at: string;
    print_count: number;
    member_since: string | null;
};

export type CardRow = {
    id: string;
    card_code: string;
    status: string;
    issue_no: number;
    issued_at: string;
    print_count: number;
    last_printed_at: string | null;
    customer_uuid: string | null;
    customer_no: string | null;
    name: string | null;
    shop_name: string | null;
};

export type TableMetaProp = {
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
};

export type GroupOption = { id: number; name: string; discount_bp: number };
