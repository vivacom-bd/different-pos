import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeAll, describe, expect, it, vi } from "vite-plus/test";
import { CollectDialog } from "@/components/sales/collect-dialog";
import { i18n } from "@/i18n";

const posts: {
    url: string;
    data: Record<string, unknown>;
    options?: { onSuccess?: (page: { url: string }) => void } | undefined;
}[] = [];
const shared = { autoPrint: true };

vi.mock("@inertiajs/react", () => ({
    router: {
        post: (
            url: string,
            data: Record<string, unknown>,
            options?: { onSuccess?: (page: { url: string }) => void },
        ) => posts.push({ url, data, options }),
    },
    usePage: () => ({
        props: { auth: { branch_auto_print: shared.autoPrint } },
    }),
}));

const t = (key: string, options: Record<string, unknown> = {}): string =>
    i18n.t(key, options);

const invoices = [
    { id: 1, number: "S-1", sold_at: "2026-08-01 10:00", due: 60_000 },
    { id: 2, number: "S-2", sold_at: "2026-10-01 10:00", due: 30_000 },
];
const methods = [
    { id: 3, name: "Cash", type: "cash", requires_reference: false },
    { id: 4, name: "bKash", type: "mfs", requires_reference: true },
];

beforeAll(() => {
    globalThis.ResizeObserver ??= class {
        observe() {}
        unobserve() {}
        disconnect() {}
    } as unknown as typeof ResizeObserver;
});

afterEach(() => {
    cleanup();
    posts.length = 0;
});

describe("CollectDialog", () => {
    it("shows the customer, the credit limit and the oldest-first allocation", () => {
        render(
            <CollectDialog
                open
                onOpenChange={vi.fn()}
                action="/app/dues/c-1/payments"
                title={t("dues.collect_title")}
                invoices={invoices}
                methods={methods}
                customer={{
                    name: "Selim",
                    customer_no: "1000 0017",
                    balance: 90_000,
                    credit_limit: 100_000,
                }}
            />,
        );
        const dialog = screen.getByRole("dialog");
        expect(dialog.textContent).toContain("Selim");
        expect(dialog.textContent).toContain(t("dues.open_invoices_oldest"));
        expect(dialog.textContent).toMatch(/Credit limit/);
        const list = screen.getByRole("list", { name: t("dues.allocation") });
        expect(list.querySelectorAll("li")).toHaveLength(2);
        expect(dialog.textContent).toContain(t("dues.remaining_after"));
    });

    it("posts the amount and method; a method needing a reference blocks until given", () => {
        render(
            <CollectDialog
                open
                onOpenChange={vi.fn()}
                action="/app/dues/c-1/payments"
                title={t("dues.collect_title")}
                invoices={invoices}
                methods={methods}
            />,
        );
        fireEvent.change(screen.getByLabelText(t("dues.tender")), {
            target: { value: "4" },
        });
        const submit = screen.getByRole("button", {
            name: new RegExp(t("dues.collect_confirm", { amount: "" }).trim()),
        }) as HTMLButtonElement;
        expect(submit.disabled).toBe(true);
        fireEvent.change(screen.getByLabelText(/Reference/), {
            target: { value: "TX1" },
        });
        expect(submit.disabled).toBe(false);
        fireEvent.click(submit);
        expect(posts).toHaveLength(1);
        expect(posts[0]).toMatchObject({
            url: "/app/dues/c-1/payments",
            data: { amount: 90_000, payment_method_id: 4, reference: "TX1" },
        });
    });

    it('keeps reference and note under "More" until asked (or a method needs a reference)', () => {
        render(
            <CollectDialog
                open
                onOpenChange={vi.fn()}
                action="/app/dues/c-1/payments"
                title={t("dues.collect_title")}
                invoices={invoices}
                methods={methods}
            />,
        );
        expect(screen.queryByLabelText(/Reference/)).toBeNull();
        expect(
            screen.getByRole("button", { name: t("dues.collect_confirm") }),
        ).toBeTruthy();
        fireEvent.click(
            screen.getByRole("button", { name: t("dues.more_fields") }),
        );
        expect(screen.getByLabelText(/Reference/)).toBeTruthy();
        expect(screen.getByLabelText(t("dues.note"))).toBeTruthy();
    });

    it.each([
        [true, 1],
        [false, 0],
    ])(
        "with branch auto_print=%s opens %s print window(s) after collecting",
        (autoPrint, opened) => {
            shared.autoPrint = autoPrint;
            const open = vi.spyOn(window, "open").mockReturnValue(null);
            render(
                <CollectDialog
                    open
                    onOpenChange={vi.fn()}
                    action="/app/dues/c-1/payments"
                    title={t("dues.collect_title")}
                    invoices={invoices}
                    methods={methods}
                />,
            );
            fireEvent.click(
                screen.getByRole("button", {
                    name: new RegExp(
                        t("dues.collect_confirm", { amount: "" }).trim(),
                    ),
                }),
            );
            posts[0]?.options?.onSuccess?.({ url: "/app/dues/c-1?receipt=7" });
            expect(open).toHaveBeenCalledTimes(opened);
            open.mockRestore();
            shared.autoPrint = true;
        },
    );
});
