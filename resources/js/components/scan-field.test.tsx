import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { ScanField } from '@/components/scan-field';

const product = {
    id: 7,
    name: 'Cotton Tee',
    variant_label: 'M',
    sku: 'TEE-M',
    barcode: null,
    track_stock: true,
    cost_price: 0,
    sale_price: 45000,
    on_hand: 0,
};

const fetchMock = vi.fn();

beforeEach(() => {
    vi.useFakeTimers();
    fetchMock.mockResolvedValue({
        ok: true,
        json: async () => ({ exact: false, products: [product] }),
    });
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    fetchMock.mockReset();
});

const field = () => screen.getByRole('textbox');

describe('ScanField type-ahead', () => {
    it('looks up matches while typing, without Enter, and picks one by click', async () => {
        const onPick = vi.fn();
        render(<ScanField lookupUrl="/app/purchases/lookup" onPick={onPick} />);

        fireEvent.change(field(), { target: { value: 'tee' } });
        expect(fetchMock).not.toHaveBeenCalled();
        await act(async () => {
            await vi.advanceTimersByTimeAsync(450);
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0]?.[0])).toBe(
            '/app/purchases/lookup?code=tee',
        );
        fireEvent.click(screen.getByRole('button', { name: /Cotton Tee/ }));
        expect(onPick).toHaveBeenCalledWith(product);
    });

    it('does not search for a single character and Enter still does the exact lookup', async () => {
        const onPick = vi.fn();
        fetchMock.mockResolvedValue({
            ok: true,
            json: async () => ({ exact: true, products: [product] }),
        });
        render(<ScanField lookupUrl="/x" onPick={onPick} />);

        fireEvent.change(field(), { target: { value: 't' } });
        await act(async () => {
            await vi.advanceTimersByTimeAsync(450);
        });
        expect(fetchMock).not.toHaveBeenCalled();

        fireEvent.change(field(), { target: { value: 'TEE-M' } });
        fireEvent.keyDown(field(), { key: 'Enter' });
        await act(async () => {
            await vi.advanceTimersByTimeAsync(450);
        });
        // Enter cancelled the pending type-ahead: exactly one request, and the product was added.
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(onPick).toHaveBeenCalledWith(product);
    });

    it('arrow keys then Enter pick a listed match', async () => {
        const onPick = vi.fn();
        render(<ScanField lookupUrl="/x" onPick={onPick} />);
        fireEvent.change(field(), { target: { value: 'tee' } });
        await act(async () => {
            await vi.advanceTimersByTimeAsync(450);
        });
        screen.getByRole('button', { name: /Cotton Tee/ });
        fireEvent.keyDown(field(), { key: 'ArrowDown' });
        fireEvent.keyDown(field(), { key: 'Enter' });
        expect(onPick).toHaveBeenCalledWith(product);
    });

    it('marks each match with its type and keeps a type filter already in the URL', async () => {
        fetchMock.mockResolvedValue({
            ok: true,
            json: async () => ({
                exact: false,
                products: [
                    { ...product, item_type: 'raw_material', unit: 'm' },
                ],
            }),
        });
        render(
            <ScanField
                lookupUrl="/app/purchases/lookup?type=raw_material"
                onPick={vi.fn()}
            />,
        );

        fireEvent.change(field(), { target: { value: 'tee' } });
        await act(async () => {
            await vi.advanceTimersByTimeAsync(450);
        });

        expect(String(fetchMock.mock.calls[0]?.[0])).toBe(
            '/app/purchases/lookup?type=raw_material&code=tee',
        );
        expect(screen.getByText('Raw material')).toBeTruthy();
    });

    it('offers to create the item when nothing matches', async () => {
        fetchMock.mockResolvedValue({
            ok: true,
            json: async () => ({ exact: false, products: [] }),
        });
        const onCreate = vi.fn();
        render(
            <ScanField lookupUrl="/x" onPick={vi.fn()} onCreate={onCreate} />,
        );

        fireEvent.change(field(), { target: { value: 'linen' } });
        await act(async () => {
            await vi.advanceTimersByTimeAsync(450);
        });
        fireEvent.click(screen.getByRole('button', { name: /linen/ }));

        expect(onCreate).toHaveBeenCalledWith('linen');
    });
});
