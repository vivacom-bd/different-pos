import { Head, Link, router } from '@inertiajs/react';
import { Page, PageHeader } from '@/components/page-header';
import {
    ArrowLeft,
    Ban,
    Banknote,
    FileText,
    HandCoins,
    Layers,
    Package,
    Printer,
    Receipt,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Kbd } from '@/components/kbd';
import { KpiTile } from '@/components/kpi-tile';
import { PayDialog, type PaymentMethodOption } from '@/components/pay-dialog';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { useDigits } from '@/hooks/use-digits';
import { useHotkeys } from '@/hotkeys/use-hotkeys';
import { useT } from '@/i18n';
import { cn } from '@/lib/utils';

type Item = {
    id: number;
    product: string;
    variant_label: string | null;
    sku: string;
    qty: number;
    unit_cost: number;
    discount: number;
    line_total: number;
    sale_price: number | null;
};
type Batch = {
    id: number;
    product_id: number;
    product: string;
    sku: string;
    batch_no: string | null;
    unit_cost: number;
    qty_received: number;
    qty_remaining: number;
    received_at: string | null;
    expires_at: string | null;
};
type Payment = {
    id: number;
    number: string;
    paid_at: string | null;
    amount: number;
    reference: string | null;
};
type Purchase = {
    id: number;
    uuid: string;
    vendor_uuid: string | null;
    number: string;
    vendor_id: number | null;
    vendor: string | null;
    vendor_phone: string | null;
    vendor_balance: number;
    vendor_ref: string | null;
    branch: { name: string; code: string } | null;
    received_by: string | null;
    imported: boolean;
    status: string;
    payment_status: string;
    purchased_at: string;
    subtotal: number;
    discount: number;
    tax_total: number;
    shipping: number;
    total: number;
    paid: number;
    due: number;
    note: string | null;
};

const TH = 'px-2.5 py-2 text-[10px] font-semibold text-muted-foreground';
const SECTION = 'text-xs font-bold tracking-wider text-foreground uppercase';

/** The mockup "Purchase & GRN Details" voucher as a page: header, vendor card, KPI strip, line items, batches, payments, settlement. */
export default function PurchaseShow({
    purchase,
    items,
    batches,
    payments,
    paymentMethods,
    can,
}: {
    purchase: Purchase;
    items: Item[];
    batches: Batch[];
    payments: Payment[];
    paymentMethods: PaymentMethodOption[];
    can: { cancel: boolean; pay: boolean };
}) {
    const { t } = useT();
    const digits = useDigits();
    const [paying, setPaying] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const received = purchase.status === 'received';
    const canPay = received && can.pay && purchase.due > 0;
    const canCancel = received && can.cancel;
    const pieces = items.reduce((sum, i) => sum + i.qty, 0);
    const print = () => window.print();

    useHotkeys('page', [
        {
            id: 'purchase.print',
            keys: 'p',
            labelKey: 'purchases.print_grn',
            handler: print,
        },
        {
            id: 'purchase.pay',
            keys: 'shift+p',
            labelKey: 'vendors.pay',
            handler: () => {
                if (canPay) setPaying(true);
            },
        },
    ]);

    return (
        <>
            <Head title={purchase.number} />
            <Page className="max-w-6xl">
                <div className="flex flex-wrap items-start justify-between gap-3">
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
                            title={
                                <span className="inline-flex items-center gap-2">
                                    <FileText
                                        className="size-5 text-primary"
                                        aria-hidden="true"
                                    />
                                    {t('purchases.detail_title')}
                                    <span className="num font-mono text-primary">
                                        {purchase.number}
                                    </span>
                                </span>
                            }
                            badge={
                                <>
                                    <StatusBadge status={purchase.status} />
                                    {received ? (
                                        <StatusBadge
                                            status={purchase.payment_status}
                                        />
                                    ) : null}
                                </>
                            }
                            subtitle={
                                <>
                                    {t('purchases.vendor')}:{' '}
                                    {purchase.vendor ??
                                        t('purchases.no_vendor')}
                                    {' · '}
                                    <span className="num">
                                        {digits.n(purchase.purchased_at)}
                                    </span>
                                    {purchase.branch
                                        ? ` · ${purchase.branch.name} [${purchase.branch.code}]`
                                        : ''}
                                </>
                            }
                        />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canPay ? (
                            <Button
                                type="button"
                                className="min-h-11 md:min-h-9"
                                onClick={() => setPaying(true)}
                            >
                                <HandCoins aria-hidden="true" />{' '}
                                {t('vendors.pay')}
                                <Kbd keys="shift+p" onButton />
                            </Button>
                        ) : null}
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11 md:min-h-9"
                            onClick={print}
                        >
                            <Printer aria-hidden="true" />{' '}
                            {t('purchases.print_grn')}
                            <Kbd keys="p" onButton />
                        </Button>
                        {canCancel ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11 text-destructive md:min-h-9"
                                onClick={() => setCancelling(true)}
                            >
                                <Ban aria-hidden="true" />{' '}
                                {t('purchases.cancel')}
                            </Button>
                        ) : null}
                    </div>
                </div>

                {/* KPI strip */}
                <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                    <KpiTile
                        tone="primary"
                        icon={Receipt}
                        label={t('purchases.kpi_total_bill')}
                        value={digits.money(purchase.total)}
                        footnote={t('purchases.kpi_lines', {
                            n: digits.n(items.length),
                        })}
                    />
                    <KpiTile
                        tone="info"
                        icon={Package}
                        label={t('purchases.kpi_received_qty')}
                        value={t('purchases.kpi_items_value', {
                            qty: digits.qty(pieces),
                        })}
                        footnote={
                            purchase.branch
                                ? `${purchase.branch.name} [${purchase.branch.code}]`
                                : undefined
                        }
                    />
                    <KpiTile
                        tone="success"
                        icon={Banknote}
                        label={t('purchases.kpi_paid_amount')}
                        value={digits.money(purchase.paid)}
                        footnote={t('purchases.payment_allocations')}
                    />
                    <KpiTile
                        tone={purchase.due > 0 ? 'danger' : 'success'}
                        icon={HandCoins}
                        label={t('purchases.kpi_outstanding')}
                        value={
                            purchase.due > 0
                                ? digits.money(purchase.due)
                                : t('purchases.fully_paid')
                        }
                        footnote={`${t('purchases.vendor_balance')}: ${digits.money(purchase.vendor_balance)}`}
                    />
                </div>

                {/* Printable GRN voucher */}
                <article
                    className="print-area space-y-4 rounded-xl border bg-surface p-5 text-xs text-foreground shadow-xs"
                    aria-labelledby="grn-title"
                >
                    <div className="flex flex-wrap items-start justify-between gap-3 border-b pb-3">
                        <div className="flex items-start gap-3">
                            <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary print:hidden">
                                <Package
                                    className="size-5"
                                    aria-hidden="true"
                                />
                            </div>
                            <div>
                                <h2
                                    id="grn-title"
                                    className="mt-1 text-xs font-bold tracking-widest text-primary uppercase"
                                >
                                    {t('purchases.grn_label')}
                                </h2>
                                {purchase.received_by ? (
                                    <div className="text-[10px] text-muted-foreground">
                                        {t('purchases.stock_keeper')}:{' '}
                                        <strong>{purchase.received_by}</strong>
                                    </div>
                                ) : null}
                            </div>
                        </div>
                        <dl className="space-y-0.5 text-right text-[11px]">
                            <div>
                                <dt className="inline">
                                    {t('purchases.grn_no')}:{' '}
                                </dt>
                                <dd className="num inline font-mono font-bold text-primary">
                                    {purchase.number}
                                </dd>
                            </div>
                            <div>
                                <dt className="inline">
                                    {t('purchases.date')}:{' '}
                                </dt>
                                <dd className="num inline font-mono">
                                    {digits.n(purchase.purchased_at)}
                                </dd>
                            </div>
                            {purchase.branch ? (
                                <div>
                                    <dt className="inline">
                                        {t('purchases.receiving_branch')}:{' '}
                                    </dt>
                                    <dd className="inline font-bold">
                                        {purchase.branch.name} [
                                        {purchase.branch.code}]
                                    </dd>
                                </div>
                            ) : null}
                            <div>
                                <dt className="inline">
                                    {t('purchases.bill_ref')}:{' '}
                                </dt>
                                <dd className="num inline font-mono font-semibold">
                                    {purchase.vendor_ref ?? '—'}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    {/* Vendor card */}
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-surface-sunken p-2.5 text-[11px]">
                        <div>
                            <div className="text-[10px] font-semibold text-muted-foreground uppercase">
                                {t('purchases.supplier')}
                            </div>
                            {purchase.vendor_uuid ? (
                                <Link
                                    href={`/app/vendors/${purchase.vendor_uuid}`}
                                    className="text-sm font-bold text-foreground underline-offset-4 hover:underline"
                                    title={t('purchases.open_vendor')}
                                >
                                    {purchase.vendor}
                                </Link>
                            ) : (
                                <span className="text-sm font-bold text-muted-foreground">
                                    {t('purchases.no_vendor')}
                                </span>
                            )}
                            {purchase.vendor_phone ? (
                                <div className="num text-[10px] text-muted-foreground">
                                    {t('purchases.vendor_phone')}:{' '}
                                    {digits.n(purchase.vendor_phone)}
                                </div>
                            ) : null}
                        </div>
                        <div className="flex items-center gap-2">
                            <StatusBadge status={purchase.status} />
                            {received ? (
                                <StatusBadge status={purchase.payment_status} />
                            ) : null}
                        </div>
                    </div>

                    {/* Line items */}
                    <section aria-labelledby="pi-title">
                        <div className="mb-1.5 flex items-center justify-between text-xs font-bold text-foreground">
                            <h3 id="pi-title">
                                {t('purchases.received_lines')}
                            </h3>
                            <span className="text-[10px] font-normal text-muted-foreground">
                                {t('purchases.total_pcs', {
                                    qty: digits.qty(pieces),
                                })}
                            </span>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full border-collapse text-left text-xs">
                                <caption className="sr-only">
                                    {t('purchases.items')}
                                </caption>
                                <thead className="border-b bg-surface-sunken">
                                    <tr>
                                        <th scope="col" className={TH}>
                                            {t('purchases.col_hash')}
                                        </th>
                                        <th scope="col" className={TH}>
                                            {t('purchases.col_product_desc')}
                                        </th>
                                        <th scope="col" className={TH}>
                                            {t('purchases.col_variant')}
                                        </th>
                                        <th
                                            scope="col"
                                            className={cn(TH, 'text-right')}
                                        >
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
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {items.map((i, idx) => (
                                        <tr key={i.id}>
                                            <td className="num px-2.5 py-2 font-mono text-muted-foreground">
                                                {digits.n(idx + 1)}
                                            </td>
                                            <th
                                                scope="row"
                                                className="px-2.5 py-2 text-left font-semibold text-foreground"
                                            >
                                                <div>{i.product}</div>
                                                <div className="num font-mono text-[10px] font-normal text-muted-foreground">
                                                    {i.sku}
                                                </div>
                                            </th>
                                            <td className="px-2.5 py-2">
                                                {i.variant_label ? (
                                                    <span className="rounded-sm border bg-surface-sunken px-1.5 py-0.5 font-mono text-[10px]">
                                                        {i.variant_label}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                            </td>
                                            <td className="num px-2.5 py-2 text-right font-mono font-bold">
                                                {digits.qty(i.qty)}
                                            </td>
                                            <td className="num px-2.5 py-2 text-right text-muted-foreground">
                                                {digits.money(i.unit_cost)}
                                            </td>
                                            <td className="num px-2.5 py-2 text-right text-muted-foreground">
                                                {i.discount > 0
                                                    ? digits.money(i.discount)
                                                    : '—'}
                                            </td>
                                            <td className="num px-2.5 py-2 text-right font-bold">
                                                {digits.money(i.line_total)}
                                            </td>
                                            <td className="num px-2.5 py-2 text-right text-success">
                                                {i.sale_price === null
                                                    ? '—'
                                                    : digits.money(
                                                          i.sale_price,
                                                      )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    {/* Settlement */}
                    <div className="grid gap-4 border-t pt-3 text-[11px] sm:grid-cols-2">
                        <div className="space-y-1 text-[10px] text-muted-foreground">
                            {purchase.received_by ? (
                                <div>
                                    {t('purchases.stock_keeper')}:{' '}
                                    <strong className="text-foreground">
                                        {purchase.received_by}
                                    </strong>
                                </div>
                            ) : null}
                            <div>
                                {t('purchases.signature')}:
                                ___________________________
                            </div>
                            <p className="italic">
                                {t('purchases.inspection_note')}
                            </p>
                            {purchase.note ? (
                                <p className="pt-1 text-foreground">
                                    {t('purchases.note')}: {purchase.note}
                                </p>
                            ) : null}
                        </div>
                        <dl className="space-y-1 text-right font-mono">
                            <div className="flex justify-between text-muted-foreground">
                                <dt>{t('purchases.goods_value')}:</dt>
                                <dd className="num">
                                    {digits.money(purchase.subtotal)}
                                </dd>
                            </div>
                            <div className="flex justify-between text-muted-foreground">
                                <dt>{t('purchases.freight')}:</dt>
                                <dd className="num">
                                    {digits.money(purchase.shipping)}
                                </dd>
                            </div>
                            {purchase.discount > 0 ? (
                                <div className="flex justify-between text-muted-foreground">
                                    <dt>{t('purchases.order_discount')}:</dt>
                                    <dd className="num">
                                        −{digits.money(purchase.discount)}
                                    </dd>
                                </div>
                            ) : null}
                            {purchase.tax_total > 0 ? (
                                <div className="flex justify-between text-muted-foreground">
                                    <dt>{t('sales.vat')}:</dt>
                                    <dd className="num">
                                        {digits.money(purchase.tax_total)}
                                    </dd>
                                </div>
                            ) : null}
                            <div className="flex justify-between border-t pt-1 text-sm font-bold text-foreground">
                                <dt>{t('purchases.kpi_total_bill')}:</dt>
                                <dd className="num">
                                    {digits.money(purchase.total)}
                                </dd>
                            </div>
                            <div className="flex justify-between font-semibold text-success">
                                <dt>{t('purchases.kpi_paid_amount')}:</dt>
                                <dd className="num">
                                    {digits.money(purchase.paid)}
                                </dd>
                            </div>
                            <div
                                className={cn(
                                    'flex justify-between border-t pt-1 text-sm font-bold',
                                    purchase.due > 0
                                        ? 'text-destructive'
                                        : 'text-success',
                                )}
                            >
                                <dt>{t('purchases.kpi_outstanding')}:</dt>
                                <dd className="num">
                                    {digits.money(purchase.due)}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </article>

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    {/* Stock batches */}
                    <section
                        aria-labelledby="pb-title"
                        className="overflow-hidden rounded-xl border bg-surface shadow-xs"
                    >
                        <div className="flex items-center justify-between gap-2 border-b bg-surface-sunken p-2.5">
                            <h2
                                id="pb-title"
                                className={cn(
                                    SECTION,
                                    'flex items-center gap-1.5',
                                )}
                            >
                                <Layers
                                    className="size-3.5 text-primary"
                                    aria-hidden="true"
                                />
                                {t('purchases.batches')}
                            </h2>
                            <span className="text-[10px] text-muted-foreground">
                                {t('purchases.batches_hint')}
                            </span>
                        </div>
                        {batches.length === 0 ? (
                            <p className="p-4 text-xs text-muted-foreground">
                                {purchase.imported
                                    ? t('purchases.imported_batches')
                                    : purchase.status === 'cancelled'
                                      ? t('purchases.cancelled_batches')
                                      : t('purchases.no_batches')}
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <caption className="sr-only">
                                        {t('purchases.batches')}
                                    </caption>
                                    <thead className="border-b bg-surface-sunken">
                                        <tr>
                                            <th scope="col" className={TH}>
                                                {t('purchases.col_batch')}
                                            </th>
                                            <th scope="col" className={TH}>
                                                {t('products.product')}
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
                                                {t(
                                                    'purchases.col_received_remaining',
                                                )}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {batches.map((b) => (
                                            <tr key={b.id}>
                                                <td className="px-2.5 py-2">
                                                    <div className="num font-mono font-semibold text-primary">
                                                        {b.batch_no ??
                                                            `#${digits.n(b.id)}`}
                                                    </div>
                                                    <div className="num text-[10px] text-muted-foreground">
                                                        {digits.n(
                                                            b.received_at ?? '',
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-2.5 py-2">
                                                    <div className="font-medium">
                                                        {b.product}
                                                    </div>
                                                    <div className="num text-[10px] text-muted-foreground">
                                                        {b.sku}
                                                    </div>
                                                </td>
                                                <td className="num px-2.5 py-2 text-right">
                                                    {digits.money(b.unit_cost)}
                                                </td>
                                                <td className="num px-2.5 py-2 text-right">
                                                    <span className="font-bold">
                                                        {digits.qty(
                                                            b.qty_received,
                                                        )}
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        {' / '}
                                                        {digits.qty(
                                                            b.qty_remaining,
                                                        )}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    {/* Payments */}
                    <section
                        aria-labelledby="pp-title"
                        className="overflow-hidden rounded-xl border bg-surface shadow-xs"
                    >
                        <div className="flex items-center justify-between gap-2 border-b bg-surface-sunken p-2.5">
                            <h2
                                id="pp-title"
                                className={cn(
                                    SECTION,
                                    'flex items-center gap-1.5',
                                )}
                            >
                                <Banknote
                                    className="size-3.5 text-success"
                                    aria-hidden="true"
                                />
                                {t('purchases.payments')}
                            </h2>
                            <span className="text-[10px] text-muted-foreground">
                                {t('purchases.payment_allocations')}
                            </span>
                        </div>
                        {payments.length === 0 ? (
                            <p className="p-4 text-xs text-muted-foreground">
                                {t('purchases.no_payments')}
                            </p>
                        ) : (
                            <table className="w-full text-left text-xs">
                                <caption className="sr-only">
                                    {t('purchases.payments')}
                                </caption>
                                <thead className="border-b bg-surface-sunken">
                                    <tr>
                                        <th scope="col" className={TH}>
                                            {t('purchases.col_payment')}
                                        </th>
                                        <th scope="col" className={TH}>
                                            {t('purchases.col_date')}
                                        </th>
                                        <th scope="col" className={TH}>
                                            {t('purchases.col_reference')}
                                        </th>
                                        <th
                                            scope="col"
                                            className={cn(TH, 'text-right')}
                                        >
                                            {t('purchases.paid')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {payments.map((p) => (
                                        <tr key={p.id}>
                                            <td className="num px-2.5 py-2 font-mono font-semibold text-primary">
                                                {p.number}
                                            </td>
                                            <td className="num px-2.5 py-2">
                                                {digits.n(p.paid_at ?? '')}
                                            </td>
                                            <td className="num px-2.5 py-2 text-muted-foreground">
                                                {p.reference ?? '—'}
                                            </td>
                                            <td className="num px-2.5 py-2 text-right font-bold text-success">
                                                {digits.money(p.amount)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>
                </div>
            </Page>

            {can.pay ? (
                <PayDialog
                    open={paying}
                    onOpenChange={setPaying}
                    url={`/app/purchases/${purchase.uuid}/payments`}
                    due={purchase.due}
                    methods={paymentMethods}
                />
            ) : null}
            <ConfirmDialog
                open={cancelling}
                onOpenChange={setCancelling}
                title={t('purchases.cancel_title')}
                description={t('purchases.cancel_hint')}
                destructive
                confirmLabel={t('purchases.cancel')}
                onConfirm={() =>
                    router.post(`/app/purchases/${purchase.uuid}/cancel`)
                }
            />
        </>
    );
}
