import {
    Banknote,
    CreditCard,
    KeyRound,
    LayoutGrid,
    Minus,
    Percent,
    Plus,
    ShoppingCart,
    Tag,
    UserPlus,
    X,
    type LucideIcon,
} from 'lucide-react';
import { forwardRef, type ReactNode } from 'react';
import { Kbd } from '@/components/kbd';
import type { Paisa } from '@/engine/money';
import type { CartTotals, LineTotals } from '@/engine/pricing';
import { QTY_SCALE } from '@/engine/quantity';
import { useDigits } from '@/hooks/use-digits';
import { useT } from '@/i18n';
import { cn } from '@/lib/utils';
import { breakdownRows, stockTone } from '../present';
import type {
    CartLine,
    CatalogItem,
    CustomerCard,
    MemberAuthState,
} from '../types';

/**
 * The register's presentational pieces in the mockup's layout (render_pos_sales_returns.py, screen 1).
 * State and keys stay in pages/pos/register.tsx; these only draw and call back.
 */

type Named = { name: string; name_bn: string | null };

function useName() {
    const { locale } = useT();

    return (item: Named) =>
        locale === 'bn' && item.name_bn ? item.name_bn : item.name;
}

/** Category pill (h-7 rounded-full; primary when active). */
export function CategoryPill({
    active,
    onClick,
    children,
}: {
    active: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={cn(
                'touch-target h-7 shrink-0 rounded-full px-3 text-xs font-medium whitespace-nowrap transition-colors',
                active
                    ? 'bg-primary text-primary-foreground shadow-xs'
                    : 'bg-surface-sunken text-muted-foreground hover:text-foreground',
            )}
        >
            {children}
        </button>
    );
}

/** Catalogue tile: icon or image, SKU chip, stock badge, name; barcode or "Variants" and the price. */
export const CatalogTile = forwardRef<
    HTMLButtonElement,
    {
        item: CatalogItem;
        highlighted: boolean;
        tabIndex: number;
        onChoose: () => void;
        onFocus: () => void;
        onKeyDown: (e: React.KeyboardEvent<HTMLElement>) => void;
    }
>(function CatalogTile(
    { item, highlighted, tabIndex, onChoose, onFocus, onKeyDown },
    ref,
) {
    const { t } = useT();
    const d = useDigits();
    const name = useName();
    const tone = stockTone(item.stock);
    const family = item.kind === 'family' && item.variants.length > 1;
    const barcode = item.variants[0]?.barcode ?? null;

    return (
        <button
            ref={ref}
            type="button"
            aria-current={highlighted ? 'true' : undefined}
            tabIndex={tabIndex}
            className={cn(
                'group relative flex h-[122px] flex-col justify-between rounded-xl border bg-surface p-2.5 text-left transition-all select-none hover:border-primary hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                highlighted && 'border-primary bg-primary-soft',
            )}
            onClick={onChoose}
            onFocus={onFocus}
            onKeyDown={onKeyDown}
        >
            <span className="mb-1 flex items-center gap-2">
                <ProductThumb
                    url={item.image_url}
                    className="size-12 transition-colors group-hover:border-primary"
                />
                <span className="min-w-0 flex-1">
                    <span className="flex items-center justify-between gap-1">
                        <span className="max-w-[65px] truncate rounded bg-surface-sunken px-1 font-mono text-[10px] font-medium text-muted-foreground">
                            {item.code}
                        </span>
                        {tone && item.stock !== null && (
                            <span
                                className={cn(
                                    'num shrink-0 rounded px-1.5 text-[9px] font-bold',
                                    tone === 'danger' &&
                                        'bg-danger-soft text-danger',
                                    tone === 'warning' &&
                                        'bg-warning-soft text-warning',
                                    tone === 'success' &&
                                        'bg-success-soft text-success',
                                )}
                            >
                                {t('pos.stock.left', {
                                    qty: d.qty(Math.max(0, item.stock)),
                                })}
                            </span>
                        )}
                    </span>
                    <span className="mt-0.5 line-clamp-2 text-xs leading-tight font-semibold text-foreground group-hover:text-primary">
                        {name(item)}
                    </span>
                </span>
            </span>
            <span className="mt-1 flex items-center justify-between border-t pt-1.5">
                {family ? (
                    <span className="flex items-center gap-1 text-[10px] font-semibold text-primary">
                        <LayoutGrid className="size-2.5" aria-hidden="true" />
                        {t('pos.catalog.variants', {
                            count: item.variants.length,
                        })}
                    </span>
                ) : (
                    <span className="truncate font-mono text-[10px] text-muted-foreground">
                        {barcode ?? ''}
                    </span>
                )}
                <span className="num text-xs font-bold text-primary">
                    {d.money(item.price)}
                </span>
            </span>
        </button>
    );
});

/** Function bar button under the catalogue (Hold F8, Held list ⇧F8, …). */
export function FnButton({
    icon: Icon,
    label,
    keys,
    tone = 'default',
    disabled,
    title,
    iconOnly = false,
    onClick,
}: {
    icon: LucideIcon;
    label: string;
    keys?: string;
    tone?: 'default' | 'warning' | 'danger';
    disabled?: boolean;
    title?: string | undefined;
    /** Mockup trash button: the icon alone (the label stays as the accessible name and tooltip). */
    iconOnly?: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            disabled={disabled}
            title={title ?? (iconOnly ? label : undefined)}
            onClick={onClick}
            className={cn(
                'touch-target flex items-center gap-1 rounded px-1.5 py-1 text-left leading-tight font-medium disabled:opacity-40 max-md:min-w-0 max-md:flex-col max-md:justify-center max-md:gap-0.5 max-md:px-1 max-md:text-center',
                tone === 'default' && 'border bg-surface hover:border-primary',
                tone === 'warning' &&
                    'border border-warning/30 bg-warning-soft text-warning',
                tone === 'danger' &&
                    'bg-danger-soft text-danger hover:bg-danger hover:text-primary-foreground',
            )}
        >
            <Icon className="size-3 shrink-0" aria-hidden="true" />
            <span
                className={cn(
                    iconOnly && 'sr-only max-md:not-sr-only',
                    'max-md:break-words',
                )}
            >
                {label}
            </span>
            {keys &&
                (keys.startsWith('shift+') ? (
                    // Mockup keycap "⇧F8": the long "Shift+F8" would push the bar off screen.
                    <kbd
                        aria-hidden="true"
                        className="keycap inline-flex shrink-0 text-[9px] [@media(pointer:coarse)]:hidden"
                    >
                        ⇧{keys.slice(6).toUpperCase()}
                    </kbd>
                ) : (
                    <Kbd keys={keys} className="text-[9px]" />
                ))}
        </button>
    );
}

/**
 * The customer strip on top of the bill: amber "attach customer" while walk-in (the owner's request),
 * else avatar, name, number, phone, due / credit, group with its discount, Collect and detach.
 */
/** The member's PIN state on the strip (02_AppFlow §4.4: "✓ PIN", "Override by …", locked, not set). */
export function MemberPinChip({
    customer,
    member,
    policy,
}: {
    customer: CustomerCard;
    member: MemberAuthState | null;
    policy: 'off' | 'optional' | 'required';
}) {
    const { t } = useT();
    const own =
        member !== null && member.customerId === customer.id ? member : null;
    const tone =
        own?.kind === 'pin_online' || own?.kind === 'override'
            ? 'bg-success-soft text-success'
            : customer.pin_status === 'locked' ||
                (policy === 'required' && own === null)
              ? 'bg-danger-soft text-danger'
              : 'bg-warning-soft text-warning';
    const label =
        own?.kind === 'pin_online'
            ? t('pos.pin.chip_verified')
            : own?.kind === 'override'
              ? t('pos.pin.chip_override', { name: own.managerName })
              : customer.pin_status === 'locked'
                ? t('pos.pin.chip_locked')
                : customer.pin_status === 'pending'
                  ? t('pos.pin.chip_not_set')
                  : own?.kind === 'skipped'
                    ? t('pos.pin.chip_skipped')
                    : t('pos.pin.chip_unverified');

    return (
        <span
            className={`rounded px-1 font-bold ${tone}`}
            data-testid="member-pin-chip"
        >
            {label}
        </span>
    );
}

export function CustomerStrip({
    customer,
    member = null,
    pinPolicy = 'off',
    onPin,
    onOpen,
    onCollect,
    onDetach,
}: {
    customer: CustomerCard | null;
    member?: MemberAuthState | null;
    pinPolicy?: 'off' | 'optional' | 'required';
    /** Alt+P: verify / set the member's PIN. */
    onPin?: () => void;
    onOpen: () => void;
    onCollect: (() => void) | null;
    onDetach: () => void;
}) {
    const { t } = useT();
    const d = useDigits();
    const name = useName();

    if (!customer) {
        return (
            <div className="border-b bg-surface-sunken p-2.5">
                <button
                    type="button"
                    className="touch-target flex h-8 w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-warning bg-warning-soft text-xs font-semibold text-warning hover:border-solid"
                    onClick={onOpen}
                >
                    <UserPlus className="size-3.5" aria-hidden="true" />
                    {t('pos.customer.attach_member')}
                    <span className="font-normal opacity-80">
                        · {t('pos.customer.walk_in')}
                    </span>
                    <Kbd keys="f4" className="text-[9px]" />
                </button>
            </div>
        );
    }

    const initial = name(customer).trim().charAt(0).toUpperCase();
    const groupPct = customer.group ? customer.group.discount_bp / 100 : 0;

    return (
        <div className="flex items-center justify-between border-b bg-surface-sunken p-2.5">
            <button
                type="button"
                className="flex min-w-0 flex-1 items-center gap-2 text-left transition-opacity hover:opacity-80"
                onClick={onOpen}
                aria-label={t('pos.customer.change', { name: name(customer) })}
            >
                <span
                    className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-soft text-xs font-bold text-primary"
                    aria-hidden="true"
                >
                    {initial}
                </span>
                <span className="min-w-0 flex-1">
                    <span className="flex items-center gap-1.5 text-xs font-bold">
                        <span className="truncate">{name(customer)}</span>
                        {customer.number && (
                            <span className="rounded border bg-surface px-1 font-mono text-[10px]">
                                #{d.n(customer.number)}
                            </span>
                        )}
                    </span>
                    <span className="mt-0.5 flex items-center gap-1.5 truncate text-[10px] text-muted-foreground">
                        {customer.phone && <span>{d.n(customer.phone)}</span>}
                        {customer.shop_name && (
                            <>
                                <span aria-hidden="true">·</span>
                                <span className="truncate">
                                    {customer.shop_name}
                                </span>
                            </>
                        )}
                        {customer.balance > 0 && (
                            <>
                                <span aria-hidden="true">·</span>
                                <span className="num font-semibold text-danger">
                                    {t('pos.customer.due', {
                                        amount: d.money(customer.balance),
                                    })}
                                </span>
                            </>
                        )}
                        {customer.advance > 0 && (
                            <>
                                <span aria-hidden="true">·</span>
                                <span className="num font-semibold text-success">
                                    {t('pos.customer.credit', {
                                        amount: d.money(customer.advance),
                                    })}
                                </span>
                            </>
                        )}
                        {customer.group && (
                            <span className="rounded bg-primary-soft px-1 font-bold text-primary">
                                {customer.group.name}
                                {groupPct > 0 && ` −${d.n(groupPct)}%`}
                            </span>
                        )}
                        {pinPolicy !== 'off' && (
                            <MemberPinChip
                                customer={customer}
                                member={member}
                                policy={pinPolicy}
                            />
                        )}
                    </span>
                </span>
            </button>
            <span className="ml-2 flex shrink-0 items-center gap-1">
                {onPin && pinPolicy !== 'off' && (
                    <button
                        type="button"
                        onClick={onPin}
                        className="touch-target flex h-7 items-center gap-1 rounded border px-2 text-[10px] font-bold text-muted-foreground hover:bg-surface"
                        aria-label={t('pos.pin.action')}
                        title={`${t('pos.pin.action')} (Alt+P)`}
                    >
                        <KeyRound className="size-3" aria-hidden="true" />
                    </button>
                )}
                {onCollect && customer.balance > 0 && (
                    <button
                        type="button"
                        onClick={onCollect}
                        className="touch-target flex h-7 items-center gap-1 rounded border border-warning/30 bg-warning/10 px-2 text-[10px] font-bold text-warning hover:bg-warning/20"
                    >
                        <Banknote className="size-3" aria-hidden="true" />
                        {t('pos.customer.collect')}
                    </button>
                )}
                <Kbd keys="f4" className="text-[9px]" />
                <button
                    type="button"
                    onClick={onDetach}
                    className="touch-target rounded p-1 text-muted-foreground hover:bg-surface"
                    aria-label={t('pos.customer.detach')}
                    title={t('pos.customer.detach')}
                >
                    <X className="size-3.5" aria-hidden="true" />
                </button>
            </span>
        </div>
    );
}

/** Header of the bill ledger table (mockup "Active Billing Ledger"). */
export function BillHead() {
    const { t } = useT();
    const th =
        'sticky top-0 z-[1] bg-surface-sunken px-2 py-2 text-[11px] font-semibold text-muted-foreground';

    return (
        <thead className="max-md:sr-only">
            <tr className="border-b">
                <th scope="col" className={cn(th, 'w-8 pl-3 text-center')}>
                    {t('pos.bill.col_no')}
                </th>
                <th scope="col" className={cn(th, 'text-left')}>
                    {t('pos.bill.col_item')}
                </th>
                <th scope="col" className={cn(th, 'text-right')}>
                    {t('pos.bill.col_price')}
                </th>
                <th scope="col" className={cn(th, 'w-32 text-center')}>
                    {t('pos.bill.col_qty')}
                </th>
                <th
                    scope="col"
                    className={cn(th, 'text-right max-md:hidden')}
                >
                    {t('pos.bill.col_available')}
                </th>
                <th scope="col" className={cn(th, 'text-right')}>
                    {t('pos.bill.col_disc')}
                </th>
                <th scope="col" className={cn(th, 'text-right')}>
                    {t('pos.bill.col_vat')}
                </th>
                <th scope="col" className={cn(th, 'text-right')}>
                    {t('pos.bill.col_total')}
                </th>
                <th scope="col" className={cn(th, 'w-9 pr-3')}>
                    <span className="sr-only">{t('pos.keys.remove')}</span>
                </th>
            </tr>
        </thead>
    );
}

/** One bill line: no., item + SKU, unit price (struck through when discounted), − qty +, discount chip, VAT, line total, remove. */
export const BillLine = forwardRef<
    HTMLTableRowElement,
    {
        index: number;
        line: CartLine;
        priced: LineTotals | undefined;
        selected: boolean;
        onSelect: () => void;
        onQty: () => void;
        onStep: (delta: number) => void;
        onDiscount: () => void;
        onRemove: () => void;
    } & Omit<React.ComponentProps<'tr'>, 'onSelect'>
>(function BillLine(
    {
        index,
        line,
        priced,
        selected,
        onSelect,
        onQty,
        onStep,
        onDiscount,
        onRemove,
        ...rest
    },
    ref,
) {
    const { t } = useT();
    const d = useDigits();
    const discount = priced?.line_discount ?? 0;
    const lineTotal = (priced?.gross ?? 0) - discount;
    const qtyUnits = line.qty / QTY_SCALE;
    const unitAfter =
        discount > 0 && qtyUnits > 0
            ? Math.round(lineTotal / qtyUnits)
            : line.unitPrice;
    const chip =
        line.discount.type === 'percent_bp'
            ? `−${d.n(line.discount.value / 100)}%`
            : `−${d.money(discount)}`;
    const step = QTY_SCALE;
    const td = 'px-2 py-2.5 align-middle';

    return (
        <tr
            ref={ref}
            {...rest}
            tabIndex={0}
            aria-selected={selected}
            className={cn(
                'cursor-pointer transition-colors select-none hover:bg-surface-sunken focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring max-md:grid max-md:grid-cols-[auto_1fr_auto_auto] max-md:items-center max-md:gap-x-2 max-md:gap-y-1 max-md:px-3 max-md:py-2',
                selected && 'bg-primary-soft/60 hover:bg-primary-soft/60',
            )}
            onClick={onSelect}
            onFocus={onSelect}
            onDoubleClick={onQty}
            onKeyDown={(e) => {
                if (e.key === 'Enter' && e.target === e.currentTarget) {
                    e.preventDefault();
                    onQty();
                }
            }}
        >
            <td
                className={cn(
                    td,
                    'num pl-3 text-center font-mono text-[11px] text-muted-foreground max-md:col-start-1 max-md:row-start-1 max-md:p-0',
                )}
            >
                {d.n(index + 1)}
            </td>
            <td
                className={cn(
                    td,
                    'max-w-0 min-w-40 max-md:col-span-2 max-md:col-start-2 max-md:row-start-1 max-md:max-w-none max-md:min-w-0 max-md:p-0',
                )}
            >
                <div className="truncate text-xs leading-tight font-semibold text-foreground">
                    {line.name}
                </div>
                <div className="mt-0.5 flex items-center gap-1.5 truncate text-[10px] text-muted-foreground">
                    <span className="font-mono font-semibold text-primary">
                        {line.sku}
                    </span>
                    {line.label && (
                        <>
                            <span aria-hidden="true">·</span>
                            <span className="font-semibold text-foreground">
                                {line.label}
                            </span>
                        </>
                    )}
                </div>
            </td>
            <td
                className={cn(
                    td,
                    'text-right whitespace-nowrap max-md:col-span-2 max-md:col-start-1 max-md:row-start-2 max-md:p-0 max-md:text-left',
                )}
            >
                {discount > 0 ? (
                    <span className="flex flex-col items-end leading-tight max-md:items-start">
                        <span className="num text-[10px] text-muted-foreground line-through opacity-70">
                            {d.money(line.unitPrice)}
                        </span>
                        <span className="num text-xs font-bold text-foreground">
                            {d.money(unitAfter)}
                        </span>
                    </span>
                ) : (
                    <span className="num text-xs font-medium text-foreground">
                        {d.money(line.unitPrice)}
                    </span>
                )}
            </td>
            <td
                className={cn(
                    td,
                    'max-md:col-start-3 max-md:row-start-2 max-md:p-0',
                )}
            >
                <div className="flex items-center justify-center gap-1">
                    <button
                        type="button"
                        onClick={(e) => {
                            e.stopPropagation();
                            onStep(-step);
                        }}
                        className="touch-target flex size-6 items-center justify-center rounded border bg-surface-sunken text-foreground hover:bg-border"
                        aria-label={t('qty.decrease')}
                    >
                        <Minus className="size-3" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        onClick={(e) => {
                            e.stopPropagation();
                            onQty();
                        }}
                        className="num touch-target h-6 min-w-10 rounded border bg-surface px-1 text-center font-mono text-xs font-bold"
                        aria-label={t('pos.bill.qty_of', { name: line.name })}
                        title={t('pos.keys.qty')}
                    >
                        {d.qty(line.qty)}
                    </button>
                    <button
                        type="button"
                        onClick={(e) => {
                            e.stopPropagation();
                            onStep(step);
                        }}
                        className="touch-target flex size-6 items-center justify-center rounded border bg-surface-sunken text-foreground hover:bg-border"
                        aria-label={t('qty.increase')}
                    >
                        <Plus className="size-3" aria-hidden="true" />
                    </button>
                </div>
            </td>
            <td
                className={cn(
                    td,
                    'num text-right text-[11px] whitespace-nowrap max-md:hidden',
                    line.stock !== null && line.stock <= 0
                        ? 'font-semibold text-danger'
                        : 'text-muted-foreground',
                )}
                data-testid="bill-available"
            >
                {line.stock === null ? '–' : d.qty(line.stock)}
            </td>
            <td className={cn(td, 'text-right max-md:hidden')}>
                <button
                    type="button"
                    onClick={(e) => {
                        e.stopPropagation();
                        onDiscount();
                    }}
                    className={cn(
                        'touch-target inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[10px] font-bold transition-colors hover:border-primary',
                        discount > 0
                            ? 'border-danger/30 bg-danger-soft text-danger'
                            : 'bg-surface-sunken text-muted-foreground hover:text-primary',
                    )}
                    title={t('pos.keys.line_discount')}
                >
                    <Percent className="size-2.5" aria-hidden="true" />
                    {discount > 0 ? chip : t('pos.bill.disc')}
                </button>
            </td>
            <td
                className={cn(
                    td,
                    'text-right text-[11px] whitespace-nowrap text-muted-foreground max-md:hidden',
                )}
            >
                <span className="num block">{d.money(priced?.tax ?? 0)}</span>
                {line.taxRateBp > 0 && (
                    <span className="block text-[9px]">
                        {t('pos.bill.vat_rate', {
                            pct: d.n(line.taxRateBp / 100),
                        })}
                    </span>
                )}
            </td>
            <td
                className={cn(
                    td,
                    'text-right whitespace-nowrap max-md:col-start-4 max-md:row-start-2 max-md:p-0',
                )}
            >
                <span className="num text-xs font-bold text-foreground">
                    {d.money(lineTotal)}
                </span>
            </td>
            <td
                className={cn(
                    td,
                    'pr-3 text-center max-md:col-start-4 max-md:row-start-1 max-md:p-0',
                )}
            >
                <button
                    type="button"
                    onClick={(e) => {
                        e.stopPropagation();
                        onRemove();
                    }}
                    className="touch-target rounded p-1 text-muted-foreground hover:bg-danger-soft hover:text-danger"
                    aria-label={t('pos.bill.remove', { name: line.name })}
                    title={t('pos.keys.remove')}
                >
                    <X className="size-3.5" aria-hidden="true" />
                </button>
            </td>
        </tr>
    );
});

/** Product photo or the tag placeholder (search hits, browse tiles). */
export function ProductThumb({
    url,
    className,
}: {
    url: string | null;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'flex shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-surface-sunken text-primary',
                className,
            )}
        >
            {url ? (
                <img
                    src={url}
                    alt=""
                    loading="lazy"
                    className="size-full object-cover object-top"
                />
            ) : (
                <Tag className="size-4" aria-hidden="true" />
            )}
        </span>
    );
}

/** One row of the search dropdown under the scan field: photo, name, code, stock, price. */
export function SearchHit({
    item,
    active,
    id,
    onChoose,
    onHover,
}: {
    item: CatalogItem;
    active: boolean;
    id: string;
    onChoose: () => void;
    onHover: () => void;
}) {
    const { t } = useT();
    const d = useDigits();
    const name = useName();
    const tone = stockTone(item.stock);
    const family = item.kind === 'family' && item.variants.length > 1;

    return (
        <li id={id}>
            <button
                type="button"
                tabIndex={-1}
                aria-current={active ? 'true' : undefined}
                className={cn(
                    'flex w-full items-center gap-2.5 px-2.5 py-1.5 text-left',
                    active ? 'bg-primary-soft' : 'hover:bg-surface-sunken',
                )}
                // Keep the focus in the scan field: the click must not blur it first.
                onMouseDown={(e) => e.preventDefault()}
                onClick={onChoose}
                onMouseMove={onHover}
            >
                <ProductThumb url={item.image_url} className="size-10" />
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-xs font-semibold text-foreground">
                        {name(item)}
                    </span>
                    <span className="flex items-center gap-1.5 text-[10px] text-muted-foreground">
                        <span className="truncate font-mono">{item.code}</span>
                        {family && (
                            <span className="flex items-center gap-0.5 font-semibold text-primary">
                                <LayoutGrid
                                    className="size-2.5"
                                    aria-hidden="true"
                                />
                                {t('pos.catalog.variants', {
                                    count: item.variants.length,
                                })}
                            </span>
                        )}
                    </span>
                </span>
                {tone && item.stock !== null && (
                    <span
                        className={cn(
                            'num shrink-0 rounded px-1.5 text-[9px] font-bold',
                            tone === 'danger' && 'bg-danger-soft text-danger',
                            tone === 'warning' &&
                                'bg-warning-soft text-warning',
                            tone === 'success' &&
                                'bg-success-soft text-success',
                        )}
                    >
                        {t('pos.stock.left', {
                            qty: d.qty(Math.max(0, item.stock)),
                        })}
                    </span>
                )}
                <span className="num w-20 shrink-0 text-right text-xs font-bold text-primary">
                    {d.money(item.price)}
                </span>
            </button>
        </li>
    );
}

/** Empty bill in the main area: cart icon, "Cart is empty", how to add, Browse catalogue (Alt+G). */
export function EmptyBill({ onBrowse }: { onBrowse: () => void }) {
    const { t } = useT();

    return (
        <div className="flex h-full flex-col items-center justify-center p-6 text-center text-muted-foreground">
            <span className="mb-3 flex size-14 items-center justify-center rounded-full bg-surface-sunken">
                <ShoppingCart className="size-7" aria-hidden="true" />
            </span>
            <p className="text-sm font-semibold text-foreground">
                {t('pos.bill.empty_title')}
            </p>
            <p className="mt-1 max-w-xs text-xs">{t('pos.bill.empty_hint')}</p>
            <button
                type="button"
                onClick={onBrowse}
                className="touch-target mt-4 flex h-9 items-center gap-1.5 rounded-lg border bg-surface px-3 text-xs font-semibold text-foreground hover:border-primary"
            >
                <LayoutGrid className="size-3.5" aria-hidden="true" />
                {t('pos.catalog.browse')}
                <Kbd keys="alt+g" className="text-[10px]" />
            </button>
        </div>
    );
}

/**
 * The payment breakdown under the bill (engine totals): subtotal, line / group discounts, the order
 * discount box with add / remove, VAT, rounding and the big total payable.
 */
export function Breakdown({
    totals,
    pcs,
    taxInclusive,
    groupName,
    orderDiscountLabel,
    canDiscount,
    onOrderDiscount,
    onRemoveOrderDiscount,
}: {
    totals: CartTotals;
    pcs: string;
    taxInclusive: boolean;
    groupName: string | null;
    orderDiscountLabel: string | null;
    canDiscount: boolean;
    onOrderDiscount: () => void;
    onRemoveOrderDiscount: () => void;
}) {
    const { t } = useT();
    const d = useDigits();
    const money = (amount: Paisa, negative?: boolean) =>
        negative ? `−${d.money(amount)}` : d.money(amount);

    return (
        <div
            className="shrink-0 space-y-1.5 border-t bg-surface-sunken p-3 text-xs"
            aria-live="polite"
        >
            {breakdownRows(totals).map((row) => {
                switch (row.id) {
                    case 'subtotal':
                        return (
                            <div
                                key={row.id}
                                className="flex justify-between text-muted-foreground"
                            >
                                <span>
                                    {t('pos.totals.subtotal', { count: pcs })}
                                </span>
                                <span className="num">{money(row.amount)}</span>
                            </div>
                        );
                    case 'line_discount':
                        return (
                            <div
                                key={row.id}
                                className={cn(
                                    'flex justify-between',
                                    row.amount > 0
                                        ? 'text-danger'
                                        : 'text-muted-foreground',
                                )}
                            >
                                <span>{t('pos.totals.line_discounts')}</span>
                                <span className="num">
                                    {money(row.amount, row.amount > 0)}
                                </span>
                            </div>
                        );
                    case 'group_discount':
                        return (
                            <div
                                key={row.id}
                                className="flex justify-between text-primary"
                            >
                                <span>
                                    {t('pos.totals.group_discount', {
                                        group: groupName ?? '',
                                    })}
                                </span>
                                <span className="num">
                                    {money(row.amount, true)}
                                </span>
                            </div>
                        );
                    case 'order_discount':
                        return (
                            <div
                                key={row.id}
                                className="flex items-center justify-between rounded-lg border border-dashed bg-surface px-2 py-1"
                            >
                                <span className="flex items-center gap-1.5 text-xs font-semibold text-foreground">
                                    <Tag
                                        className="size-3 text-primary"
                                        aria-hidden="true"
                                    />
                                    {t('pos.totals.order_discount')}
                                </span>
                                {row.amount > 0 ? (
                                    <span className="flex items-center gap-1.5">
                                        <span className="num font-bold text-danger">
                                            {money(row.amount, true)}
                                            {orderDiscountLabel &&
                                                ` (${orderDiscountLabel})`}
                                        </span>
                                        <button
                                            type="button"
                                            className="touch-target text-[10px] font-bold text-danger hover:underline"
                                            onClick={onRemoveOrderDiscount}
                                        >
                                            × {t('pos.totals.remove')}
                                        </button>
                                    </span>
                                ) : (
                                    <button
                                        type="button"
                                        disabled={!canDiscount}
                                        className="touch-target flex h-6 items-center gap-1 rounded bg-primary-soft px-2.5 text-[11px] font-bold text-primary transition-colors hover:bg-primary hover:text-primary-foreground disabled:opacity-40"
                                        onClick={onOrderDiscount}
                                    >
                                        <Percent
                                            className="size-3"
                                            aria-hidden="true"
                                        />
                                        {t('pos.totals.add_order_discount')}
                                        <Kbd
                                            keys="shift+f6"
                                            className="border-transparent bg-transparent p-0 text-[10px] text-primary"
                                        />
                                    </button>
                                )}
                            </div>
                        );
                    case 'vat':
                        return (
                            <div
                                key={row.id}
                                className="flex justify-between text-muted-foreground"
                            >
                                <span>
                                    {taxInclusive
                                        ? t('pos.totals.vat_included')
                                        : t('pos.totals.vat_after')}
                                </span>
                                <span className="num">{money(row.amount)}</span>
                            </div>
                        );
                    case 'rounding':
                        return (
                            <div
                                key={row.id}
                                className="flex justify-between text-muted-foreground"
                            >
                                <span>{t('pos.totals.rounding')}</span>
                                <span className="num">{money(row.amount)}</span>
                            </div>
                        );
                    case 'total':
                        return (
                            <div
                                key={row.id}
                                className="flex items-baseline justify-between border-t pt-2"
                            >
                                <span className="text-sm font-bold text-foreground">
                                    {t('pos.totals.payable')}
                                </span>
                                <span className="num text-2xl font-extrabold tracking-tight text-primary xl:text-3xl">
                                    {money(row.amount)}
                                </span>
                            </div>
                        );
                }

                return null;
            })}
        </div>
    );
}

/** The big PAY F9 bar. */
export function PayBar({
    total,
    pcs,
    disabled,
    onPay,
}: {
    total: Paisa;
    pcs: string;
    disabled: boolean;
    onPay: () => void;
}) {
    const { t } = useT();
    const d = useDigits();

    return (
        <div className="shrink-0 border-t bg-surface p-2.5">
            <button
                type="button"
                disabled={disabled}
                onClick={onPay}
                aria-label={t('pos.actions.pay', { amount: d.money(total) })}
                className="flex h-14 w-full items-center justify-between rounded-xl bg-primary px-5 text-sm font-bold text-primary-foreground shadow-md transition-colors hover:bg-primary-hover disabled:opacity-40"
            >
                <span className="flex items-center gap-2.5">
                    <span className="flex size-8 items-center justify-center rounded-lg bg-primary-foreground/20">
                        <CreditCard
                            className="size-[18px]"
                            aria-hidden="true"
                        />
                    </span>
                    <span className="text-left">
                        <span className="flex items-center gap-2">
                            <span className="text-base tracking-wide">
                                {t('pos.paybar.pay')}
                            </span>
                            <Kbd keys="f9" tone="on-primary" />
                        </span>
                        <span className="block text-[11px] font-normal opacity-90">
                            {t('pos.paybar.hint')}
                        </span>
                    </span>
                </span>
                <span className="text-right">
                    <span className="num block text-base font-bold">
                        {d.money(total)}
                    </span>
                    <span className="block text-[10px] opacity-80">
                        {t('pos.paybar.items', { count: pcs })}
                    </span>
                </span>
            </button>
        </div>
    );
}
