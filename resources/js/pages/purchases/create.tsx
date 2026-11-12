import { Head, Link } from '@inertiajs/react';
import { Page, PageHeader } from '@/components/page-header';
import {
    ArrowLeft,
    CheckCircle2,
    Package,
    PackagePlus,
    PlusCircle,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { FormField } from '@/components/form-field';
import { actions } from '@/command/actions';
import { Kbd } from '@/components/kbd';
import { MoneyInput } from '@/components/money-input';
import { NativeSelect } from '@/components/native-select';
import type { PaymentMethodOption } from '@/components/pay-dialog';
import {
    NewItemDialog,
    type UnitOption,
} from '@/components/purchases/new-item-dialog';
import { QtyStepper } from '@/components/qty-stepper';
import { ScanField, type ScanProduct } from '@/components/scan-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    VendorPicker,
    type VendorOption,
} from '@/components/vendors/vendor-picker';
import { useDigits } from '@/hooks/use-digits';
import { useFormSubmit } from '@/hooks/use-form-submit';
import { useHotkeys } from '@/hotkeys/use-hotkeys';
import { useT } from '@/i18n';
import {
    addLine,
    lineNet,
    purchaseTotals,
    type Line,
} from '@/lib/purchase-lines';
import { cn } from '@/lib/utils';

const TH = 'px-3 py-1.5 text-[10px] font-semibold text-muted-foreground';
const SECTION_TITLE =
    'text-[11px] font-bold tracking-wider text-foreground uppercase';

/** The mockup "New Purchase & Goods Received Note (GRN)" form as a page: vendor bill header, scan-to-add lines, landed costs, settlement. */
export default function PurchaseCreate({
    vendors,
    paymentMethods,
    canPay,
    canCreateItem = false,
    units = [],
    today,
}: {
    vendors: VendorOption[];
    paymentMethods: PaymentMethodOption[];
    canPay: boolean;
    /** `products.create`: shows "New item" (and its shortcut). */
    canCreateItem?: boolean;
    units?: UnitOption[];
    today: string;
}) {
    const { t } = useT();
    const digits = useDigits();
    const { submit, processing, errors } = useFormSubmit();
    const scan = useRef<HTMLInputElement>(null);

    const preset =
        typeof window === 'undefined'
            ? null
            : new URLSearchParams(window.location.search).get('vendor');
    const [vendorId, setVendorId] = useState(
        preset && vendors.some((v) => String(v.id) === preset) ? preset : '',
    );
    const [vendorRef, setVendorRef] = useState('');
    const [date, setDate] = useState(today);
    const [note, setNote] = useState('');
    const [lines, setLines] = useState<Line[]>([]);
    /** The line adder's segmented filter: both kinds, raw materials only, or products only. */
    const [kind, setKind] = useState<'' | 'raw_material' | 'finished'>('');
    const [newItem, setNewItem] = useState<{ open: boolean; name: string }>({
        open: false,
        name: '',
    });
    /** Retail price per product (the mockup's "Retail MRP" column and margin chip). */
    const [retail, setRetail] = useState<Record<number, number>>({});
    const [discount, setDiscount] = useState<number | null>(null);
    const [shipping, setShipping] = useState<number | null>(null);
    const [paid, setPaid] = useState<number | null>(null);
    const [methodId, setMethodId] = useState(
        paymentMethods[0] ? String(paymentMethods[0].id) : '',
    );

    const totals = useMemo(
        () => purchaseTotals(lines, discount ?? 0, shipping ?? 0),
        [lines, discount, shipping],
    );
    const pieces = lines.reduce((sum, l) => sum + l.qty, 0);
    const balance = Math.max(totals.total - (paid ?? 0), 0);

    const onPick = (p: ScanProduct) => {
        if (p.item_type !== 'raw_material') {
            setRetail((prev) => ({ ...prev, [p.id]: p.sale_price }));
        }
        setLines((prev) =>
            addLine(prev, {
                product_id: p.id,
                name: p.variant_label
                    ? `${p.name} · ${p.variant_label}`
                    : p.name,
                sku: p.sku,
                unit_cost: p.purchase_cost ?? p.cost_price,
                item_type: p.item_type ?? 'finished',
                unit: p.unit ?? null,
            }),
        );
    };
    const setLine = (index: number, patch: Partial<Line>) =>
        setLines((prev) =>
            prev.map((l, i) => (i === index ? { ...l, ...patch } : l)),
        );

    const ready = !processing && lines.length > 0;
    const receive = () =>
        submit('post', '/app/purchases', {
            vendor_id: vendorId === '' ? null : Number(vendorId),
            vendor_ref: vendorRef || null,
            purchased_at: date,
            discount: discount ?? 0,
            shipping: shipping ?? 0,
            note: note || null,
            items: lines.map((l) => ({
                product_id: l.product_id,
                qty: l.qty,
                unit_cost: l.unit_cost,
                discount: l.discount,
            })),
            ...(vendorId !== '' && canPay && paid !== null && paid > 0
                ? {
                      payment: {
                          amount: paid,
                          payment_method_id:
                              methodId === '' ? null : Number(methodId),
                      },
                  }
                : {}),
        });

    useHotkeys('form', [
        {
            id: 'purchase.receive',
            keys: 'mod+enter',
            labelKey: 'purchases.receive',
            allowInInput: true,
            handler: (e) => {
                e.preventDefault();
                if (ready) receive();
            },
        },
        ...(canCreateItem
            ? [
                  {
                      id: 'purchase.new_item',
                      keys: 'f3',
                      labelKey: 'purchases.new_item',
                      allowInInput: true,
                      handler: (e: KeyboardEvent) => {
                          e.preventDefault();
                          setNewItem({ open: true, name: '' });
                      },
                  },
              ]
            : []),
        {
            id: 'purchase.scan',
            keys: 'f2',
            labelKey: 'scan.label',
            allowInInput: true,
            handler: (e) => {
                e.preventDefault();
                scan.current?.focus();
            },
        },
    ]);

    // Command palette entry while this page is open (hidden without products.create).
    useEffect(
        () =>
            canCreateItem
                ? actions.register({
                      id: 'purchase.new_item',
                      entity: null,
                      labelKey: 'purchases.new_item',
                      icon: PackagePlus,
                      permission: 'products.create',
                      run: () => setNewItem({ open: true, name: '' }),
                  })
                : () => undefined,
        [canCreateItem],
    );

    const err = (key: string) => errors[key] || undefined;
    const lineError = Object.entries(errors).find(([k]) =>
        k.startsWith('items'),
    )?.[1];
    const marginPct = (cost: number, price: number) =>
        Math.round(((price - cost) / Math.max(1, cost)) * 100);

    return (
        <>
            <Head title={t('purchases.new')} />
            <Page className="max-w-6xl">
                <div className="flex items-center gap-3">
                    <Button
                        asChild
                        variant="outline"
                        size="icon"
                        className="size-11 md:size-9"
                    >
                        <Link
                            href="/app/purchases"
                            aria-label={t('purchases.back')}
                        >
                            <ArrowLeft aria-hidden="true" />
                        </Link>
                    </Button>
                    <PageHeader
                        className="flex-1"
                        title={
                            <span className="inline-flex items-center gap-2">
                                <Package
                                    className="size-5 text-primary"
                                    aria-hidden="true"
                                />
                                {t('purchases.new_title')}
                            </span>
                        }
                        subtitle={t('purchases.new_subtitle')}
                    />
                </div>

                <form
                    className="flex flex-col gap-3 text-xs"
                    noValidate
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (ready) receive();
                    }}
                >
                    {/* Vendor bill header */}
                    <div className="grid grid-cols-1 gap-3 rounded-xl border bg-surface-sunken p-3 sm:grid-cols-4">
                        <FormField
                            label={t('purchases.vendor_required')}
                            error={err('vendor_id')}
                            hint={t('purchases.vendor_optional_hint')}
                        >
                            <VendorPicker
                                vendors={vendors}
                                value={vendorId}
                                onChange={setVendorId}
                            />
                        </FormField>
                        <FormField
                            label={t('purchases.bill_no')}
                            error={err('vendor_ref')}
                        >
                            <Input
                                className="num bg-surface font-semibold"
                                value={vendorRef}
                                placeholder={t('purchases.bill_no_ph')}
                                onChange={(e) => setVendorRef(e.target.value)}
                            />
                        </FormField>
                        <FormField
                            label={t('purchases.date')}
                            error={err('purchased_at')}
                        >
                            <Input
                                className="num bg-surface"
                                type="date"
                                value={date}
                                onChange={(e) => setDate(e.target.value)}
                            />
                        </FormField>
                        <FormField
                            label={t('purchases.note')}
                            error={err('note')}
                        >
                            <Input
                                className="bg-surface"
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                            />
                        </FormField>
                    </div>

                    {/* Line adder */}
                    <section
                        aria-labelledby="pa-title"
                        className="space-y-2 rounded-xl border-2 border-dashed bg-surface p-3.5"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2
                                id="pa-title"
                                className="flex items-center gap-1.5 text-xs font-bold text-foreground"
                            >
                                <PlusCircle
                                    className="size-3.5 text-primary"
                                    aria-hidden="true"
                                />
                                {t('purchases.add_items')}
                            </h2>
                            <span className="text-[10px] text-muted-foreground">
                                {t('purchases.add_items_hint')}{' '}
                                <Kbd keys="f2" className="ml-1" />
                            </span>
                        </div>
                        <fieldset className="m-0 flex min-w-0 flex-wrap items-center gap-1.5 border-0 p-0">
                            <legend className="sr-only">
                                {t('purchases.kind_label')}
                            </legend>
                            {(
                                [
                                    ['', 'purchases.kind_all'],
                                    ['raw_material', 'purchases.kind_raw'],
                                    ['finished', 'purchases.kind_products'],
                                ] as const
                            ).map(([value, key]) => (
                                <button
                                    key={value}
                                    type="button"
                                    aria-pressed={kind === value}
                                    onClick={() => setKind(value)}
                                    className={cn(
                                        'min-h-11 rounded-full border px-3 text-[11px] font-semibold outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:min-h-8',
                                        kind === value
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'bg-surface text-muted-foreground hover:bg-surface-sunken',
                                    )}
                                >
                                    {t(key)}
                                </button>
                            ))}
                            {canCreateItem ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="ml-auto min-h-11 md:min-h-8"
                                    onClick={() =>
                                        setNewItem({ open: true, name: '' })
                                    }
                                >
                                    <PackagePlus aria-hidden="true" />
                                    {t('purchases.new_item')}
                                    <Kbd keys="f3" className="ml-1" />
                                </Button>
                            ) : null}
                        </fieldset>
                        <ScanField
                            ref={scan}
                            lookupUrl={`/app/purchases/lookup${kind === '' ? '' : `?type=${kind}`}`}
                            onPick={onPick}
                            {...(canCreateItem
                                ? {
                                      onCreate: (typed: string) =>
                                          setNewItem({
                                              open: true,
                                              name: typed,
                                          }),
                                  }
                                : {})}
                        />
                        {lineError ? (
                            <p role="alert" className="text-destructive">
                                {lineError}
                            </p>
                        ) : null}
                    </section>

                    {/* GRN items */}
                    <section
                        aria-labelledby="pl-title"
                        className="overflow-hidden rounded-xl border bg-surface"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b bg-surface-sunken p-2.5 text-xs font-bold">
                            <h2 id="pl-title">
                                {t('purchases.grn_items', {
                                    items: digits.n(lines.length),
                                    qty: digits.qty(pieces),
                                })}
                            </h2>
                            <span className="text-[10px] font-normal text-muted-foreground">
                                {t('purchases.grn_items_hint')}
                            </span>
                        </div>
                        {lines.length === 0 ? (
                            <p className="p-6 text-center text-muted-foreground">
                                {t('purchases.scan_hint')}
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full border-collapse text-left text-xs">
                                    <caption className="sr-only">
                                        {t('purchases.items')}
                                    </caption>
                                    <thead className="border-b bg-surface-sunken">
                                        <tr>
                                            <th scope="col" className={TH}>
                                                {t(
                                                    'purchases.col_product_desc',
                                                )}
                                            </th>
                                            <th scope="col" className={TH}>
                                                {t('purchases.qty')}
                                            </th>
                                            <th
                                                scope="col"
                                                className={cn(TH, 'text-right')}
                                            >
                                                {t('purchases.unit_cost')}
                                            </th>
                                            <th
                                                scope="col"
                                                className={cn(TH, 'text-right')}
                                            >
                                                {t('purchases.line_discount')}
                                            </th>
                                            <th
                                                scope="col"
                                                className={cn(TH, 'text-right')}
                                            >
                                                {t('purchases.line_total')}
                                            </th>
                                            <th
                                                scope="col"
                                                className={cn(TH, 'text-right')}
                                            >
                                                {t('purchases.col_retail')}
                                            </th>
                                            <th
                                                scope="col"
                                                className={cn(
                                                    TH,
                                                    'text-center',
                                                )}
                                            >
                                                <span className="sr-only">
                                                    {t('common.actions')}
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {lines.map((l, i) => {
                                            const mrp = retail[l.product_id];

                                            return (
                                                <tr
                                                    key={l.product_id}
                                                    className="hover:bg-surface-sunken"
                                                >
                                                    <th
                                                        scope="row"
                                                        className="px-3 py-1.5 text-left font-semibold text-foreground"
                                                    >
                                                        <div>
                                                            {l.name}
                                                            {l.item_type ===
                                                            'raw_material' ? (
                                                                <span className="ml-1.5 rounded-sm bg-info-soft px-1.5 py-0.5 align-middle text-[10px] font-bold text-info">
                                                                    {t(
                                                                        'scan.type_raw',
                                                                    )}
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                        <div className="num text-[10px] font-normal text-muted-foreground">
                                                            {l.sku}
                                                        </div>
                                                    </th>
                                                    <td className="px-3 py-1.5">
                                                        <QtyStepper
                                                            value={l.qty}
                                                            min={1}
                                                            onChange={(v) =>
                                                                setLine(i, {
                                                                    qty: v,
                                                                })
                                                            }
                                                            aria-label={`${t('purchases.qty')} ${l.name}`}
                                                        />
                                                        {l.unit ? (
                                                            <span className="ml-1 text-[10px] text-muted-foreground">
                                                                {l.unit}
                                                            </span>
                                                        ) : null}
                                                    </td>
                                                    <td className="px-3 py-1.5">
                                                        <MoneyInput
                                                            className="w-32 font-bold"
                                                            value={l.unit_cost}
                                                            onChange={(v) =>
                                                                setLine(i, {
                                                                    unit_cost:
                                                                        v ?? 0,
                                                                })
                                                            }
                                                            aria-label={`${t('purchases.unit_cost')} ${l.name}`}
                                                        />
                                                    </td>
                                                    <td className="px-3 py-1.5">
                                                        <MoneyInput
                                                            className="w-28"
                                                            value={l.discount}
                                                            onChange={(v) =>
                                                                setLine(i, {
                                                                    discount:
                                                                        v ?? 0,
                                                                })
                                                            }
                                                            aria-label={`${t('purchases.line_discount')} ${l.name}`}
                                                        />
                                                    </td>
                                                    <td className="num px-3 py-1.5 text-right font-bold text-foreground">
                                                        {digits.money(
                                                            lineNet(l),
                                                        )}
                                                    </td>
                                                    <td className="num px-3 py-1.5 text-right text-success">
                                                        {mrp === undefined
                                                            ? l.item_type ===
                                                              'raw_material'
                                                                ? t(
                                                                      'purchases.not_sold',
                                                                  )
                                                                : '—'
                                                            : digits.money(mrp)}
                                                        {mrp !== undefined &&
                                                        l.unit_cost > 0 ? (
                                                            <span className="num ml-1.5 rounded-sm bg-success-soft px-1.5 py-0.5 text-[10px] font-bold">
                                                                {t(
                                                                    'purchases.margin',
                                                                    {
                                                                        pct: digits.n(
                                                                            marginPct(
                                                                                l.unit_cost,
                                                                                mrp,
                                                                            ),
                                                                        ),
                                                                    },
                                                                )}
                                                            </span>
                                                        ) : null}
                                                    </td>
                                                    <td className="px-2 py-1.5 text-center">
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-11 text-destructive hover:bg-danger-soft hover:text-destructive md:size-8"
                                                            aria-label={`${t('purchases.remove_line')} ${l.name}`}
                                                            onClick={() =>
                                                                setLines(
                                                                    (prev) =>
                                                                        prev.filter(
                                                                            (
                                                                                _,
                                                                                idx,
                                                                            ) =>
                                                                                idx !==
                                                                                i,
                                                                        ),
                                                                )
                                                            }
                                                        >
                                                            <X aria-hidden="true" />
                                                        </Button>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    {/* Landed costs, valuation, settlement */}
                    <div className="grid grid-cols-1 gap-3 rounded-xl border bg-surface-sunken p-3 sm:grid-cols-3">
                        <div className="space-y-2">
                            <h2 className={SECTION_TITLE}>
                                {t('purchases.landed_costs')}
                            </h2>
                            <FormField
                                label={t('purchases.freight')}
                                error={err('shipping')}
                            >
                                <MoneyInput
                                    className="bg-surface font-bold"
                                    value={shipping}
                                    onChange={setShipping}
                                />
                            </FormField>
                            <FormField
                                label={t('purchases.order_discount')}
                                error={err('discount')}
                            >
                                <MoneyInput
                                    className="bg-surface font-bold"
                                    value={discount}
                                    onChange={setDiscount}
                                />
                            </FormField>
                        </div>
                        <dl className="flex flex-col justify-between space-y-1 text-[11px] sm:border-x sm:px-3">
                            <div>
                                <h2 className={cn(SECTION_TITLE, 'mb-2')}>
                                    {t('purchases.bill_valuation')}
                                </h2>
                                <div className="flex justify-between py-0.5 text-muted-foreground">
                                    <dt>{t('purchases.goods_value')}</dt>
                                    <dd className="num font-bold text-foreground">
                                        {digits.money(totals.subtotal)}
                                    </dd>
                                </div>
                                <div className="flex justify-between py-0.5 text-muted-foreground">
                                    <dt>{t('purchases.freight')}</dt>
                                    <dd className="num">
                                        +{digits.money(shipping ?? 0)}
                                    </dd>
                                </div>
                                <div className="flex justify-between py-0.5 text-muted-foreground">
                                    <dt>{t('purchases.order_discount')}</dt>
                                    <dd className="num">
                                        −{digits.money(discount ?? 0)}
                                    </dd>
                                </div>
                            </div>
                            <div className="flex justify-between border-t pt-1 text-xs font-bold text-foreground">
                                <dt>{t('purchases.grand_total')}</dt>
                                <dd className="num text-sm text-primary">
                                    {digits.money(totals.total)}
                                </dd>
                            </div>
                        </dl>
                        <div className="flex flex-col justify-between space-y-2">
                            <div>
                                <h2 className={SECTION_TITLE}>
                                    {t('purchases.payment_settlement')}
                                </h2>
                                {vendorId === '' ? (
                                    <p className="mt-1.5 text-[11px] text-muted-foreground">
                                        {t('purchases.vendorless_note')}
                                    </p>
                                ) : canPay ? (
                                    <div className="mt-1.5 grid gap-2">
                                        <FormField
                                            label={t(
                                                'purchases.immediate_paid',
                                            )}
                                            error={err('payment.amount')}
                                            hint={t('purchases.pay_now_hint')}
                                        >
                                            <MoneyInput
                                                className="bg-surface font-bold text-success"
                                                value={paid}
                                                onChange={setPaid}
                                            />
                                        </FormField>
                                        {paid !== null && paid > 0 ? (
                                            <FormField
                                                label={t('vendors.pay_method')}
                                                error={err(
                                                    'payment.payment_method_id',
                                                )}
                                            >
                                                <NativeSelect
                                                    className="bg-surface"
                                                    value={methodId}
                                                    onChange={(e) =>
                                                        setMethodId(
                                                            e.target.value,
                                                        )
                                                    }
                                                >
                                                    {paymentMethods.map((m) => (
                                                        <option
                                                            key={m.id}
                                                            value={m.id}
                                                        >
                                                            {m.name}
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                            </FormField>
                                        ) : null}
                                    </div>
                                ) : (
                                    <p className="mt-1.5 text-[11px] text-muted-foreground">
                                        {t('purchases.pay_now_hint')}
                                    </p>
                                )}
                            </div>
                            <div className="flex items-center justify-between rounded-lg border bg-surface p-2">
                                <span className="text-[10px] font-semibold text-muted-foreground">
                                    {t('purchases.balance_due')}
                                </span>
                                <span
                                    className={cn(
                                        'num text-xs font-bold',
                                        balance > 0
                                            ? 'text-destructive'
                                            : 'text-success',
                                    )}
                                >
                                    {balance > 0 || lines.length === 0
                                        ? digits.money(balance)
                                        : t('purchases.fully_paid')}
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Footer */}
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-3">
                        <Button
                            asChild
                            type="button"
                            variant="outline"
                            className="min-h-11 md:min-h-9"
                        >
                            <Link href="/app/purchases">
                                {t('common.cancel')}
                            </Link>
                        </Button>
                        <div className="flex items-center gap-3">
                            {!processing && !ready ? (
                                <output className="text-[11px] font-semibold text-destructive">
                                    {t('purchases.blocked_lines')}
                                </output>
                            ) : (
                                <span className="hidden text-[11px] text-muted-foreground md:inline">
                                    {t('purchases.receive_hint_keys')}
                                </span>
                            )}
                            <Button
                                type="submit"
                                className="min-h-11 font-bold md:min-h-9"
                                disabled={!ready}
                            >
                                <CheckCircle2 aria-hidden="true" />{' '}
                                {t('purchases.receive')}
                                <Kbd keys="mod+enter" onButton />
                            </Button>
                        </div>
                    </div>
                </form>
            </Page>
            {canCreateItem && newItem.open ? (
                <NewItemDialog
                    open={newItem.open}
                    onOpenChange={(open) =>
                        setNewItem((prev) => ({ ...prev, open }))
                    }
                    units={units}
                    initialName={newItem.name}
                    onCreated={(item) => {
                        onPick(item);
                        scan.current?.focus();
                    }}
                />
            ) : null}
        </>
    );
}
