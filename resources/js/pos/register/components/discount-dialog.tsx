import { Tag } from 'lucide-react';
import { type KeyboardEventHandler, useState } from 'react';
import { MoneyInput } from '@/components/money-input';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { mulDivHalfUp, parseMoney, timesQty, type Paisa } from '@/engine/money';
import type { Discount } from '@/engine/pricing';
import { useDigits } from '@/hooks/use-digits';
import { useHotkeys } from '@/hotkeys/use-hotkeys';
import { useT } from '@/i18n';
import { cn } from '@/lib/utils';
import { PosDialog } from './pos-dialog';

/** "12.5" → 1250 bp (two decimals, integers only); null when not a percentage 0–100. */
export function parsePercentBp(text: string): number | null {
    try {
        const bp = parseMoney(text.trim() === '' ? '0' : text.trim());

        return bp >= 0 && bp <= 10_000 ? bp : null;
    } catch {
        return null;
    }
}

/** 1250 bp → "12.5" (no trailing zeros). */
export const bpText = (bp: number): string =>
    `${Math.trunc(bp / 100)}${
        bp % 100 === 0
            ? ''
            : `.${String(bp % 100)
                  .padStart(2, '0')
                  .replace(/0$/, '')}`
    }`;

/** Mockup quick-select chips. Alt+1 … Alt+4 pick them from the keyboard. */
export const QUICK_PERCENTS = [5, 10, 15, 20] as const;

/** Mockup reasons; UI-side only until the server takes a discount reason. */
export const DISCOUNT_REASONS = [
    'seasonal_promo',
    'customer_courtesy',
    'clearance',
    'defect',
    'bulk',
    'manager_special',
    'other',
] as const;
export type DiscountReason = (typeof DISCOUNT_REASONS)[number];

export type DiscountTab = 'percent_bp' | 'amount' | 'set_price';

/**
 * Pure dialog arithmetic (integers only): what the entry means in paisa against `base`.
 * - percent: base × bp / 10000 (half-up)
 * - amount: capped at base
 * - set_price: line → (unit price − new unit price) × qty; bill → base − new total
 */
export function discountFromEntry(input: {
    tab: DiscountTab;
    bp: number | null;
    amount: Paisa | null;
    target: Paisa | null;
    base: Paisa;
    unitPrice?: Paisa | undefined;
    qtyMilli?: number | undefined;
}): { discount: Discount; paisa: Paisa; invalid: boolean } {
    const { tab, base } = input;
    if (tab === 'percent_bp') {
        if (input.bp === null) {
            return {
                discount: { type: 'none', value: 0 },
                paisa: 0,
                invalid: true,
            };
        }
        const paisa = mulDivHalfUp(base, input.bp, 10_000);

        return {
            discount:
                input.bp === 0
                    ? { type: 'none', value: 0 }
                    : { type: 'percent_bp', value: input.bp },
            paisa,
            invalid: false,
        };
    }
    let amount: Paisa;
    if (tab === 'amount') {
        amount = Math.min(input.amount ?? 0, base);
    } else {
        const target = input.target;
        if (target === null) {
            amount = 0;
        } else if (
            input.unitPrice !== undefined &&
            input.qtyMilli !== undefined
        ) {
            const perUnit = Math.max(0, input.unitPrice - target);
            amount = Math.min(timesQty(perUnit, input.qtyMilli), base);
        } else {
            amount = Math.max(0, Math.min(base - target, base));
        }
    }

    return {
        discount:
            amount === 0
                ? { type: 'none', value: 0 }
                : { type: 'amount', value: amount },
        paisa: amount,
        invalid: false,
    };
}

type Props = {
    open: boolean;
    target: 'line' | 'bill';
    /** Line name (line) — the bill shows "Entire order / bill". */
    subject?: string | undefined;
    /** The amount the discount applies to (line gross / bill after line + group discounts). */
    base: Paisa;
    /** Line only: lets "Set price" mean the new unit price. */
    unitPrice?: Paisa | undefined;
    qtyMilli?: number | undefined;
    current: Discount;
    capBp: number;
    onApply: (discount: Discount) => void;
    onNeedApproval: () => void;
    onClose: () => void;
};

/**
 * F6 (line) / Shift+F6 (bill), the mockup discount modal: % · ৳ · Set price tabs, 5/10/15/20 % quick
 * chips, the entry, a reason, a live "discount / new total" preview and the manager note when above
 * the role cap. Above the cap is allowed to enter but flagged: a manager must approve it before Pay,
 * and the server checks again.
 */
export function DiscountDialog({
    open,
    target,
    subject,
    onClose,
    ...rest
}: Props) {
    const { t } = useT();

    return (
        <PosDialog
            icon={Tag}
            open={open}
            onClose={onClose}
            title={
                target === 'line'
                    ? t('pos.discount.line_title')
                    : t('pos.discount.bill_title')
            }
            description={
                target === 'line'
                    ? (subject ?? '')
                    : t('pos.discount.bill_subject')
            }
            className="sm:max-w-md"
        >
            {open && (
                <DiscountBody target={target} onClose={onClose} {...rest} />
            )}
        </PosDialog>
    );
}

function DiscountBody({
    target,
    base,
    unitPrice,
    qtyMilli,
    current,
    capBp,
    onApply,
    onNeedApproval,
    onClose,
}: Omit<Props, 'open' | 'subject'>) {
    const { t } = useT();
    const d = useDigits();
    const [tab, setTab] = useState<DiscountTab>(
        current.type === 'percent_bp' ? 'percent_bp' : 'amount',
    );
    const [percent, setPercent] = useState(
        current.type === 'percent_bp' ? bpText(current.value) : '',
    );
    const [amount, setAmount] = useState<Paisa | null>(
        current.type === 'amount' ? current.value : null,
    );
    const [price, setPrice] = useState<Paisa | null>(null);
    const [reason, setReason] = useState<DiscountReason>('seasonal_promo');
    const [customReason, setCustomReason] = useState('');

    const bp = tab === 'percent_bp' ? parsePercentBp(d.latin(percent)) : null;
    const result = discountFromEntry({
        tab,
        bp,
        amount,
        target: price,
        base,
        unitPrice,
        qtyMilli,
    });
    const effectiveBp =
        base === 0 ? 0 : mulDivHalfUp(result.paisa, 10_000, base);
    const overCap = effectiveBp > capBp;
    const reasonMissing =
        overCap && reason === 'other' && customReason.trim() === '';
    const invalid = result.invalid || reasonMissing;
    const newTotal = base - result.paisa;

    const switchTab = (next: DiscountTab) => {
        setTab(next);
        setPercent('');
        setAmount(null);
        setPrice(null);
    };
    const pick = (pct: number) => {
        setTab('percent_bp');
        setPercent(String(pct));
    };

    useHotkeys(
        'dialog',
        QUICK_PERCENTS.map((pct, i) => ({
            id: `pos.discount.quick_${pct}`,
            keys: `alt+${i + 1}`,
            labelKey: 'pos.discount.quick_hotkey',
            allowInInput: true,
            inDialog: true,
            handler: () => pick(pct),
        })),
    );

    const apply = () => {
        if (invalid) {
            return;
        }
        onApply(result.discount);
    };

    /** "%" jumps to the percent tab, as on a calculator. */
    const percentKey: KeyboardEventHandler<HTMLInputElement> = (e) => {
        if (e.key === '%') {
            e.preventDefault();
            switchTab('percent_bp');
        }
    };

    const entryLabel =
        tab === 'percent_bp'
            ? t('pos.discount.entry_percent')
            : tab === 'amount'
              ? t('pos.discount.entry_amount')
              : target === 'line'
                ? t('pos.discount.entry_unit_price')
                : t('pos.discount.entry_bill_total');

    return (
        <form
            className="space-y-3"
            onSubmit={(e) => {
                e.preventDefault();
                apply();
            }}
        >
            {/* Tabs: ৳ amount (default) · % · Set price */}
            <div
                role="tablist"
                aria-label={t('pos.discount.mode')}
                className="grid grid-cols-3 gap-1 rounded-lg bg-surface-sunken p-1"
            >
                {(
                    [
                        ['amount', t('pos.discount.tab_amount')],
                        ['percent_bp', t('pos.discount.tab_percent')],
                        ['set_price', t('pos.discount.tab_set_price')],
                    ] as const
                ).map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        role="tab"
                        aria-selected={tab === key}
                        onClick={() => switchTab(key)}
                        className={cn(
                            'min-h-11 rounded py-1 text-xs font-bold outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                            tab === key
                                ? 'bg-surface text-primary shadow-xs'
                                : 'text-muted-foreground',
                        )}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {/* Quick chips */}
            <div>
                <div className="mb-1 text-[10px] font-semibold text-muted-foreground">
                    {t('pos.discount.quick')}
                </div>
                <div className="grid grid-cols-4 gap-1.5">
                    {QUICK_PERCENTS.map((pct, i) => {
                        const active = tab === 'percent_bp' && bp === pct * 100;

                        return (
                            <button
                                key={pct}
                                type="button"
                                aria-pressed={active}
                                title={`Alt+${i + 1}`}
                                onClick={() => pick(pct)}
                                className={cn(
                                    'num min-h-11 rounded border text-xs font-bold outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                    active
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border bg-surface-sunken text-foreground hover:border-primary',
                                )}
                            >
                                {d.n(pct)} %
                            </button>
                        );
                    })}
                </div>
            </div>

            {/* Entry */}
            <div className="space-y-1">
                <Label
                    htmlFor="pos-discount-value"
                    className="text-[11px] font-semibold text-muted-foreground"
                >
                    {entryLabel}
                </Label>
                {tab === 'percent_bp' ? (
                    <Input
                        id="pos-discount-value"
                        autoFocus
                        inputMode="decimal"
                        placeholder="0"
                        className="num h-11 text-right"
                        value={percent}
                        aria-invalid={result.invalid || undefined}
                        onChange={(e) => setPercent(e.target.value)}
                        onFocus={(e) => e.currentTarget.select()}
                    />
                ) : tab === 'amount' ? (
                    <MoneyInput
                        id="pos-discount-value"
                        value={amount}
                        onChange={setAmount}
                        onKeyDown={percentKey}
                        className="h-11"
                    />
                ) : (
                    <MoneyInput
                        id="pos-discount-value"
                        value={price}
                        onChange={setPrice}
                        onKeyDown={percentKey}
                        className="h-11"
                    />
                )}
            </div>

            {/* Reason (UI-side) */}
            <div className="space-y-1">
                <Label
                    htmlFor="pos-discount-reason"
                    className="text-[11px] font-semibold text-muted-foreground"
                >
                    {t('pos.discount.reason')}{' '}
                    {overCap && (
                        <span className="font-bold text-destructive">
                            {t('pos.discount.reason_required', {
                                pct: d.n(bpText(capBp)),
                            })}
                        </span>
                    )}
                </Label>
                <NativeSelect
                    id="pos-discount-reason"
                    className="min-h-11"
                    value={reason}
                    onChange={(e) =>
                        setReason(e.target.value as DiscountReason)
                    }
                >
                    {DISCOUNT_REASONS.map((r) => (
                        <option key={r} value={r}>
                            {t(`pos.discount.reasons.${r}`)}
                        </option>
                    ))}
                </NativeSelect>
                {reason === 'other' && (
                    <Input
                        id="pos-discount-reason-other"
                        aria-label={t('pos.discount.reason_other')}
                        placeholder={t('pos.discount.reason_other_hint')}
                        className="h-11"
                        value={customReason}
                        aria-invalid={reasonMissing || undefined}
                        onChange={(e) => setCustomReason(e.target.value)}
                    />
                )}
            </div>

            {/* Live preview */}
            <dl
                className="num grid grid-cols-2 gap-x-3 gap-y-0.5 rounded-lg border border-border bg-surface-sunken p-2.5 text-xs"
                aria-live="polite"
            >
                <dt className="text-muted-foreground">
                    {t('pos.discount.on')}
                </dt>
                <dd className="text-right">{d.money(base)}</dd>
                <dt className="text-muted-foreground">
                    {t('pos.discount.discount')}
                </dt>
                <dd className="text-right text-destructive">
                    −{d.money(result.paisa)} ({d.n(bpText(effectiveBp))} %)
                </dd>
                <dt className="font-bold">{t('pos.discount.new_total')}</dt>
                <dd
                    className="text-right font-extrabold"
                    data-testid="pos-discount-new-total"
                >
                    {d.money(newTotal)}
                </dd>
            </dl>

            {overCap && (
                <div className="space-y-1.5 rounded-xl border border-warning/30 bg-warning-soft p-2.5 text-xs text-warning">
                    <p className="font-bold">
                        {t('pos.discount.over_cap', {
                            pct: d.n(bpText(capBp)),
                        })}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11 w-full"
                        onClick={onNeedApproval}
                    >
                        {t('pos.manager.ask')}
                    </Button>
                </div>
            )}

            <div className="flex gap-2 pt-2">
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11 flex-1"
                    onClick={onClose}
                >
                    {t('confirm.cancel')}
                </Button>
                <Button
                    type="submit"
                    className="min-h-11 flex-1 font-bold"
                    disabled={invalid}
                >
                    {t('pos.discount.apply')}
                </Button>
            </div>
        </form>
    );
}
