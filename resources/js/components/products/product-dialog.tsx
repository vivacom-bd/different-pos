import { Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    Check,
    CircleDollarSign,
    ImageIcon,
    Images,
    Layers,
    Plus,
    Tag,
    Upload,
    Wand2,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Kbd } from '@/components/kbd';
import { MoneyInput } from '@/components/money-input';
import { QtyStepper } from '@/components/qty-stepper';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { mulDivHalfUp } from '@/engine/money';
import {
    Dialog,
    DialogActions,
    DialogBar,
    DialogBody,
    DialogContent,
} from '@/components/ui/dialog';
import { useHotkeys } from '@/hotkeys/use-hotkeys';
import { useT } from '@/i18n';
import { cn } from '@/lib/utils';
import { GroupDialog, type Family } from './group-dialog';
import { InlineBrandDialog } from './inline-brand-dialog';
import { ImagePickerDialog, type LibraryImage } from './image-picker-dialog';
import {
    defaultVariantDraft,
    gridFor,
    VariantBuilder,
    type VariantDraft,
    type VariantOptionDef,
} from './variant-builder';

type Option = { id: number; name: string };

export type ProductDetail = {
    id: number;
    uuid: string;
    name: string;
    name_bn: string | null;
    sku: string;
    barcode: string | null;
    hs_code: string | null;
    type: 'standard' | 'service';
    item_type: 'finished' | 'raw_material';
    category_id: number | null;
    brand_id: number | null;
    unit_id: number;
    tax_rate_id: number | null;
    default_vendor_id: number | null;
    sale_price: number;
    cost_price: number | null;
    min_sale_price: number | null;
    wholesale_price: number | null;
    min_sale_qty: number;
    reorder_level: number;
    track_stock: boolean;
    is_active: boolean;
    description: string | null;
    image_url: string | null;
    family_id: number | null;
    family_uuid: string | null;
    family_code: string | null;
    variant_label: string | null;
};

export type ProductFormOptions = {
    categories: Option[];
    brands: Option[];
    units: {
        id: number;
        name: string;
        short_name: string;
        allows_decimal: boolean;
    }[];
    taxRates: { id: number; name: string; rate_bp: number }[];
    vendors: Option[];
    variantOptions: VariantOptionDef[];
    /** The tenant's default unit (settings), preselected on a new product. */
    defaultUnitId?: number | null;
    /** `stock.adjust`: shows the opening stock field when creating. */
    canOpenStock?: boolean;
};

type Errors = Record<string, string>;

const idOrNull = (v: string): number | null => (v === '' ? null : Number(v));

/** Mockup form controls (render_catalog_stock_purchases.py product_form): 32 px, sunken, 12 px semibold. */
const box =
    'h-8 w-full rounded-lg border bg-surface-sunken px-2.5 text-xs font-semibold text-foreground outline-none focus-visible:border-primary focus-visible:ring-[3px] focus-visible:ring-ring/50 [@media(pointer:coarse)]:min-h-11';
const control = `mt-1 ${box}`;
const invalidControl = 'border-destructive bg-danger-soft';
const labelClass = 'text-[11px] font-semibold text-muted-foreground';

function initialState(
    product: ProductDetail | null,
    options: ProductFormOptions,
) {
    return {
        name: product?.name ?? '',
        name_bn: product?.name_bn ?? '',
        sku: product?.sku ?? '',
        barcode: product?.barcode ?? '',
        hs_code: product?.hs_code ?? '',
        type: product?.type ?? ('standard' as 'standard' | 'service'),
        item_type:
            product?.item_type ?? ('finished' as 'finished' | 'raw_material'),
        category_id: product?.category_id ?? null,
        brand_id: product?.brand_id ?? null,
        unit_id:
            product?.unit_id ??
            (options.units.some((u) => u.id === options.defaultUnitId)
                ? (options.defaultUnitId ?? null)
                : null) ??
            options.units[0]?.id ??
            null,
        opening_qty: 0,
        tax_rate_id: product?.tax_rate_id ?? null,
        default_vendor_id: product?.default_vendor_id ?? null,
        sale_price: product?.sale_price ?? (null as number | null),
        cost_price: product?.cost_price ?? (null as number | null),
        min_sale_price: product?.min_sale_price ?? (null as number | null),
        wholesale_price: product?.wholesale_price ?? (null as number | null),
        min_sale_qty: product?.min_sale_qty ?? 0,
        reorder_level: product?.reorder_level ?? 0,
        track_stock: product?.track_stock ?? true,
        is_active: product?.is_active ?? true,
        description: product?.description ?? '',
    };
}

/**
 * Add / edit product as the mockup dialog: "Classification & Identity" and "Pricing, VAT & Variant Matrix"
 * panels side by side, the size/colour variant grid below when creating a style. Saves through
 * SaveProductRequest; Ctrl+Enter (or Ctrl+S) saves, Esc closes and asks first when something changed.
 * `listQuery` (the list's `?q=…&filter[…]`) rides on the submit URL so the list comes back as it was.
 */
export function ProductDialog({
    product,
    families = [],
    options,
    canViewCost,
    canCreateBrand = false,
    listQuery = '',
    onClose,
}: {
    product: ProductDetail | null;
    families?: Family[];
    options: ProductFormOptions;
    canViewCost: boolean;
    /** `products.create`: shows the "+" that adds a brand without leaving the form. */
    canCreateBrand?: boolean;
    listQuery?: string;
    onClose: () => void;
}) {
    const { t } = useT();
    const editing = product !== null;
    const [initial] = useState(() => initialState(product, options));
    const [f, setF] = useState(initial);
    const [brands, setBrands] = useState(options.brands);
    const [brandOpen, setBrandOpen] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const [image, setImage] = useState<File | null>(null);
    const [removeImage, setRemoveImage] = useState(false);
    const [reused, setReused] = useState<LibraryImage | null>(null);
    const [variantOptions, setVariantOptions] = useState(
        options.variantOptions,
    );
    const [draft, setDraft] = useState<VariantDraft>(() =>
        defaultVariantDraft(options.variantOptions),
    );
    // "Has variants" is on exactly when size/colour chips are ticked (creating only).
    const variantRows = useMemo(
        () => gridFor(variantOptions, draft, f.sku),
        [variantOptions, draft, f.sku],
    );
    const raw = f.item_type === 'raw_material';
    const withVariants = !editing && !raw && variantRows.length > 0;
    const showOpening =
        !editing &&
        !withVariants &&
        options.canOpenStock === true &&
        (raw || (f.type === 'standard' && f.track_stock));
    const clearVariants = () =>
        setDraft((d) => ({ ...d, values1: [], values2: [], rows: [] }));
    const [groupOpen, setGroupOpen] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);

    const set = <K extends keyof typeof f>(key: K, value: (typeof f)[K]) => {
        setF((prev) => ({ ...prev, [key]: value }));
        if (errors[key]) {
            setErrors((prev) => ({ ...prev, [key]: '' }));
        }
    };

    const dirty =
        JSON.stringify(f) !== JSON.stringify(initial) ||
        image !== null ||
        removeImage ||
        reused !== null ||
        withVariants;

    const requestClose = () => {
        if (processing) {
            return;
        }
        if (dirty) {
            setConfirmClose(true);
        } else {
            onClose();
        }
    };

    /** Required fields are checked here first (mockup inline errors); the server re-validates everything. */
    const check = (): Errors => {
        const found: Errors = {};
        if (f.name.trim() === '') {
            found['name'] = t('products.err_name');
        }
        if (f.sku.trim() === '') {
            found['sku'] = t('products.err_sku');
        }
        if (f.unit_id === null) {
            found['unit_id'] = t('products.err_unit');
        }
        if (!raw && f.sale_price === null) {
            found['sale_price'] = t('products.err_price');
        }

        return found;
    };

    const save = () => {
        if (processing) {
            return;
        }
        const found = check();
        if (Object.keys(found).length > 0) {
            setErrors(found);
            const first = Object.keys(found)[0];
            document.getElementById(`product-${first}`)?.focus();

            return;
        }
        const payload: Record<string, unknown> = {
            ...f,
            opening_qty: showOpening && f.opening_qty > 0 ? f.opening_qty : null,
            hs_code: f.hs_code || null,
            barcode: withVariants ? null : f.barcode || null,
            // A raw material is bought and stocked, never sold: no retail price, always standard and tracked.
            ...(raw
                ? {
                      type: 'standard',
                      sale_price: 0,
                      wholesale_price: null,
                      min_sale_price: null,
                      track_stock: true,
                  }
                : {
                      track_stock: f.type === 'service' ? false : f.track_stock,
                  }),
            cost_price: canViewCost ? f.cost_price : null,
        };
        if (image) {
            payload['image'] = image;
        } else if (reused) {
            payload['existing_image'] = reused.path;
        }
        if (editing && removeImage) {
            payload['remove_image'] = true;
        }
        if (withVariants && draft.option1_id !== null) {
            payload['variants'] = {
                option1_id: draft.option1_id,
                option2_id: draft.option2_id,
                rows: variantRows.map((r) => ({
                    option1_value_id: r.option1_value_id,
                    option2_value_id: r.option2_value_id,
                    sale_price: r.sale_price,
                    cost_price: r.cost_price,
                    sku: r.sku,
                    barcode: r.barcode || null,
                })),
            };
        }
        const files = image !== null || withVariants;
        const base = editing
            ? `/app/products/${product.uuid}`
            : '/app/products';
        router.visit(`${base}${listQuery}`, {
            // A PUT carrying a file goes as POST + _method, which Laravel honours.
            method: editing && !files ? 'put' : 'post',
            data: (editing && files
                ? { ...payload, _method: 'put' }
                : payload) as never,
            forceFormData: files,
            preserveState: true,
            preserveScroll: true,
            // The saved list replaces the dialog URL, so Back does not reopen the dialog.
            replace: true,
            onStart: () => {
                setProcessing(true);
                setErrors({});
            },
            onError: (bag) => setErrors(bag as Errors),
            onFinish: () => setProcessing(false),
        });
    };

    useHotkeys('dialog', [
        {
            id: 'product.save',
            keys: 'mod+enter',
            labelKey: 'hotkeys.save',
            allowInInput: true,
            inDialog: true,
            handler: (event) => {
                event.preventDefault();
                save();
            },
        },
        {
            id: 'product.save_s',
            keys: 'mod+s',
            labelKey: 'hotkeys.save',
            allowInInput: true,
            inDialog: true,
            handler: (event) => {
                event.preventDefault();
                save();
            },
        },
    ]);

    const err = (key: string): string | undefined => errors[key] || undefined;
    const margin =
        !raw &&
        canViewCost &&
        f.cost_price !== null &&
        f.cost_price > 0 &&
        f.sale_price !== null &&
        f.sale_price > 0
            ? mulDivHalfUp(f.sale_price - f.cost_price, 100, f.cost_price)
            : null;

    return (
        <>
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) {
                        requestClose();
                    }
                }}
            >
                <DialogContent
                    layout="panel"
                    size="4xl"
                    className="sm:max-w-4xl"
                    onEscapeKeyDown={(e) => {
                        // A second Esc that lands before the discard confirm has taken over the key
                        // cancels the confirm instead of re-asking.
                        if (confirmClose) {
                            e.preventDefault();
                            setConfirmClose(false);
                        }
                    }}
                    onOpenAutoFocus={(e) => {
                        e.preventDefault();
                        document.getElementById('product-name')?.focus();
                    }}
                >
                    <DialogBar
                        title={
                            editing
                                ? t('products.dialog_edit')
                                : t('products.dialog_new')
                        }
                        subtitle={t('products.dialog_hint')}
                        icon={Tag}
                        badge={editing ? product.sku : undefined}
                        closeLabel={t('common.close')}
                    />
                    <form
                        id="product-form"
                        className="contents"
                        noValidate
                        onSubmit={(e) => {
                            e.preventDefault();
                            save();
                        }}
                    >
                        <DialogBody className="space-y-4">
                            {err('form') ? (
                                <p
                                    role="alert"
                                    className="rounded-lg bg-danger-soft p-2.5 text-xs font-semibold text-destructive"
                                >
                                    {err('form')}
                                </p>
                            ) : null}
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                {/* Panel 1: Classification & Identity */}
                                <Panel
                                    icon={
                                        <Tag className="size-3.5 text-primary" />
                                    }
                                    title={t('products.panel_identity')}
                                >
                                    <Field
                                        id="product-name"
                                        label={`${t('products.name_en')} *`}
                                        error={err('name')}
                                    >
                                        <input
                                            id="product-name"
                                            className={cn(
                                                control,
                                                err('name') && invalidControl,
                                            )}
                                            value={f.name}
                                            placeholder={t(
                                                'products.name_placeholder',
                                            )}
                                            aria-invalid={
                                                err('name') ? true : undefined
                                            }
                                            onChange={(e) =>
                                                set('name', e.target.value)
                                            }
                                        />
                                    </Field>
                                    <Field
                                        id="product-name_bn"
                                        label={t('products.name_bn_label')}
                                        error={err('name_bn')}
                                    >
                                        <input
                                            id="product-name_bn"
                                            lang="bn"
                                            className={cn(
                                                control,
                                                'font-bangla font-normal',
                                            )}
                                            value={f.name_bn}
                                            placeholder={t(
                                                'products.name_bn_placeholder',
                                            )}
                                            onChange={(e) =>
                                                set('name_bn', e.target.value)
                                            }
                                        />
                                    </Field>
                                    <div className="grid grid-cols-2 gap-2">
                                        <Field
                                            id="product-category_id"
                                            label={t('products.col_category')}
                                            error={err('category_id')}
                                        >
                                            <select
                                                id="product-category_id"
                                                className={cn(control, 'px-2')}
                                                value={f.category_id ?? ''}
                                                onChange={(e) =>
                                                    set(
                                                        'category_id',
                                                        idOrNull(
                                                            e.target.value,
                                                        ),
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    {t('common.none')}
                                                </option>
                                                {options.categories.map((o) => (
                                                    <option
                                                        key={o.id}
                                                        value={o.id}
                                                    >
                                                        {o.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>
                                        <Field
                                            id="product-brand_id"
                                            label={t('products.brand_label')}
                                            error={err('brand_id')}
                                        >
                                            <div className="mt-1 flex gap-1.5">
                                                <select
                                                    id="product-brand_id"
                                                    className={cn(
                                                        control,
                                                        'mt-0 min-w-0 flex-1 px-2',
                                                    )}
                                                    value={f.brand_id ?? ''}
                                                    onChange={(e) =>
                                                        set(
                                                            'brand_id',
                                                            idOrNull(
                                                                e.target.value,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        {t('common.none')}
                                                    </option>
                                                    {brands.map((o) => (
                                                        <option
                                                            key={o.id}
                                                            value={o.id}
                                                        >
                                                            {o.name}
                                                        </option>
                                                    ))}
                                                </select>
                                                {canCreateBrand ? (
                                                    <button
                                                        type="button"
                                                        title={t(
                                                            'products.brand_add',
                                                        )}
                                                        aria-label={t(
                                                            'products.brand_add',
                                                        )}
                                                        onClick={() =>
                                                            setBrandOpen(true)
                                                        }
                                                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border bg-surface-sunken outline-none hover:bg-border focus-visible:border-primary focus-visible:ring-[3px] focus-visible:ring-ring/50 [@media(pointer:coarse)]:size-11"
                                                    >
                                                        <Plus
                                                            className="size-3.5"
                                                            aria-hidden="true"
                                                        />
                                                    </button>
                                                ) : null}
                                            </div>
                                        </Field>
                                    </div>
                                    <div className="grid grid-cols-2 gap-2">
                                        <Field
                                            id="product-default_vendor_id"
                                            label={t('products.vendor_label')}
                                            error={err('default_vendor_id')}
                                        >
                                            <select
                                                id="product-default_vendor_id"
                                                className={cn(control, 'px-2')}
                                                value={
                                                    f.default_vendor_id ?? ''
                                                }
                                                onChange={(e) =>
                                                    set(
                                                        'default_vendor_id',
                                                        idOrNull(
                                                            e.target.value,
                                                        ),
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    {t('common.none')}
                                                </option>
                                                {options.vendors.map((o) => (
                                                    <option
                                                        key={o.id}
                                                        value={o.id}
                                                    >
                                                        {o.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>
                                        <Field
                                            id="product-unit_id"
                                            label={`${t('products.unit_label')} *`}
                                            error={err('unit_id')}
                                        >
                                            <select
                                                id="product-unit_id"
                                                className={cn(
                                                    control,
                                                    'px-2',
                                                    err('unit_id') &&
                                                        invalidControl,
                                                )}
                                                value={f.unit_id ?? ''}
                                                onChange={(e) =>
                                                    set(
                                                        'unit_id',
                                                        idOrNull(
                                                            e.target.value,
                                                        ),
                                                    )
                                                }
                                            >
                                                {f.unit_id === null ? (
                                                    <option value="">
                                                        {t('common.choose')}
                                                    </option>
                                                ) : null}
                                                {options.units.map((u) => (
                                                    <option
                                                        key={u.id}
                                                        value={u.id}
                                                    >
                                                        {u.name} ({u.short_name}
                                                        )
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>
                                    </div>
                                    <div className="grid grid-cols-2 gap-2">
                                        <Field
                                            id="product-sku"
                                            label={`${
                                                withVariants
                                                    ? t('products.family_code')
                                                    : t('products.sku_label')
                                            } *`}
                                            error={err('sku')}
                                        >
                                            <input
                                                id="product-sku"
                                                className={cn(
                                                    control,
                                                    'num font-mono font-bold',
                                                    err('sku') &&
                                                        invalidControl,
                                                )}
                                                value={f.sku}
                                                aria-invalid={
                                                    err('sku')
                                                        ? true
                                                        : undefined
                                                }
                                                onChange={(e) =>
                                                    set('sku', e.target.value)
                                                }
                                            />
                                        </Field>
                                        {!withVariants ? (
                                            <Field
                                                id="product-barcode"
                                                label={t(
                                                    'products.barcode_label',
                                                )}
                                                error={err('barcode')}
                                                hint={
                                                    f.barcode === '' && !editing
                                                        ? t(
                                                              'products.barcode_auto',
                                                          )
                                                        : undefined
                                                }
                                            >
                                                <div className="mt-1 flex gap-1.5">
                                                    <input
                                                        id="product-barcode"
                                                        className={cn(
                                                            control,
                                                            'num mt-0 flex-1 font-mono font-bold',
                                                        )}
                                                        value={f.barcode}
                                                        placeholder={t(
                                                            'products.barcode_auto_short',
                                                        )}
                                                        onChange={(e) =>
                                                            set(
                                                                'barcode',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                    <button
                                                        type="button"
                                                        title={t(
                                                            'products.barcode_auto',
                                                        )}
                                                        disabled={
                                                            f.barcode === ''
                                                        }
                                                        onClick={() =>
                                                            set('barcode', '')
                                                        }
                                                        className="flex h-8 items-center gap-1 rounded-lg border bg-surface-sunken px-2 text-[10px] font-semibold hover:bg-border disabled:opacity-50 [@media(pointer:coarse)]:min-h-11"
                                                    >
                                                        <Wand2
                                                            className="size-3"
                                                            aria-hidden="true"
                                                        />
                                                        {t(
                                                            'products.barcode_gen',
                                                        )}
                                                    </button>
                                                </div>
                                            </Field>
                                        ) : null}
                                    </div>
                                    <Field
                                        id="product-item_type"
                                        label={t('products.item_type')}
                                        error={err('item_type')}
                                        hint={
                                            raw
                                                ? t('products.raw_hint')
                                                : undefined
                                        }
                                    >
                                        <select
                                            id="product-item_type"
                                            className={cn(control, 'px-2')}
                                            value={f.item_type}
                                            onChange={(e) => {
                                                const next = e.target
                                                    .value as typeof f.item_type;
                                                setF((prev) => ({
                                                    ...prev,
                                                    item_type: next,
                                                    ...(next === 'raw_material'
                                                        ? { type: 'standard' }
                                                        : {}),
                                                }));
                                                if (next === 'raw_material') {
                                                    clearVariants();
                                                }
                                            }}
                                        >
                                            <option value="finished">
                                                {t('products.item_finished')}
                                            </option>
                                            <option value="raw_material">
                                                {t(
                                                    'products.item_raw_material',
                                                )}
                                            </option>
                                        </select>
                                    </Field>
                                    <div className="grid grid-cols-2 gap-2">
                                        {!raw ? (
                                            <Field
                                                id="product-type"
                                                label={t('products.type')}
                                                error={err('type')}
                                            >
                                                <select
                                                    id="product-type"
                                                    className={cn(
                                                        control,
                                                        'px-2',
                                                    )}
                                                    value={f.type}
                                                    onChange={(e) =>
                                                        set(
                                                            'type',
                                                            e.target.value as
                                                                | 'standard'
                                                                | 'service',
                                                        )
                                                    }
                                                >
                                                    <option value="standard">
                                                        {t(
                                                            'products.type_standard',
                                                        )}
                                                    </option>
                                                    <option value="service">
                                                        {t(
                                                            'products.type_service',
                                                        )}
                                                    </option>
                                                </select>
                                            </Field>
                                        ) : null}
                                        <Field
                                            id="product-hs_code"
                                            label={t('products.hs_code')}
                                            error={err('hs_code')}
                                        >
                                            <input
                                                id="product-hs_code"
                                                className={cn(
                                                    control,
                                                    'num font-mono',
                                                )}
                                                value={f.hs_code}
                                                onChange={(e) =>
                                                    set(
                                                        'hs_code',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                    </div>
                                    <ImageField
                                        current={
                                            reused?.url ??
                                            (removeImage
                                                ? null
                                                : (product?.image_url ?? null))
                                        }
                                        reused={reused !== null}
                                        chosen={image}
                                        error={
                                            err('image') ??
                                            err('existing_image')
                                        }
                                        onChoose={(file) => {
                                            setImage(file);
                                            if (file) {
                                                setReused(null);
                                            }
                                        }}
                                        onReuse={(picked) => {
                                            setReused(picked);
                                            setImage(null);
                                            setRemoveImage(false);
                                        }}
                                        onRemove={() => {
                                            if (reused) {
                                                setReused(null);
                                            } else {
                                                setRemoveImage(true);
                                            }
                                        }}
                                    />
                                    <Field
                                        id="product-description"
                                        label={t('products.description')}
                                        error={err('description')}
                                    >
                                        <textarea
                                            id="product-description"
                                            rows={2}
                                            className={cn(
                                                control,
                                                'h-auto min-h-14 py-1.5 font-normal',
                                            )}
                                            value={f.description}
                                            onChange={(e) =>
                                                set(
                                                    'description',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </Panel>

                                {/* Panel 2: Pricing, VAT & Variant Matrix */}
                                <Panel
                                    icon={
                                        <CircleDollarSign className="size-3.5 text-success" />
                                    }
                                    title={t('products.panel_pricing')}
                                    badge={
                                        margin === null ? null : (
                                            <span
                                                className={cn(
                                                    'num rounded-sm px-2 py-0.5 text-[10px] font-bold',
                                                    margin >= 0
                                                        ? 'bg-success-soft text-success'
                                                        : 'bg-danger-soft text-destructive',
                                                )}
                                            >
                                                {margin >= 0
                                                    ? t('products.margin', {
                                                          n: margin,
                                                      })
                                                    : t(
                                                          'products.negative_margin',
                                                      )}
                                            </span>
                                        )
                                    }
                                >
                                    <div className="grid grid-cols-2 gap-2">
                                        {canViewCost ? (
                                            <Field
                                                id="product-cost_price"
                                                label={
                                                    raw
                                                        ? t(
                                                              'products.raw_cost_label',
                                                          )
                                                        : t(
                                                              'products.cost_label',
                                                          )
                                                }
                                                error={err('cost_price')}
                                            >
                                                <div className="mt-1">
                                                    <MoneyInput
                                                        id="product-cost_price"
                                                        className={cn(
                                                            box,
                                                            'pl-8 text-right font-bold',
                                                        )}
                                                        value={f.cost_price}
                                                        invalid={Boolean(
                                                            err('cost_price'),
                                                        )}
                                                        onChange={(v) =>
                                                            set('cost_price', v)
                                                        }
                                                    />
                                                </div>
                                            </Field>
                                        ) : null}
                                        {!raw ? (
                                            <Field
                                                id="product-sale_price"
                                                label={`${t('products.mrp_label')} *`}
                                                error={err('sale_price')}
                                            >
                                                <div className="mt-1">
                                                    <MoneyInput
                                                        id="product-sale_price"
                                                        className={cn(
                                                            box,
                                                            'pl-8 text-right font-bold text-primary',
                                                            err('sale_price') &&
                                                                invalidControl,
                                                        )}
                                                        value={f.sale_price}
                                                        invalid={Boolean(
                                                            err('sale_price'),
                                                        )}
                                                        onChange={(v) =>
                                                            set('sale_price', v)
                                                        }
                                                    />
                                                </div>
                                            </Field>
                                        ) : null}
                                    </div>
                                    <div className="grid grid-cols-2 gap-2">
                                        {!raw ? (
                                            <Field
                                                id="product-wholesale_price"
                                                label={t(
                                                    'products.wholesale_label',
                                                )}
                                                error={err('wholesale_price')}
                                            >
                                                <div className="mt-1">
                                                    <MoneyInput
                                                        id="product-wholesale_price"
                                                        className={cn(
                                                            box,
                                                            'pl-8 text-right font-bold',
                                                        )}
                                                        value={
                                                            f.wholesale_price
                                                        }
                                                        onChange={(v) =>
                                                            set(
                                                                'wholesale_price',
                                                                v,
                                                            )
                                                        }
                                                    />
                                                </div>
                                            </Field>
                                        ) : null}
                                        <Field
                                            id="product-tax_rate_id"
                                            label={t('products.vat_label')}
                                            error={err('tax_rate_id')}
                                        >
                                            <select
                                                id="product-tax_rate_id"
                                                className={cn(control, 'px-2')}
                                                value={f.tax_rate_id ?? ''}
                                                onChange={(e) =>
                                                    set(
                                                        'tax_rate_id',
                                                        idOrNull(
                                                            e.target.value,
                                                        ),
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    {t('common.none')}
                                                </option>
                                                {options.taxRates.map((r) => (
                                                    <option
                                                        key={r.id}
                                                        value={r.id}
                                                    >
                                                        {r.name} (
                                                        {r.rate_bp / 100}%)
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>
                                    </div>

                                    {!raw ? (
                                        <div className="grid grid-cols-2 gap-2">
                                            <Field
                                                id="product-min_sale_price"
                                                label={t(
                                                    'products.min_sale_price',
                                                )}
                                                error={err('min_sale_price')}
                                                hint={t(
                                                    'products.min_sale_price_hint',
                                                )}
                                            >
                                                <div className="mt-1">
                                                    <MoneyInput
                                                        id="product-min_sale_price"
                                                        className={cn(
                                                            box,
                                                            'pl-8 text-right',
                                                        )}
                                                        value={f.min_sale_price}
                                                        onChange={(v) =>
                                                            set(
                                                                'min_sale_price',
                                                                v,
                                                            )
                                                        }
                                                    />
                                                </div>
                                            </Field>
                                        </div>
                                    ) : null}

                                    {!raw ? (
                                        <>
                                            {/* Where the mockup has "Applicable Sizes / Colors". */}
                                            <div>
                                                <div className="flex items-center justify-between gap-2">
                                                    <span
                                                        className={labelClass}
                                                    >
                                                        {t('products.variants')}
                                                    </span>
                                                    {!editing ? (
                                                        <button
                                                            type="button"
                                                            role="switch"
                                                            id="product-with_variants"
                                                            aria-checked={
                                                                withVariants
                                                            }
                                                            title={t(
                                                                'products.has_variants_hint',
                                                            )}
                                                            onClick={() => {
                                                                if (
                                                                    withVariants
                                                                ) {
                                                                    clearVariants();
                                                                } else {
                                                                    document
                                                                        .querySelector<HTMLElement>(
                                                                            '#product-variant-chips fieldset button',
                                                                        )
                                                                        ?.focus();
                                                                }
                                                            }}
                                                            className="flex items-center gap-1.5 rounded-md text-[11px] font-semibold text-muted-foreground outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 [@media(pointer:coarse)]:min-h-11"
                                                        >
                                                            {t(
                                                                'products.has_variants',
                                                            )}
                                                            <span
                                                                aria-hidden="true"
                                                                className={cn(
                                                                    'relative inline-flex h-4 w-7 items-center rounded-full transition-colors',
                                                                    withVariants
                                                                        ? 'bg-primary'
                                                                        : 'bg-border',
                                                                )}
                                                            >
                                                                <span
                                                                    className={cn(
                                                                        'size-3 rounded-full bg-surface shadow-xs transition-transform',
                                                                        withVariants
                                                                            ? 'translate-x-3.5'
                                                                            : 'translate-x-0.5',
                                                                    )}
                                                                />
                                                            </span>
                                                        </button>
                                                    ) : null}
                                                </div>
                                                {editing ? (
                                                    <div className="mt-1 flex flex-wrap items-center gap-2 rounded-lg border bg-surface-sunken p-2 text-[11px]">
                                                        {product.variant_label ? (
                                                            <span>
                                                                {t(
                                                                    'products.variant_of',
                                                                    {
                                                                        code:
                                                                            product.family_code ??
                                                                            '',
                                                                        label: product.variant_label,
                                                                    },
                                                                )}
                                                            </span>
                                                        ) : (
                                                            <span className="text-muted-foreground">
                                                                {t(
                                                                    'products.no_style',
                                                                )}
                                                            </span>
                                                        )}
                                                        {product.family_uuid !==
                                                        null ? (
                                                            <Link
                                                                href={`/app/products/families/${product.family_uuid}`}
                                                                className="ml-auto inline-flex items-center gap-1 font-semibold text-primary hover:underline"
                                                            >
                                                                <Layers
                                                                    className="size-3"
                                                                    aria-hidden="true"
                                                                />
                                                                {t(
                                                                    'products.open_family',
                                                                )}
                                                            </Link>
                                                        ) : families.length >
                                                          0 ? (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    setGroupOpen(
                                                                        true,
                                                                    )
                                                                }
                                                                className="ml-auto inline-flex items-center gap-1 font-semibold text-primary hover:underline"
                                                            >
                                                                <Layers
                                                                    className="size-3"
                                                                    aria-hidden="true"
                                                                />
                                                                {t(
                                                                    'products.group',
                                                                )}
                                                            </button>
                                                        ) : null}
                                                    </div>
                                                ) : (
                                                    <div
                                                        id="product-variant-chips"
                                                        className="mt-1"
                                                    >
                                                        <VariantBuilder
                                                            layout="chips"
                                                            options={
                                                                variantOptions
                                                            }
                                                            onOptionsChange={
                                                                setVariantOptions
                                                            }
                                                            draft={draft}
                                                            onChange={setDraft}
                                                            familyCode={f.sku}
                                                            canViewCost={
                                                                canViewCost
                                                            }
                                                        />
                                                    </div>
                                                )}
                                            </div>
                                        </>
                                    ) : null}

                                    {/* Where the mockup has the branch stock row: min qty and reorder level. */}
                                    <div className="grid grid-cols-2 gap-2 border-t pt-2">
                                        {!raw ? (
                                            <Field
                                                id="product-min_sale_qty"
                                                label={t(
                                                    'products.min_sale_qty',
                                                )}
                                                error={err('min_sale_qty')}
                                            >
                                                <div className="mt-1">
                                                    <QtyStepper
                                                        id="product-min_sale_qty"
                                                        value={f.min_sale_qty}
                                                        onChange={(v) =>
                                                            set(
                                                                'min_sale_qty',
                                                                v,
                                                            )
                                                        }
                                                        aria-label={t(
                                                            'products.min_sale_qty',
                                                        )}
                                                    />
                                                </div>
                                            </Field>
                                        ) : null}
                                        {f.type === 'standard' ? (
                                            <Field
                                                id="product-reorder_level"
                                                label={t(
                                                    'products.reorder_level',
                                                )}
                                                error={err('reorder_level')}
                                            >
                                                <div className="mt-1">
                                                    <QtyStepper
                                                        id="product-reorder_level"
                                                        value={f.reorder_level}
                                                        onChange={(v) =>
                                                            set(
                                                                'reorder_level',
                                                                v,
                                                            )
                                                        }
                                                        aria-label={t(
                                                            'products.reorder_level',
                                                        )}
                                                    />
                                                </div>
                                            </Field>
                                        ) : (
                                            <p className="self-end text-[11px] text-muted-foreground">
                                                {t('products.service_hint')}
                                            </p>
                                        )}
                                    </div>
                                    {showOpening ? (
                                        <Field
                                            id="product-opening_qty"
                                            label={t('products.opening_stock')}
                                            error={err('opening_qty')}
                                        >
                                            <div className="mt-1">
                                                <QtyStepper
                                                    id="product-opening_qty"
                                                    value={f.opening_qty}
                                                    onChange={(v) =>
                                                        set('opening_qty', v)
                                                    }
                                                    aria-label={t(
                                                        'products.opening_stock',
                                                    )}
                                                />
                                            </div>
                                            <p className="mt-1 text-[11px] text-muted-foreground">
                                                {t('products.opening_stock_hint')}
                                            </p>
                                        </Field>
                                    ) : null}
                                    <div className="flex flex-wrap gap-x-4 gap-y-2 text-xs">
                                        {f.type === 'standard' && !raw ? (
                                            <label
                                                htmlFor="product-track_stock"
                                                className="flex items-center gap-2 [@media(pointer:coarse)]:min-h-11"
                                            >
                                                <Checkbox
                                                    id="product-track_stock"
                                                    checked={f.track_stock}
                                                    onCheckedChange={(v) =>
                                                        set(
                                                            'track_stock',
                                                            v === true,
                                                        )
                                                    }
                                                />
                                                {t('products.track_stock')}
                                            </label>
                                        ) : null}
                                        <label
                                            htmlFor="product-is_active"
                                            className="flex items-center gap-2 [@media(pointer:coarse)]:min-h-11"
                                        >
                                            <Checkbox
                                                id="product-is_active"
                                                checked={f.is_active}
                                                onCheckedChange={(v) =>
                                                    set('is_active', v === true)
                                                }
                                            />
                                            {t('products.status_active')}
                                        </label>
                                    </div>
                                </Panel>
                            </div>

                            {withVariants ? (
                                <div className="space-y-3 rounded-xl border bg-surface p-4 shadow-xs">
                                    <h3 className="text-xs font-bold text-foreground">
                                        {t('products.variant_grid')}
                                    </h3>
                                    <VariantBuilder
                                        layout="grid"
                                        options={variantOptions}
                                        onOptionsChange={setVariantOptions}
                                        draft={draft}
                                        onChange={setDraft}
                                        familyCode={f.sku}
                                        canViewCost={canViewCost}
                                    />
                                    {err('variants') || err('variants.rows') ? (
                                        <p
                                            role="alert"
                                            className="text-xs font-semibold text-destructive"
                                        >
                                            {err('variants') ||
                                                err('variants.rows')}
                                        </p>
                                    ) : null}
                                </div>
                            ) : null}
                        </DialogBody>
                        <DialogActions
                            hints={
                                <>
                                    <span className="flex items-center gap-1">
                                        <Kbd keys="mod+enter" />
                                        {t('common.save')}
                                    </span>
                                    <span className="flex items-center gap-1">
                                        <Kbd keys="escape" />{' '}
                                        {t('common.close')}
                                    </span>
                                </>
                            }
                        >
                            <Button
                                type="button"
                                variant="outline"
                                onClick={requestClose}
                            >
                                {t('common.cancel')}
                            </Button>
                            <Button type="submit" disabled={processing}>
                                <Check aria-hidden="true" />
                                {t('products.save_product')}
                            </Button>
                        </DialogActions>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={confirmClose}
                onOpenChange={setConfirmClose}
                title={t('products.discard_title')}
                description={t('products.discard_hint')}
                confirmLabel={t('products.discard')}
                onConfirm={onClose}
            />

            <InlineBrandDialog
                open={brandOpen}
                onOpenChange={setBrandOpen}
                onCreated={(brand) => {
                    setBrands((prev) =>
                        prev.some((b) => b.id === brand.id)
                            ? prev
                            : [...prev, brand].sort((x, y) =>
                                  x.name.localeCompare(y.name),
                              ),
                    );
                    set('brand_id', brand.id);
                    window.setTimeout(
                        () =>
                            document
                                .getElementById('product-brand_id')
                                ?.focus(),
                        0,
                    );
                }}
            />

            {editing && families.length > 0 ? (
                <GroupDialog
                    open={groupOpen}
                    onOpenChange={setGroupOpen}
                    families={families}
                    productId={product.id}
                />
            ) : null}
        </>
    );
}

/** Mockup form panel: rounded-xl surface card, 12 px bold heading with an icon and a rule under it. */
function Panel({
    icon,
    title,
    badge,
    children,
}: {
    icon: ReactNode;
    title: string;
    badge?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="space-y-3 rounded-xl border bg-surface p-4 shadow-xs">
            <h3 className="flex items-center justify-between border-b pb-2 text-xs font-bold text-foreground">
                <span className="flex items-center gap-1.5">
                    <span aria-hidden="true">{icon}</span>
                    {title}
                </span>
                {badge}
            </h3>
            {children}
        </section>
    );
}

function Field({
    id,
    label,
    error,
    hint,
    children,
}: {
    id: string;
    label: string;
    error?: string | undefined;
    hint?: string | undefined;
    children: ReactNode;
}) {
    return (
        <div>
            <label htmlFor={id} className={labelClass}>
                {label}
            </label>
            {children}
            {error ? (
                <p
                    role="alert"
                    className="mt-1 flex items-center gap-1 text-[10px] font-semibold text-destructive"
                >
                    <AlertCircle className="size-2.75" aria-hidden="true" />
                    <span>{error}</span>
                </p>
            ) : hint ? (
                <p className="mt-1 text-[10px] text-muted-foreground">{hint}</p>
            ) : null}
        </div>
    );
}

const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
const IMAGE_MAX_MB = 4;

/** Compact photo picker: thumbnail (or placeholder), "Choose photo", remove. Type/size are checked here and again on the server. */
function ImageField({
    current,
    reused,
    chosen,
    error,
    onChoose,
    onReuse,
    onRemove,
}: {
    current: string | null;
    /** `current` is a photo picked from the tenant's library (not saved yet). */
    reused: boolean;
    chosen: File | null;
    error?: string | undefined;
    onChoose: (file: File | null) => void;
    onReuse: (image: LibraryImage) => void;
    onRemove: () => void;
}) {
    const { t } = useT();
    const input = useRef<HTMLInputElement>(null);
    const [localError, setLocalError] = useState<string | null>(null);
    const [library, setLibrary] = useState(false);
    const preview = useMemo(
        () => (chosen ? URL.createObjectURL(chosen) : null),
        [chosen],
    );
    useEffect(
        () => () => {
            if (preview) {
                URL.revokeObjectURL(preview);
            }
        },
        [preview],
    );

    const take = (file: File | undefined) => {
        if (!file) {
            return;
        }
        if (!IMAGE_TYPES.includes(file.type)) {
            setLocalError(t('upload.bad_type', { name: file.name }));

            return;
        }
        if (file.size > IMAGE_MAX_MB * 1024 * 1024) {
            setLocalError(
                t('upload.too_big', { name: file.name, mb: IMAGE_MAX_MB }),
            );

            return;
        }
        setLocalError(null);
        onChoose(file);
    };
    const shown = preview ?? current;
    const message = localError ?? error;

    return (
        <div>
            <label htmlFor="product-image" className={labelClass}>
                {t('products.photo')}
            </label>
            <div className="mt-1 flex items-center gap-3">
                {shown ? (
                    <img
                        src={shown}
                        alt=""
                        className="size-12 shrink-0 rounded-lg border object-cover"
                    />
                ) : (
                    <span className="flex size-12 shrink-0 items-center justify-center rounded-lg border bg-surface-sunken text-muted-foreground">
                        <ImageIcon className="size-5" aria-hidden="true" />
                    </span>
                )}
                <input
                    ref={input}
                    id="product-image"
                    type="file"
                    accept={IMAGE_TYPES.join(',')}
                    className="sr-only"
                    onChange={(e) => take(e.target.files?.[0])}
                />
                <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                    <button
                        type="button"
                        onClick={() => input.current?.click()}
                        className="flex h-8 items-center gap-1.5 rounded-lg border bg-surface-sunken px-3 text-xs font-semibold hover:border-primary [@media(pointer:coarse)]:min-h-11"
                    >
                        <Upload className="size-3.5" aria-hidden="true" />
                        {t('products.photo_pick')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setLibrary(true)}
                        className="flex h-8 items-center gap-1.5 rounded-lg border bg-surface-sunken px-3 text-xs font-semibold hover:border-primary [@media(pointer:coarse)]:min-h-11"
                    >
                        <Images className="size-3.5" aria-hidden="true" />
                        {t('products.photo_reuse')}
                    </button>
                    {chosen ? (
                        <>
                            <span className="truncate text-[11px] text-muted-foreground">
                                {chosen.name}
                            </span>
                            <button
                                type="button"
                                onClick={() => {
                                    onChoose(null);
                                    if (input.current) {
                                        input.current.value = '';
                                    }
                                }}
                                className="text-[11px] font-semibold text-muted-foreground hover:text-foreground"
                            >
                                {t('upload.remove')}
                            </button>
                        </>
                    ) : current ? (
                        <>
                            {reused && (
                                <span className="text-[11px] text-muted-foreground">
                                    {t('products.photo_reused')}
                                </span>
                            )}
                            <button
                                type="button"
                                onClick={onRemove}
                                className="text-[11px] font-semibold text-destructive hover:underline"
                            >
                                {reused
                                    ? t('upload.remove')
                                    : t('products.remove_image')}
                            </button>
                        </>
                    ) : (
                        <span className="text-[10px] text-muted-foreground">
                            {t('products.photo_hint')}
                        </span>
                    )}
                </div>
            </div>
            {message ? (
                <p
                    role="alert"
                    className="mt-1 flex items-center gap-1 text-[10px] font-semibold text-destructive"
                >
                    <AlertCircle className="size-2.75" aria-hidden="true" />
                    <span>{message}</span>
                </p>
            ) : null}
            <ImagePickerDialog
                open={library}
                onOpenChange={setLibrary}
                onPick={(image) => {
                    setLocalError(null);
                    if (input.current) {
                        input.current.value = '';
                    }
                    onReuse(image);
                }}
            />
        </div>
    );
}
