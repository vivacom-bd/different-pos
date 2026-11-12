import { Plus, ScanBarcode } from 'lucide-react';
import { forwardRef, useEffect, useRef, useState } from 'react';
import { Input } from '@/components/ui/input';
import { useT } from '@/i18n';
import { apiRequest } from '@/lib/api';

export type ScanProduct = {
    id: number;
    name: string;
    variant_label: string | null;
    sku: string;
    barcode: string | null;
    track_stock: boolean;
    /** Paisa; 0 when the user may not see costs. */
    cost_price: number;
    /** Purchase form only: last received cost in this branch, else the cost price (paisa). */
    purchase_cost?: number;
    /** Paisa. */
    sale_price: number;
    /** Milli-units on hand at the current branch. */
    on_hand: number;
    /** Only the purchase / stock lookups return raw materials; the register never does. */
    item_type?: 'finished' | 'raw_material';
    /** Short unit name (pcs, m, kg). */
    unit?: string | null;
    allows_decimal?: boolean;
};

type Result = { exact: boolean; products: ScanProduct[] };

/**
 * Scan-to-add: a barcode scanner types the code and presses Enter, which adds the exact match.
 * Anything else lists up to 8 name/SKU matches to pick from (Enter picks a single match).
 */
const lookup = (base: string, code: string): string =>
    `${base}${base.includes('?') ? '&' : '?'}code=${encodeURIComponent(code)}`;

export const ScanField = forwardRef<
    HTMLInputElement,
    {
        lookupUrl: string;
        onPick: (product: ScanProduct) => void;
        /** When set, a search with no match offers "New item" and hands over what was typed. */
        onCreate?: (typed: string) => void;
    }
>(function ScanField({ lookupUrl, onPick, onCreate }, ref) {
    const { t } = useT();
    const [text, setText] = useState('');
    const [matches, setMatches] = useState<ScanProduct[]>([]);
    const [message, setMessage] = useState<string | null>(null);
    /** What was typed when nothing matched (offers the inline create). */
    const [missed, setMissed] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [active, setActive] = useState(-1);
    /** Latest type-ahead request; an older answer (or one after Enter / a pick) is dropped. */
    const ticket = useRef(0);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const cancelSearch = () => {
        ticket.current += 1;
        if (timer.current !== null) {
            clearTimeout(timer.current);
            timer.current = null;
        }
    };
    useEffect(() => cancelSearch, []);

    /** Search as you type (name or SKU prefix), so a buyer sees matches without knowing Enter is needed. */
    const typeAhead = (value: string) => {
        cancelSearch();
        setMissed(null);
        const code = value.trim();
        if (code.length < 2) {
            setMatches([]);
            setActive(-1);
            if (code === '') {
                setMessage(null);
            }

            return;
        }
        const mine = ticket.current;
        timer.current = setTimeout(async () => {
            try {
                const result = await apiRequest<Result>(
                    lookup(lookupUrl, code),
                );
                if (mine !== ticket.current) {
                    return;
                }
                setActive(-1);
                if (result.products.length === 0) {
                    setMatches([]);
                    setMessage(t('scan.not_found', { code }));
                    setMissed(code);
                } else {
                    setMessage(null);
                    setMatches(result.products);
                }
            } catch {
                if (mine === ticket.current) {
                    setMessage(t('scan.failed'));
                }
            }
        }, 400);
    };

    const pick = (product: ScanProduct) => {
        cancelSearch();
        setActive(-1);
        onPick(product);
        setMissed(null);
        setText('');
        setMatches([]);
        setMessage(null);
    };

    const run = async () => {
        const code = text.trim();
        if (code === '' || busy) {
            return;
        }
        cancelSearch();
        setBusy(true);
        try {
            const result = await apiRequest<Result>(lookup(lookupUrl, code));
            const first = result.products[0];
            if (first && (result.exact || result.products.length === 1)) {
                pick(first);
            } else if (result.products.length === 0) {
                setMatches([]);
                setMessage(t('scan.not_found', { code }));
                setMissed(code);
            } else {
                setMessage(null);
                setMatches(result.products);
            }
        } catch {
            setMessage(t('scan.failed'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="relative">
            <ScanBarcode
                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                ref={ref}
                id="scan"
                className="num min-h-11 pl-9"
                value={text}
                autoComplete="off"
                placeholder={t('scan.placeholder')}
                aria-label={t('scan.label')}
                onChange={(e) => {
                    setText(e.target.value);
                    typeAhead(e.target.value);
                }}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown' && matches.length > 0) {
                        e.preventDefault();
                        setActive((a) => Math.min(a + 1, matches.length - 1));
                    } else if (e.key === 'ArrowUp' && matches.length > 0) {
                        e.preventDefault();
                        setActive((a) => Math.max(a - 1, 0));
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        const chosen = matches[active];
                        if (chosen) {
                            pick(chosen);
                        } else {
                            void run();
                        }
                    }
                    if (e.key === 'Escape') {
                        cancelSearch();
                        setMatches([]);
                        setMessage(null);
                    }
                }}
            />
            {message ? (
                <p role="alert" className="mt-1 text-sm text-destructive">
                    {message}
                </p>
            ) : null}
            {missed !== null && onCreate ? (
                <button
                    type="button"
                    className="mt-1 inline-flex min-h-11 items-center gap-1.5 rounded-md border bg-surface px-3 text-sm font-semibold text-primary hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    onClick={() => onCreate(missed)}
                >
                    <Plus className="size-4" aria-hidden="true" />
                    {t('scan.create_item', { name: missed })}
                </button>
            ) : null}
            {matches.length > 0 ? (
                <ul
                    aria-label={t('scan.matches')}
                    className="absolute z-10 mt-1 w-full overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md"
                >
                    {matches.map((m, i) => (
                        <li key={m.id}>
                            <button
                                type="button"
                                aria-current={i === active ? 'true' : undefined}
                                className={`flex min-h-11 w-full items-center justify-between gap-3 px-3 text-left text-sm hover:bg-muted focus-visible:bg-muted focus-visible:outline-none ${i === active ? 'bg-muted' : ''}`}
                                onClick={() => pick(m)}
                            >
                                <span className="flex min-w-0 items-center gap-2">
                                    <span className="truncate">
                                        {m.name}
                                        {m.variant_label
                                            ? ` · ${m.variant_label}`
                                            : ''}
                                    </span>
                                    {m.item_type ? (
                                        <span
                                            className={`shrink-0 rounded-sm px-1.5 py-0.5 text-[10px] font-bold ${m.item_type === 'raw_material' ? 'bg-info-soft text-info' : 'bg-success-soft text-success'}`}
                                        >
                                            {t(
                                                m.item_type === 'raw_material'
                                                    ? 'scan.type_raw'
                                                    : 'scan.type_finished',
                                            )}
                                        </span>
                                    ) : null}
                                </span>
                                <span className="num text-xs text-muted-foreground">
                                    {m.sku}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
});
