import type { Discount } from '@/engine/pricing';
import type { Paisa } from '@/engine/money';
import type { QtyMilli } from '@/engine/quantity';

/** Shapes of the online register's props and JSON (paisa and milli-units, integers throughout). */
export type PaymentMethodType =
    | 'cash'
    | 'card'
    | 'mobile_wallet'
    | 'bank'
    | 'customer_credit'
    | 'exchange_credit';

export type PaymentMethod = {
    id: number;
    name: string;
    type: PaymentMethodType;
    requires_reference: boolean;
};

export type RegisterSettings = {
    tax_inclusive: boolean;
    rounding_step: Paisa;
    backdate_days: number;
    /** Print the receipt (and show the done panel) after a sale; off = straight to the next sale. */
    auto_print: boolean;
    /** tenants.settings.require_customer_on_sale: no walk-in sales at the register. */
    require_customer: boolean;
    paper: string;
    allow_negative_stock: boolean;
    /** tenants.settings.customer_pin_policy. */
    pin_policy: 'off' | 'optional' | 'required';
};

export type CatalogVariant = {
    id: number;
    name: string;
    sku: string;
    barcode: string | null;
    label: string | null;
    option1: string | null;
    option2: string | null;
    price: Paisa;
    tax_rate_bp: number;
    /** null = stock not tracked. */
    stock: QtyMilli | null;
    allows_decimal: boolean;
};

export type CatalogItem = {
    key: string;
    kind: 'product' | 'family';
    name: string;
    name_bn: string | null;
    code: string;
    category_id: number | null;
    image_url: string | null;
    price: Paisa;
    stock: QtyMilli | null;
    variants: CatalogVariant[];
};

export type CustomerHit = {
    id: number;
    uuid: string;
    number: string | null;
    name: string;
    name_bn: string | null;
    shop_name: string | null;
    phone: string | null;
};

export type CustomerCard = CustomerHit & {
    group: { name: string; discount_bp: number } | null;
    /** What the customer owes now (negative = in credit). */
    balance: Paisa;
    /** Effective limit (own or tenant default); null = no limit. */
    credit_limit: Paisa | null;
    /** Credit the customer can spend on this sale. */
    advance: Paisa;
    is_active: boolean;
    /** PIN state only (set / pending / locked); the server never sends the PIN or its hash. */
    pin_status: 'set' | 'pending' | 'locked';
};

/** How the attached member was authenticated on this bill (02_AppFlow §4.4). */
export type MemberAuthState =
    | {
          kind: 'pin_online';
          customerId: number;
          /** Member token from verify-pin, bound to this cart; rides along with the sale. */
          token: string;
          /** Epoch ms. */
          expiresAt: number;
      }
    | {
          kind: 'override';
          customerId: number;
          managerName: string;
      }
    | {
          /** Optional policy: the member chose not to enter (or has no) PIN. */
          kind: 'skipped';
          customerId: number;
      };

export type CartLine = {
    /** Stable row key (the product id: one line per product). */
    key: string;
    productId: number;
    name: string;
    sku: string;
    label: string | null;
    unitPrice: Paisa;
    qty: QtyMilli;
    taxRateBp: number;
    allowsDecimal: boolean;
    stock: QtyMilli | null;
    discount: Discount;
};

export type Approval = {
    token: string;
    managerName: string;
    capBp: number;
    /** Epoch ms. */
    expiresAt: number;
};

export type CartState = {
    lines: CartLine[];
    /** Index of the highlighted bill line, -1 when none. */
    selected: number;
    orderDiscount: Discount;
    customer: CustomerCard | null;
    /** Idempotency key of this bill; a new one for every new sale. */
    clientUuid: string;
    approval: Approval | null;
    member: MemberAuthState | null;
};

export type CompletedSale = {
    id: number;
    uuid: string;
    number: string;
    total: Paisa;
    paid: Paisa;
    change: Paisa;
    due: Paisa;
    flags: string[];
    member_auth: string;
    sms_notice?: string | null;
    receipt_url: string | null;
};

export type TodaySummary = {
    count: number;
    total: Paisa;
    paid: Paisa;
    due: Paisa;
    methods: { name: string; amount: Paisa }[];
    recent: {
        id: number;
        uuid: string;
        number: string;
        total: Paisa;
        due: Paisa;
        sold_at: string;
    }[];
};
