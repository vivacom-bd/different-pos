import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { i18n } from '@/i18n';
import {
    bpText,
    DiscountDialog,
    discountFromEntry,
    parsePercentBp,
} from './discount-dialog';

afterEach(cleanup);

const t = (key: string, options: Record<string, unknown> = {}): string =>
    i18n.t(key, options);

const open = (
    overrides: Partial<Parameters<typeof DiscountDialog>[0]> = {},
) => {
    const onApply = vi.fn();
    const onNeedApproval = vi.fn();
    const onClose = vi.fn();
    render(
        <DiscountDialog
            open
            target="bill"
            base={115_000}
            current={{ type: 'none', value: 0 }}
            capBp={1000}
            onApply={onApply}
            onNeedApproval={onNeedApproval}
            onClose={onClose}
            {...overrides}
        />,
    );

    return { onApply, onNeedApproval, onClose };
};

const newTotal = () => screen.getByTestId('pos-discount-new-total').textContent;

describe('parsePercentBp / bpText', () => {
    it('reads percentages as integer basis points', () => {
        expect(parsePercentBp('12.5')).toBe(1250);
        expect(parsePercentBp('')).toBe(0);
        expect(parsePercentBp('100')).toBe(10_000);
        expect(parsePercentBp('100.01')).toBeNull();
        expect(parsePercentBp('-1')).toBeNull();
        expect(bpText(1250)).toBe('12.5');
        expect(bpText(1000)).toBe('10');
    });
});

describe('discountFromEntry', () => {
    it('percent rounds half-up on integers and 0 % is no discount', () => {
        expect(
            discountFromEntry({
                tab: 'percent_bp',
                bp: 1250,
                amount: null,
                target: null,
                base: 115_001,
            }),
        ).toEqual({
            discount: { type: 'percent_bp', value: 1250 },
            paisa: 14_375,
            invalid: false,
        });
        expect(
            discountFromEntry({
                tab: 'percent_bp',
                bp: 0,
                amount: null,
                target: null,
                base: 100,
            }).discount,
        ).toEqual({ type: 'none', value: 0 });
        expect(
            discountFromEntry({
                tab: 'percent_bp',
                bp: null,
                amount: null,
                target: null,
                base: 100,
            }).invalid,
        ).toBe(true);
    });

    it('amount is capped at the base', () => {
        expect(
            discountFromEntry({
                tab: 'amount',
                bp: null,
                amount: 999_999,
                target: null,
                base: 5000,
            }).paisa,
        ).toBe(5000);
    });

    it('set price: line = (unit − new unit) × qty, bill = base − new total, never negative', () => {
        expect(
            discountFromEntry({
                tab: 'set_price',
                bp: null,
                amount: null,
                target: 130_000,
                base: 290_000,
                unitPrice: 145_000,
                qtyMilli: 2000,
            }),
        ).toEqual({
            discount: { type: 'amount', value: 30_000 },
            paisa: 30_000,
            invalid: false,
        });
        expect(
            discountFromEntry({
                tab: 'set_price',
                bp: null,
                amount: null,
                target: 100_000,
                base: 115_000,
            }).paisa,
        ).toBe(15_000);
        expect(
            discountFromEntry({
                tab: 'set_price',
                bp: null,
                amount: null,
                target: 200_000,
                base: 115_000,
            }).paisa,
        ).toBe(0);
    });
});

describe('DiscountDialog', () => {
    it('renders the mockup modal: tabs, quick chips, reason, live preview, cancel / apply', () => {
        open();
        expect(screen.getByText(t('pos.discount.bill_title'))).toBeTruthy();
        expect(screen.getByText(t('pos.discount.bill_subject'))).toBeTruthy();
        expect(screen.getAllByRole('tab').map((e) => e.textContent)).toEqual([
            t('pos.discount.tab_amount'),
            t('pos.discount.tab_percent'),
            t('pos.discount.tab_set_price'),
        ]);
        // Amount (৳) is the default tab.
        expect(
            screen
                .getByRole('tab', { name: t('pos.discount.tab_amount') })
                .getAttribute('aria-selected'),
        ).toBe('true');
        expect(
            screen.getByLabelText(t('pos.discount.entry_amount')),
        ).toBeTruthy();
        for (const pct of [5, 10, 15, 20]) {
            expect(
                screen.getByRole('button', { name: `${pct} %` }),
            ).toBeTruthy();
        }
        expect(
            screen.getByLabelText(t('pos.discount.reason'), { exact: false }),
        ).toBeTruthy();
        expect(newTotal()).toContain('1,150.00');
        expect(
            screen.getByRole('button', { name: t('confirm.cancel') }),
        ).toBeTruthy();
        expect(
            screen.getByRole('button', { name: t('pos.discount.apply') }),
        ).toBeTruthy();
    });

    it('a quick chip sets the percent and the preview; Apply hands over a percent discount', () => {
        const { onApply } = open();
        fireEvent.click(screen.getByRole('button', { name: '10 %' }));
        expect(
            (
                screen.getByLabelText(
                    t('pos.discount.entry_percent'),
                ) as HTMLInputElement
            ).value,
        ).toBe('10');
        expect(screen.getByText(/−৳115\.00 \(10 %\)/)).toBeTruthy();
        expect(newTotal()).toContain('1,035.00');
        fireEvent.click(
            screen.getByRole('button', { name: t('pos.discount.apply') }),
        );
        expect(onApply).toHaveBeenCalledWith({
            type: 'percent_bp',
            value: 1000,
        });
    });

    it('typing a percent and pressing Enter applies; Bangla digits are read', () => {
        const { onApply } = open();
        fireEvent.click(
            screen.getByRole('tab', { name: t('pos.discount.tab_percent') }),
        );
        const input = screen.getByLabelText(t('pos.discount.entry_percent'));
        fireEvent.change(input, { target: { value: '৫' } });
        expect(newTotal()).toContain('1,092.50');
        fireEvent.submit(input.closest('form') as HTMLFormElement);
        expect(onApply).toHaveBeenCalledWith({
            type: 'percent_bp',
            value: 500,
        });
    });

    it('above the role cap shows the manager note, asks for approval and needs a custom reason for "Other"', () => {
        const { onApply, onNeedApproval } = open();
        fireEvent.click(screen.getByRole('button', { name: '20 %' }));
        expect(
            screen.getByText(t('pos.discount.over_cap', { pct: '10' })),
        ).toBeTruthy();
        fireEvent.click(
            screen.getByRole('button', { name: t('pos.manager.ask') }),
        );
        expect(onNeedApproval).toHaveBeenCalled();
        fireEvent.change(
            screen.getByLabelText(t('pos.discount.reason'), { exact: false }),
            {
                target: { value: 'other' },
            },
        );
        const apply = screen.getByRole('button', {
            name: t('pos.discount.apply'),
        }) as HTMLButtonElement;
        expect(apply.disabled).toBe(true);
        fireEvent.change(
            screen.getByLabelText(t('pos.discount.reason_other')),
            { target: { value: 'Owner said so' } },
        );
        expect(apply.disabled).toBe(false);
        fireEvent.click(apply);
        expect(onApply).toHaveBeenCalledWith({
            type: 'percent_bp',
            value: 2000,
        });
    });

    it('the amount tab gives a fixed amount; invalid percent disables Apply; Cancel closes', () => {
        const { onApply, onClose } = open();
        fireEvent.click(
            screen.getByRole('tab', { name: t('pos.discount.tab_amount') }),
        );
        const money = screen.getByLabelText(t('pos.discount.entry_amount'));
        fireEvent.focus(money);
        fireEvent.change(money, { target: { value: '50' } });
        expect(newTotal()).toContain('1,100.00');
        fireEvent.click(
            screen.getByRole('button', { name: t('pos.discount.apply') }),
        );
        expect(onApply).toHaveBeenCalledWith({ type: 'amount', value: 5000 });

        fireEvent.click(
            screen.getByRole('tab', { name: t('pos.discount.tab_percent') }),
        );
        fireEvent.change(
            screen.getByLabelText(t('pos.discount.entry_percent')),
            { target: { value: '101' } },
        );
        expect(
            (
                screen.getByRole('button', {
                    name: t('pos.discount.apply'),
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
        fireEvent.click(
            screen.getByRole('button', { name: t('confirm.cancel') }),
        );
        expect(onClose).toHaveBeenCalled();
    });

    it('line: "Set price" is the new unit price × qty and the title names the line', () => {
        const { onApply } = open({
            target: 'line',
            subject: 'Panjabi',
            base: 290_000,
            unitPrice: 145_000,
            qtyMilli: 2000,
        });
        expect(screen.getByText(t('pos.discount.line_title'))).toBeTruthy();
        expect(screen.getByText('Panjabi')).toBeTruthy();
        fireEvent.click(
            screen.getByRole('tab', { name: t('pos.discount.tab_set_price') }),
        );
        const price = screen.getByLabelText(t('pos.discount.entry_unit_price'));
        fireEvent.focus(price);
        fireEvent.change(price, { target: { value: '1300' } });
        expect(newTotal()).toContain('2,600.00');
        fireEvent.click(
            screen.getByRole('button', { name: t('pos.discount.apply') }),
        );
        expect(onApply).toHaveBeenCalledWith({ type: 'amount', value: 30_000 });
    });
});
