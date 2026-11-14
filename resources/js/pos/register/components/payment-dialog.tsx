import {
    AlertTriangle,
    Banknote,
    CreditCard,
    Landmark,
    ShieldCheck,
    Smartphone,
    UserPlus,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Kbd } from '@/components/kbd';
import { MoneyInput } from '@/components/money-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Paisa } from '@/engine/money';
import { useDigits } from '@/hooks/use-digits';
import { useT } from '@/i18n';
import { cn } from '@/lib/utils';
import type { PosError } from '../api';
import {
    addCash,
    creditMeter,
    exactCash,
    initialTenders,
    leaveDue,
    payKey,
    pickTender,
    tenderOptions,
    type TenderIcon,
} from '../present';
import { OVERRIDABLE, settle, type TenderRow } from '../tenders';
import type { Approval, CustomerCard, PaymentMethod } from '../types';
import { PosDialog } from './pos-dialog';

/** Server error code → the manager permission that can lift it. */
export const APPROVABLE: Record<string, string> = {
    discount_cap_exceeded: 'pos.discount_override',
    below_cost_sale: 'pos.sell_below_cost',
    price_override_not_allowed: 'pos.price_override',
    credit_limit_exceeded: 'pos.customer_pin_override',
    backdate_not_allowed: 'pos.backdate',
};

const ICONS: Record<TenderIcon, LucideIcon> = {
    cash: Banknote,
    card: CreditCard,
    wallet: Smartphone,
    bank: Landmark,
    credit: Wallet,
};

/** Quick cash steps (mockup: +100 / +500 / +1000), keys 1–3. */
const CASH_STEPS: readonly Paisa[] = [10_000, 50_000, 100_000];

/**
 * F9, the mockup's "Payment & Due Settlement" panel: one row per payment method (cash prefilled
 * with the exact amount), quick cash, and the settlement on the right (tendered, change or due with
 * the credit-limit meter). Alt+C/K/B/N/R/A pick a method, Alt+E exact cash, Alt+D rest as due,
 * 1–3 add cash, Enter or F10 completes.
 */
type Props = {
    open: boolean;
    total: Paisa;
    methods: readonly PaymentMethod[];
    customer: CustomerCard | null;
    approval: Approval | null;
    /** The register prints the receipt on completion (labels the button "Complete Sale & Print Receipt"). */
    autoPrint?: boolean;
    /** Courier names offered for this sale (Settings > Couriers); empty hides the picker. */
    couriers?: readonly string[];
    onSubmit: (
        tenders: TenderRow[],
        courier: string | null,
    ) => Promise<PosError | null>;
    onNeedApproval: (permission: string) => void;
    onAttachCustomer: () => void;
    onClose: () => void;
};

export function PaymentDialog({ open, onClose, ...rest }: Props) {
    const { t } = useT();
    const [busy, setBusy] = useState(false);

    return (
        <PosDialog
            open={open}
            onClose={() => !busy && onClose()}
            title={t('pos.pay.title')}
            description={t('pos.pay.hint')}
            icon={CreditCard}
            className="sm:max-w-2xl"
        >
            <PaymentBody onBusy={setBusy} {...rest} />
        </PosDialog>
    );
}

function PaymentBody({
    total,
    methods,
    customer,
    approval,
    autoPrint = false,
    couriers = [],
    onSubmit,
    onNeedApproval,
    onAttachCustomer,
    onBusy,
}: Omit<Props, 'open' | 'onClose'> & { onBusy: (busy: boolean) => void }) {
    const { t } = useT();
    const d = useDigits();
    const options = useMemo(
        () => tenderOptions(methods, customer),
        [methods, customer],
    );
    const cashId = options.find((o) => o.method.type === 'cash')?.method.id;
    const [rows, setRows] = useState<TenderRow[]>(() =>
        initialTenders(options, total),
    );
    const [busy, setBusyState] = useState(false);
    const [error, setError] = useState<PosError | null>(null);
    const [courier, setCourier] = useState('');
    const formRef = useRef<HTMLFormElement>(null);
    const setBusy = (value: boolean) => {
        setBusyState(value);
        onBusy(value);
    };

    const s = settle(total, rows, methods, customer);
    const approved = approval !== null;
    const blocking = s.problems.filter(
        (p) => !(OVERRIDABLE.includes(p) && approved),
    );
    const meter = creditMeter(s.balanceAfter, customer?.credit_limit ?? null);
    const amountOf = (id: number) =>
        rows.find((r) => r.methodId === id)?.amount ?? 0;
    const setRow = (id: number, patch: Partial<TenderRow>) =>
        setRows((prev) =>
            prev.map((r) => (r.methodId === id ? { ...r, ...patch } : r)),
        );
    /**
     * After a key changed the amounts: refocus (a field being edited shows its own text until it is
     * focused again), on the given row or wherever the focus was.
     */
    const refocus = (id?: number) =>
        requestAnimationFrame(() => {
            const current = document.activeElement;
            const target =
                id === undefined
                    ? current
                    : document.getElementById(`pos-tender-${id}`);
            if (current instanceof HTMLElement) {
                current.blur();
            }
            if (target instanceof HTMLElement) {
                target.focus();
            }
        });
    const pick = (id: number) => {
        setRows((prev) => pickTender(prev, id, total, methods, customer));
        refocus(id);
    };

    const submit = async () => {
        if (busy || blocking.length > 0) {
            return;
        }
        setBusy(true);
        setError(null);
        const result = await onSubmit(
            rows.filter((r) => r.amount > 0),
            courier === '' ? null : courier,
        );
        setBusy(false);
        setError(result);
    };

    // Native listener on the panel (it is not a widget): F10, Alt+keys, quick cash 1–3.
    const keys = useRef<(e: KeyboardEvent) => void>(() => undefined);
    useEffect(() => {
        keys.current = (e: KeyboardEvent) => {
            const action = payKey(
                {
                    key: e.key,
                    altKey: e.altKey,
                    ctrlKey: e.ctrlKey,
                    metaKey: e.metaKey,
                    shiftKey: e.shiftKey,
                    typing: e.target instanceof HTMLInputElement,
                },
                options,
                cashId === undefined ? 0 : CASH_STEPS.length,
            );
            if (!action) {
                return;
            }
            e.preventDefault();
            switch (action.type) {
                case 'submit':
                    void submit();
                    break;
                case 'due':
                    setRows((prev) => leaveDue(prev, cashId));
                    refocus();
                    break;
                case 'exact':
                    setRows((prev) => exactCash(prev, total, cashId));
                    refocus();
                    break;
                case 'pick':
                    pick(action.methodId);
                    break;
                case 'quick':
                    setRows((prev) =>
                        addCash(prev, cashId, CASH_STEPS[action.index] ?? 0),
                    );
                    refocus();
                    break;
            }
        };
    });
    useEffect(() => {
        // The whole panel (header close button included), not only the form.
        const panel =
            formRef.current?.closest<HTMLElement>('[role="dialog"]') ??
            formRef.current;
        const listener = (e: KeyboardEvent) => keys.current(e);
        panel?.addEventListener('keydown', listener);

        return () => panel?.removeEventListener('keydown', listener);
    }, []);

    return (
        <form
            ref={formRef}
            className="grid grid-cols-1 gap-4 text-xs md:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                void submit();
            }}
        >
            {/* Left: tender breakdown */}
            <fieldset className="space-y-2">
                <legend className="mb-1 text-xs font-bold text-muted-foreground">
                    {t('pos.pay.breakdown')}
                </legend>
                {options.map(({ method, icon, key }) => {
                    const Icon = ICONS[icon];
                    const amount = amountOf(method.id);
                    const row = rows.find((r) => r.methodId === method.id);

                    return (
                        <div key={method.id} className="space-y-1">
                            <div className="flex items-center justify-between gap-2">
                                <label
                                    htmlFor={`pos-tender-${method.id}`}
                                    className="flex w-36 shrink-0 items-center gap-1.5 truncate text-[11px] font-medium text-foreground"
                                >
                                    <Icon
                                        className="size-3.5 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <span className="truncate">
                                        {method.name}
                                    </span>
                                    {method.type === 'customer_credit' &&
                                        customer && (
                                            <span className="num text-[10px] text-muted-foreground">
                                                ({d.money(customer.advance)})
                                            </span>
                                        )}
                                    {key && (
                                        <Kbd
                                            keys={`alt+${key}`}
                                            className="ml-auto text-[9px]"
                                        />
                                    )}
                                </label>
                                <div className="flex-1">
                                    <MoneyInput
                                        id={`pos-tender-${method.id}`}
                                        value={amount}
                                        onChange={(v) =>
                                            setRow(method.id, {
                                                amount: v ?? 0,
                                            })
                                        }
                                        aria-describedby="pos-pay-summary"
                                        className={cn(
                                            'h-9 bg-surface-sunken font-mono font-bold',
                                            amount === 0 &&
                                                'text-muted-foreground',
                                        )}
                                    />
                                </div>
                            </div>
                            {method.requires_reference && amount > 0 && (
                                <Input
                                    className="ml-auto h-9 w-[calc(100%-9.5rem)] text-xs"
                                    value={row?.reference ?? ''}
                                    placeholder={t('pos.pay.reference')}
                                    aria-label={t('pos.pay.reference_for', {
                                        method: method.name,
                                    })}
                                    aria-invalid={
                                        (row?.reference ?? '').trim() === '' ||
                                        undefined
                                    }
                                    onChange={(e) =>
                                        setRow(method.id, {
                                            reference: e.target.value,
                                        })
                                    }
                                />
                            )}
                        </div>
                    );
                })}

                {cashId !== undefined && (
                    <div className="flex flex-wrap items-center gap-1 pt-2">
                        <button
                            type="button"
                            className="touch-target flex min-h-7 items-center gap-1 rounded border border-primary bg-primary-soft px-2 py-1 font-mono text-[10px] font-bold text-primary hover:bg-primary hover:text-primary-foreground"
                            onClick={() =>
                                setRows((prev) =>
                                    exactCash(prev, total, cashId),
                                )
                            }
                        >
                            {t('pos.pay.exact')}
                            <Kbd keys="alt+e" className="text-[9px]" />
                        </button>
                        {CASH_STEPS.map((step, i) => (
                            <button
                                key={step}
                                type="button"
                                className="touch-target flex min-h-7 flex-1 items-center justify-center gap-1 rounded border bg-surface-sunken py-1 font-mono text-[10px] font-semibold hover:border-primary"
                                aria-label={t('pos.pay.add_cash', {
                                    amount: d.money(step),
                                })}
                                aria-keyshortcuts={String(i + 1)}
                                title={`${t('pos.pay.add_cash', { amount: d.money(step) })} (${i + 1})`}
                                onClick={() =>
                                    setRows((prev) =>
                                        addCash(prev, cashId, step),
                                    )
                                }
                            >
                                +{d.n(step / 100)}
                            </button>
                        ))}
                        <button
                            type="button"
                            className="touch-target min-h-7 rounded bg-danger-soft px-2 py-1 font-mono text-[10px] font-semibold text-danger"
                            onClick={() =>
                                setRows((prev) =>
                                    prev.map((r) => ({ ...r, amount: 0 })),
                                )
                            }
                        >
                            {t('pos.pay.reset')}
                        </button>
                    </div>
                )}
                {couriers.length > 0 && (
                    <div className="space-y-1 pt-2">
                        <label
                            htmlFor="pos-pay-courier"
                            className="text-[11px] font-medium text-foreground"
                        >
                            {t('pos.pay.courier')}
                        </label>
                        <select
                            id="pos-pay-courier"
                            className="h-9 w-full rounded-lg border bg-surface-sunken px-2.5 text-xs text-foreground outline-none focus-visible:border-primary focus-visible:ring-[3px] focus-visible:ring-ring/50 [@media(pointer:coarse)]:min-h-11"
                            value={courier}
                            onChange={(e) => setCourier(e.target.value)}
                        >
                            <option value="">{t('pos.pay.courier_none')}</option>
                            {couriers.map((name) => (
                                <option key={name} value={name}>
                                    {name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}
                {customer && (
                    <button
                        type="button"
                        className="touch-target flex min-h-7 w-full items-center justify-between gap-2 rounded border border-dashed border-warning/40 px-2 py-1 text-[11px] font-semibold text-warning hover:bg-warning-soft"
                        onClick={() =>
                            setRows((prev) => leaveDue(prev, cashId))
                        }
                    >
                        {t('pos.pay.leave_due')}
                        <Kbd keys="alt+d" className="text-[9px]" />
                    </button>
                )}
            </fieldset>

            {/* Right: settlement */}
            <div className="flex flex-col justify-between gap-3 rounded-xl bg-surface-sunken p-4">
                <div
                    id="pos-pay-summary"
                    className="space-y-2 text-xs"
                    aria-live="polite"
                >
                    <div className="flex justify-between text-muted-foreground">
                        <span>{t('pos.pay.payable')}</span>
                        <span className="num text-sm font-bold text-foreground">
                            {d.money(total)}
                        </span>
                    </div>
                    <div className="flex justify-between text-muted-foreground">
                        <span>{t('pos.pay.tendered')}</span>
                        <span className="num text-sm font-bold text-success">
                            {d.money(s.tendered)}
                        </span>
                    </div>

                    {s.due > 0 ? (
                        <div className="space-y-1 rounded-xl border border-warning/30 bg-warning-soft p-2.5 text-warning">
                            <div className="flex items-center justify-between text-xs font-bold">
                                <span className="flex items-center gap-1">
                                    <AlertTriangle
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {t('pos.pay.paid_amount', {
                                        amount: d.money(s.tendered),
                                    })}
                                </span>
                                <span>
                                    {t('pos.pay.due')}: {d.money(s.due)}
                                </span>
                            </div>
                            {s.tendered === 0 && (
                                <p className="text-[10px] font-bold">
                                    {t('pos.pay.full_credit')}
                                </p>
                            )}
                            {!customer ? (
                                <div className="flex items-center justify-between gap-2 border-t border-warning/30 pt-2">
                                    <span className="text-[10px] font-bold text-danger">
                                        {t('pos.pay.attach_for_due')}
                                    </span>
                                    <button
                                        type="button"
                                        className="touch-target flex min-h-6 items-center gap-1 rounded bg-warning px-2 text-[10px] font-bold text-primary-foreground"
                                        onClick={onAttachCustomer}
                                    >
                                        <UserPlus
                                            className="size-3"
                                            aria-hidden="true"
                                        />
                                        {t('pos.pay.attach')}
                                    </button>
                                </div>
                            ) : (
                                s.balanceAfter !== null && (
                                    <div className="space-y-1 border-t border-warning/30 pt-2">
                                        <div className="flex justify-between font-mono text-[10px]">
                                            <span>
                                                {t('pos.pay.balance_after')}
                                            </span>
                                            <span className="num">
                                                {d.money(s.balanceAfter)}
                                                {customer.credit_limit !==
                                                    null &&
                                                    ` / ${d.money(customer.credit_limit)}`}
                                            </span>
                                        </div>
                                        {meter && (
                                            <div
                                                className="h-1.5 w-full overflow-hidden rounded-full bg-foreground/10"
                                                aria-hidden="true"
                                            >
                                                <div
                                                    className={cn(
                                                        'h-full',
                                                        meter.over
                                                            ? 'bg-danger'
                                                            : 'bg-warning',
                                                    )}
                                                    style={{
                                                        width: `${meter.percent}%`,
                                                    }}
                                                />
                                            </div>
                                        )}
                                    </div>
                                )
                            )}
                        </div>
                    ) : (
                        <div className="flex justify-between border-t pt-2 font-bold">
                            <span>{t('pos.pay.change')}</span>
                            <span className="num text-base text-success">
                                {d.money(s.change)}
                            </span>
                        </div>
                    )}

                    {customer &&
                        s.due === 0 &&
                        s.creditUsed > 0 &&
                        s.balanceAfter !== null && (
                            <div className="flex justify-between text-muted-foreground">
                                <span>{t('pos.pay.balance_after')}</span>
                                <span className="num">
                                    {d.money(s.balanceAfter)}
                                </span>
                            </div>
                        )}

                    {s.problems.length > 0 && (
                        <ul className="space-y-1 text-[11px]" role="alert">
                            {s.problems.map((p) => (
                                <li
                                    key={p}
                                    className={cn(
                                        OVERRIDABLE.includes(p) && approved
                                            ? 'text-muted-foreground'
                                            : 'font-semibold text-danger',
                                    )}
                                >
                                    {t(`pos.pay.problem.${p}`)}
                                    {p === 'over_limit' && !approved && (
                                        <Button
                                            type="button"
                                            variant="link"
                                            className="h-auto p-0 pl-2 text-[11px]"
                                            onClick={() =>
                                                onNeedApproval(
                                                    'pos.customer_pin_override',
                                                )
                                            }
                                        >
                                            {t('pos.manager.ask')}
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}

                    {error && (
                        <div
                            className="rounded-lg border border-danger bg-danger-soft p-2.5 text-xs"
                            role="alert"
                        >
                            <p className="font-semibold">
                                {error.message || t('pos.errors.network')}
                            </p>
                            {error.detail && (
                                <p className="mt-1 text-muted-foreground">
                                    {error.detail}
                                </p>
                            )}
                            {error.code && APPROVABLE[error.code] && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="mt-2 min-h-9"
                                    onClick={() =>
                                        onNeedApproval(
                                            APPROVABLE[error.code ?? ''] ?? '',
                                        )
                                    }
                                >
                                    <ShieldCheck aria-hidden="true" />{' '}
                                    {t('pos.manager.ask')}
                                </Button>
                            )}
                        </div>
                    )}

                    {approved && (
                        <p className="flex items-center gap-1 text-[11px] text-success">
                            <ShieldCheck
                                className="size-3.5"
                                aria-hidden="true"
                            />{' '}
                            {t('pos.manager.approved_by', {
                                name: approval.managerName,
                            })}
                        </p>
                    )}
                </div>

                <button
                    type="submit"
                    className="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-primary text-xs font-bold text-primary-foreground shadow-md transition-colors hover:bg-primary-hover disabled:opacity-40"
                    disabled={busy || blocking.length > 0}
                >
                    {busy
                        ? t('pos.pay.saving')
                        : s.due > 0
                          ? t('pos.pay.complete_due')
                          : autoPrint
                            ? t('pos.pay.complete_print')
                            : t('pos.pay.complete')}
                    <Kbd keys="f10" tone="on-primary" />
                </button>
            </div>
        </form>
    );
}
