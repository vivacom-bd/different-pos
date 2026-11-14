import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from "@testing-library/react";
import { useState } from "react";
import { afterEach, beforeAll, describe, expect, it, vi } from "vite-plus/test";
import { CustomerDialog } from "@/components/customers/customer-dialog";
import { HotkeyHost } from "@/hotkeys/host";
import { i18n } from "@/i18n";
import type { CustomerDetail, GroupOption } from "@/types/customers";

type Visit = {
    method: "post" | "put";
    url: string;
    data: Record<string, unknown>;
};
type FormOptions = {
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

const visits: Visit[] = [];
let nextErrors: Record<string, string> | null = null;

// A small stand-in for Inertia's useForm: state, isDirty, errors, and recorded submissions.
vi.mock("@inertiajs/react", async () => {
    const react = await vi.importActual<typeof import("react")>("react");

    return {
        Link: ({
            children,
            href,
        }: {
            children: React.ReactNode;
            href: string;
        }) => <a href={href}>{children}</a>,
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setAll] = react.useState(initial);
            const [errors, setErrors] = react.useState<Record<string, string>>(
                {},
            );
            const submit =
                (method: "post" | "put") =>
                (url: string, options: FormOptions) => {
                    visits.push({ method, url, data });
                    if (nextErrors) {
                        setErrors(nextErrors);
                        options.onError?.(nextErrors);
                    } else {
                        options.onSuccess?.();
                    }
                };

            return {
                data,
                errors,
                processing: false,
                isDirty: JSON.stringify(data) !== JSON.stringify(initial),
                setData: (key: keyof T, value: unknown) =>
                    setAll((d) => ({ ...d, [key]: value })),
                post: submit("post"),
                put: submit("put"),
            };
        },
    };
});

vi.mock("@/lib/api", () => ({
    apiRequest: vi.fn(async (url: string) => {
        if (url.startsWith("/app/api/geo/")) {
            return {
                version: "t",
                results: [],
                divisions: [],
                districts: [],
                upazilas: [],
                unions: [],
                post_codes: [],
            };
        }

        return {
            existing: {
                uuid: "u-9",
                name: "Already Here",
                shop_name: null,
                customer_no: "1000 0017",
                phone: "+8801711111111",
            },
        };
    }),
}));

const groups: GroupOption[] = [
    { id: 1, name: "Retail", discount_bp: 0 },
    { id: 2, name: "VIP Wholesale", discount_bp: 1000 },
];

const existing: CustomerDetail = {
    uuid: "c-1",
    customer_no: "1000 0025",
    name: "Rahim Uddin",
    name_bn: "রহিম উদ্দিন",
    shop_name: "Moon Store",
    phone: "+8801711234567",
    alt_phone: null,
    email: null,
    customer_group_id: 2,
    group: "VIP Wholesale",
    group_discount_bp: 1000,
    credit_limit: 1000000,
    balance: 0,
    address_line: "Holding 42",
    area: "Kalai",
    post_code: "5930",
    geo_division_id: null,
    geo_district_id: null,
    geo_upazila_id: null,
    geo_union_id: null,
    location: { division: null, district: null, upazila: null, union: null },
    location_verified: false,
    pin_status: "pending",
    pin_set_at: null,
    pin_locked_until: null,
    dob: null,
    anniversary_date: null,
    notes: null,
    member_since: null,
    last_purchase_at: null,
    is_active: true,
    is_walk_in: false,
};

function Harness({ customer = null }: { customer?: CustomerDetail | null }) {
    const [open, setOpen] = useState(true);

    return (
        <>
            <HotkeyHost />
            <span data-testid="state">{open ? "open" : "closed"}</span>
            <CustomerDialog
                open={open}
                customer={customer}
                groups={groups}
                onClose={() => setOpen(false)}
            />
        </>
    );
}

const t = (key: string): string => i18n.t(key);
const field = (key: string) =>
    screen.getByLabelText(new RegExp(`^${t(key).replace(/[()]/g, "\\$&")}`));

beforeAll(() => {
    globalThis.ResizeObserver ??= class {
        observe() {}
        unobserve() {}
        disconnect() {}
    } as unknown as typeof ResizeObserver;
});

afterEach(() => {
    cleanup();
    visits.length = 0;
    nextErrors = null;
});

describe("CustomerDialog", () => {
    it("renders the mockup sections in order with shop name next to the name", () => {
        render(<Harness />);
        const headings = screen
            .getAllByRole("heading", { level: 3 })
            .map((h) => h.textContent);
        expect(headings).toEqual([
            t("customers.dialog.section_identity"),
            t("customers.dialog.section_address"),
            t("customers.dialog.section_credit"),
            t("customers.dialog.section_dates"),
        ]);
        const inputs = Array.from(
            document.querySelectorAll("#customer-form [name]"),
        ).map((el) => el.getAttribute("name"));
        expect(inputs.slice(0, 4)).toEqual([
            "name",
            "name_bn",
            "shop_name",
            "phone",
        ]);
        expect(screen.getByRole("dialog")).toHaveProperty("textContent");
        expect(document.activeElement?.getAttribute("name")).toBe("name");
    });

    it("saves a new customer with Ctrl+Enter and closes", () => {
        render(<Harness />);
        fireEvent.change(field("customers.dialog.name_en"), {
            target: { value: "Asif Mahmud" },
        });
        fireEvent.change(field("customers.dialog.phone"), {
            target: { value: "01711234567" },
        });
        fireEvent.keyDown(field("customers.dialog.phone"), {
            key: "Enter",
            ctrlKey: true,
        });

        expect(visits).toHaveLength(1);
        expect(visits[0]).toMatchObject({
            method: "post",
            url: "/app/customers",
            data: { name: "Asif Mahmud", phone: "01711234567" },
        });
        expect(screen.getByTestId("state").textContent).toBe("closed");
    });

    it("updates an existing customer and shows the group discount", () => {
        render(<Harness customer={existing} />);
        expect(
            (field("customers.dialog.discount") as HTMLInputElement).value,
        ).toBe("10%");
        fireEvent.change(field("customers.dialog.group"), {
            target: { value: "1" },
        });
        expect(
            (field("customers.dialog.discount") as HTMLInputElement).value,
        ).toBe("0%");
        fireEvent.click(
            screen.getByRole("button", { name: t("customers.form.save") }),
        );

        expect(visits[0]).toMatchObject({
            method: "put",
            url: "/app/customers/c-1",
            data: { customer_group_id: "1", shop_name: "Moon Store" },
        });
    });

    it("shows server errors inline and the duplicate-phone owner, staying open", async () => {
        nextErrors = {
            phone: "This phone number already belongs to Already Here.",
        };
        render(<Harness />);
        fireEvent.change(field("customers.dialog.name_en"), {
            target: { value: "Copy" },
        });
        fireEvent.change(field("customers.dialog.phone"), {
            target: { value: "01711111111" },
        });
        await act(async () => {
            fireEvent.click(
                screen.getByRole("button", { name: t("customers.form.save") }),
            );
        });

        expect(
            screen.getByText(
                "This phone number already belongs to Already Here.",
            ),
        ).toBeTruthy();
        expect(
            field("customers.dialog.phone").getAttribute("aria-invalid"),
        ).toBe("true");
        expect(
            await screen.findByRole("link", {
                name: t("customers.form.open_existing"),
            }),
        ).toHaveProperty("href", expect.stringContaining("/app/customers/u-9"));
        expect(screen.getByTestId("state").textContent).toBe("open");
    });

    it("closes on Esc when untouched but asks first when there are changes", async () => {
        const { unmount } = render(<Harness />);
        fireEvent.keyDown(document.activeElement ?? document.body, {
            key: "Escape",
        });
        expect(screen.getByTestId("state").textContent).toBe("closed");
        unmount();

        render(<Harness />);
        fireEvent.change(field("customers.dialog.name_en"), {
            target: { value: "Half typed" },
        });
        fireEvent.keyDown(field("customers.dialog.name_en"), { key: "Escape" });
        expect(screen.getByTestId("state").textContent).toBe("open");
        expect(
            await screen.findByText(t("customers.dialog.discard_title")),
        ).toBeTruthy();
        await act(async () => {
            fireEvent.click(
                screen.getByRole("button", {
                    name: t("customers.dialog.discard"),
                }),
            );
        });
        expect(screen.getByTestId("state").textContent).toBe("closed");
        expect(visits).toHaveLength(0);
    });
});
