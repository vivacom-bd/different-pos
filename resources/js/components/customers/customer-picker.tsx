import { Search } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { Input } from '@/components/ui/input';
import { useDigits } from '@/hooks/use-digits';
import { useT } from '@/i18n';
import { apiRequest } from '@/lib/api';
import { cn } from '@/lib/utils';

export type PickedCustomer = {
    uuid: string;
    customer_no: string | null;
    name: string;
    name_bn: string | null;
    shop_name: string | null;
    phone: string | null;
    is_active: boolean;
};

/**
 * Search-as-you-type customer finder (number, phone, name or shop name) with a result dropdown.
 * Arrow keys move, Enter picks, Esc closes. The server decides what a query matches.
 */
export function CustomerPicker({
    onPick,
    autoFocus = false,
    placeholder,
    excludeUuid,
    className,
}: {
    onPick: (customer: PickedCustomer) => void;
    autoFocus?: boolean;
    placeholder?: string;
    excludeUuid?: string | undefined;
    className?: string;
}) {
    const { t } = useT();
    const digits = useDigits();
    const listId = useId();
    const [text, setText] = useState('');
    const [rawResults, setResults] = useState<PickedCustomer[]>([]);
    const [active, setActive] = useState(0);
    const [open, setOpen] = useState(false);
    const [searched, setSearched] = useState(false);
    const results = text.trim().length < 2 ? [] : rawResults;
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        const q = text.trim();
        if (timer.current) {
            clearTimeout(timer.current);
        }
        if (q.length < 2) {
            return;
        }
        const controller = new AbortController();
        timer.current = setTimeout(() => {
            apiRequest<{ customers: PickedCustomer[] }>(
                `/app/customers/search?q=${encodeURIComponent(q)}`,
                { signal: controller.signal },
            )
                .then((data) => {
                    setResults(
                        data.customers.filter((c) => c.uuid !== excludeUuid),
                    );
                    setActive(0);
                    setSearched(true);
                    setOpen(true);
                })
                .catch(() => undefined);
        }, 400);

        return () => {
            controller.abort();
            if (timer.current) {
                clearTimeout(timer.current);
            }
        };
    }, [text, excludeUuid]);

    const pick = (customer: PickedCustomer) => {
        setOpen(false);
        setText('');
        setResults([]);
        onPick(customer);
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setOpen(true);
            setActive((i) => Math.min(results.length - 1, i + 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((i) => Math.max(0, i - 1));
        } else if (event.key === 'Enter' && open && results[active]) {
            event.preventDefault();
            pick(results[active]);
        } else if (event.key === 'Escape' && open) {
            event.stopPropagation();
            setOpen(false);
        }
    };

    return (
        <div className={cn('relative', className)}>
            <Search
                className="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                type="search"
                aria-controls={open ? listId : undefined}
                autoComplete="off"
                autoFocus={autoFocus}
                value={text}
                placeholder={placeholder ?? t('customers.picker.placeholder')}
                aria-label={placeholder ?? t('customers.picker.placeholder')}
                className="min-h-11 pl-9 md:min-h-9"
                onChange={(e) => setText(e.target.value)}
                onKeyDown={onKeyDown}
            />
            <output className="sr-only">
                {open && results[active]
                    ? `${results[active].name} ${active + 1}/${results.length}`
                    : ''}
            </output>
            {open ? (
                <ul
                    id={listId}
                    className="absolute z-30 mt-1 max-h-80 w-full overflow-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
                >
                    {results.length === 0 && searched ? (
                        <li className="px-3 py-2 text-sm text-muted-foreground">
                            {t('customers.picker.none')}
                        </li>
                    ) : null}
                    {results.map((c, index) => (
                        <li key={c.uuid}>
                            <button
                                type="button"
                                aria-current={index === active}
                                tabIndex={-1}
                                className={cn(
                                    'flex min-h-11 w-full flex-col items-start rounded-sm px-3 py-1.5 text-left text-sm md:min-h-9',
                                    index === active && 'bg-accent',
                                )}
                                onMouseEnter={() => setActive(index)}
                                onClick={() => pick(c)}
                            >
                                <span className="font-medium">
                                    {c.name}
                                    {c.shop_name ? (
                                        <span className="ml-2 font-normal text-muted-foreground">
                                            {c.shop_name}
                                        </span>
                                    ) : null}
                                </span>
                                <span className="num text-xs text-muted-foreground">
                                    {[c.customer_no, c.phone]
                                        .filter(Boolean)
                                        .map((v) => digits.n(v as string))
                                        .join('  ')}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
