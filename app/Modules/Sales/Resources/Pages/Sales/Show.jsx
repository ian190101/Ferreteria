import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { assetUrl } from '@/Utils/assets';
import FormField from '../../../../Shared/Resources/Components/FormField';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { promptAction } from '@/Utils/alerts';
import { useEffect, useRef, useState } from 'react';

const PAPER_SIZES = {
    letter: { width: '216mm', minHeight: '279mm', page: 'letter' },
    half_letter: { width: '216mm', minHeight: '140mm', page: '216mm 140mm' },
    legal: { width: '216mm', minHeight: '356mm', page: 'legal' },
    half_legal: { width: '216mm', minHeight: '178mm', page: '216mm 178mm' },
    full_page: { width: '210mm', minHeight: '297mm', page: 'A4' },
    thermal: { width: null, minHeight: null, page: 'auto' },
};

const DEFAULT_ITEM_COLUMNS = [
    { key: 'item_number', label: 'N', show: true, align: 'left' },
    { key: 'item_description', label: 'Descripcion', show: true, align: 'left' },
    { key: 'item_lot', label: 'Lote', show: false, align: 'left' },
    { key: 'item_model', label: 'Modelo', show: true, align: 'left' },
    { key: 'item_unit', label: 'Und.', show: true, align: 'right' },
    { key: 'item_quantity', label: 'Cant.', show: true, align: 'right' },
    { key: 'item_base', label: 'Base', show: true, align: 'right' },
    { key: 'item_price', label: 'Precio', show: true, align: 'right' },
    { key: 'item_subtotal', label: 'Subtotal', show: true, align: 'right' },
];

export default function Show({ sale, template, paymentMethods = [], conversionReadiness = { can_convert: false, issues: [], items: [] } }) {
    const documentTitle = sale.document_type === 'quotation' ? 'COTIZACION' : 'NOTA DE VENTA';
    const page = usePage();
    const permissions = page.props.auth.permissions;
    const errors = page.props.errors ?? {};
    const canManagePayments = permissions.includes('payments.manage');
    const canManageSales = permissions.includes('sales.manage');
    const canConvertQuotation = canManageSales && sale.document_type === 'quotation' && sale.status === 'quoted' && (conversionReadiness?.can_convert ?? false);
    const currency = sale.currency ?? { symbol: 'Bs', code: 'BOB' };
    const branch = sale.branch ?? {};
    const layout = template.layout;
    const fields = layout.fields ?? {};
    const sections = [...(layout.sections ?? [])]
        .filter((section) => section.show)
        .sort((left, right) => left.order - right.order);
    const paper = PAPER_SIZES[template.paper_type] ?? PAPER_SIZES.letter;
    const paperWidth = template.paper_type === 'thermal' ? `${template.thermal_width_mm ?? 80}mm` : paper.width;
    const paperMinHeight = template.paper_type === 'thermal' ? null : paper.minHeight;
    const primary = layout.colors.primary;
    const secondary = layout.colors.secondary;
    const logoPath = template.use_branding ? branch.setting?.logo_path : layout.logo?.path;
    const previewShellRef = useRef(null);
    const previewPaperRef = useRef(null);
    const [previewFrame, setPreviewFrame] = useState({ scale: 1, width: null, height: null });
    const paymentForm = useForm({
        sale_id: sale.id,
        payment_method_id: paymentMethods[0]?.id ?? '',
        paid_at: '',
        amount: sale.balance_due ?? '',
        reference: '',
        notes: '',
    });

    useEffect(() => {
        paymentForm.setData({
            sale_id: sale.id,
            payment_method_id: paymentMethods[0]?.id ?? '',
            paid_at: '',
            amount: sale.balance_due ?? '',
            reference: '',
            notes: '',
        });
        paymentForm.clearErrors();
    }, [sale.id]);

    useEffect(() => {
        if (!paymentForm.data.payment_method_id && paymentMethods[0]?.id) {
            paymentForm.setData('payment_method_id', paymentMethods[0].id);
        }
    }, [paymentMethods]);

    useEffect(() => {
        const shell = previewShellRef.current;
        const paperElement = previewPaperRef.current;

        if (!shell || !paperElement) {
            return undefined;
        }

        const updatePreviewSize = () => {
            const availableWidth = shell.clientWidth;
            const paperWidthPx = paperElement.offsetWidth;
            const paperHeightPx = paperElement.offsetHeight;
            const nextScale = paperWidthPx > 0 ? Math.min(1, availableWidth / paperWidthPx) : 1;

            setPreviewFrame({
                scale: nextScale,
                width: paperWidthPx ? paperWidthPx * nextScale : null,
                height: paperHeightPx ? paperHeightPx * nextScale : null,
            });
        };

        updatePreviewSize();

        const observer = new ResizeObserver(updatePreviewSize);
        observer.observe(shell);
        observer.observe(paperElement);

        window.addEventListener('orientationchange', updatePreviewSize);

        return () => {
            observer.disconnect();
            window.removeEventListener('orientationchange', updatePreviewSize);
        };
    }, [paperWidth, paperMinHeight, layout.margin_mm, layout.font_family, layout.font_size, sale.items.length, sections.length]);

    const submitPayment = (event) => {
        event.preventDefault();
        paymentForm.post(route('payments.store'), { preserveScroll: true });
    };

    const renderSection = (section) => {
        const props = { sale, branch, currency, documentTitle, fields, primary, secondary, layout, logoPath };

        return {
            header: <HeaderSection key="header" {...props} />,
            document: <DocumentSection key="document" {...props} />,
            customer: <CustomerSection key="customer" {...props} />,
            items: <ItemsSection key="items" {...props} />,
            totals: <TotalsSection key="totals" {...props} />,
            terms: <TermsSection key="terms" {...props} />,
        }[section.key] ?? null;
    };

    return (
        <AuthenticatedLayout header={<h2 className="print:hidden text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Ventas</h2>}>
            <Head title={`${documentTitle} ${sale.receipt_number}`} />

            <style>{`
                @media print {
                    body { background: #fff !important; }
                    nav, header, .print-hidden { display: none !important; }
                    main { padding: 0 !important; }
                    .ticket-preview-shell, .ticket-preview-stage { display: contents !important; width: auto !important; height: auto !important; overflow: visible !important; }
                    .ticket-paper { box-shadow: none !important; margin: 0 auto !important; transform: none !important; }
                    @page { margin: ${layout.margin_mm ?? 8}mm; size: ${paper.page}; }
                }
            `}</style>

            <section className="mx-auto max-w-7xl px-4 py-8 print:px-0 print:py-0">
                <div className="print-hidden mb-4 flex flex-wrap gap-3">
                    <button onClick={() => window.print()} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white">Imprimir</button>
                    {canManageSales && sale.document_type === 'quotation' && sale.status === 'quoted' ? (
                        <button
                            onClick={() => convertQuotation(sale)}
                            disabled={!canConvertQuotation}
                            className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-400"
                            type="button"
                            title={canConvertQuotation ? 'Convertir a nota de venta' : 'No hay stock libre suficiente para convertir'}
                        >
                            Convertir a nota
                        </button>
                    ) : null}
                    <Link href={route('sales.templates.index')} className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700">Editar plantillas</Link>
                    <Link href={route('sales.index')} className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700">Volver</Link>
                </div>

                {sale.status === 'void' ? (
                    <div className="print-hidden mx-auto mb-4 max-w-4xl rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                        Documento anulado. No debe usarse como comprobante vigente.
                    </div>
                ) : null}

                {Object.keys(errors).length > 0 ? (
                    <div className="print-hidden mx-auto mb-4 max-w-4xl rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/70 dark:bg-red-950/30 dark:text-red-100">
                        <p className="font-semibold">No se pudo completar la accion.</p>
                        <ul className="mt-2 list-disc space-y-1 pl-5">
                            {Object.entries(errors).map(([key, message]) => (
                                <li key={key}>{message}</li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {sale.document_type === 'quotation' && sale.status === 'quoted' && conversionReadiness?.items?.length ? (
                    <section className="print-hidden mx-auto mb-4 max-w-4xl rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 className="font-semibold text-slate-950 dark:text-white">Validacion para convertir</h3>
                                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                    La nota de venta descuenta inventario al generarse. Primero debe existir stock libre suficiente en la sucursal del documento.
                                </p>
                            </div>
                            <span className={[
                                'rounded-full px-3 py-1 text-xs font-semibold',
                                conversionReadiness.can_convert
                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200'
                                    : 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200',
                            ].join(' ')}>
                                {conversionReadiness.can_convert ? 'Lista para convertir' : 'Falta stock'}
                            </span>
                        </div>
                        <div className="mt-4 overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                                <thead className="text-left text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th className="px-3 py-2">Item</th>
                                        <th className="px-3 py-2 text-right">Requerido</th>
                                        <th className="px-3 py-2 text-right">Disponible</th>
                                        <th className="px-3 py-2 text-right">Libre</th>
                                        <th className="px-3 py-2">Estado</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {conversionReadiness.items.map((item) => (
                                        <tr key={item.item_id}>
                                            <td className="px-3 py-2 font-medium text-slate-900 dark:text-slate-100">{item.description}</td>
                                            <td className="px-3 py-2 text-right">{item.required_label ?? `${item.required_meters} m`}</td>
                                            <td className="px-3 py-2 text-right">{item.available_label ?? `${item.available_meters} m`}</td>
                                            <td className="px-3 py-2 text-right">{item.free_label ?? `${item.free_meters} m`}</td>
                                            <td className="px-3 py-2">
                                                {item.can_convert ? (
                                                    <span className="text-emerald-600 dark:text-emerald-300">Correcto</span>
                                                ) : (
                                                    <div className="flex flex-col gap-1">
                                                        <span className="text-amber-700 dark:text-amber-300">{item.message}</span>
                                                        {item.action ? (
                                                            <Link href={item.action.url} className="text-xs font-semibold text-brand-primary hover:underline">
                                                                {item.action.label}
                                                            </Link>
                                                        ) : null}
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ) : null}

                {sale.document_type === 'sale_note' ? (
                    <DeliveryProgress sale={sale} />
                ) : null}

                <div ref={previewShellRef} className="ticket-preview-shell mx-auto max-w-full overflow-hidden print:contents">
                    <div
                        className="ticket-preview-stage mx-auto print:contents"
                        style={{
                            width: previewFrame.width ? `${previewFrame.width}px` : paperWidth,
                            height: previewFrame.height ? `${previewFrame.height}px` : 'auto',
                        }}
                    >
                        <article
                            ref={previewPaperRef}
                            className="ticket-paper origin-top-left bg-white text-black shadow-lg"
                            style={{
                                width: paperWidth,
                                minHeight: paperMinHeight ?? undefined,
                                padding: `${layout.margin_mm ?? 8}mm`,
                                fontFamily: layout.font_family,
                                fontSize: `${layout.font_size}px`,
                                lineHeight: 1.18,
                                transform: `scale(${previewFrame.scale})`,
                            }}
                        >
                            {sale.status === 'void' ? (
                                <div className="mb-3 border-2 border-red-600 py-2 text-center text-lg font-bold text-red-700">
                                    ANULADO
                                </div>
                            ) : null}
                            {sections.map(renderSection)}
                        </article>
                    </div>
                </div>

                <div className="print-hidden mx-auto mt-6 grid max-w-4xl gap-6 lg:grid-cols-[1fr_1fr]">
                    <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="mb-4 font-semibold text-slate-900 dark:text-slate-100">Pagos registrados</h3>
                        <div className="space-y-3">
                            {sale.payments.length === 0 ? (
                                <p className="text-sm text-slate-500">Sin pagos registrados.</p>
                            ) : sale.payments.map((payment) => (
                                <div key={payment.id} className="flex justify-between gap-4 border-b border-slate-100 pb-2 text-sm dark:border-slate-800">
                                    <div>
                                        <p className="font-medium">{payment.method?.name ?? '-'}</p>
                                        <p className="text-xs text-slate-500">{new Date(payment.paid_at).toLocaleString('es-BO')}</p>
                                    </div>
                                    <p className="font-semibold">{currency.symbol} {payment.amount}</p>
                                </div>
                            ))}
                        </div>
                    </section>

                    {canManagePayments && sale.document_type === 'sale_note' && Number(sale.balance_due) > 0 ? (
                        <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <h3 className="mb-4 font-semibold text-slate-900 dark:text-slate-100">Registrar pago</h3>
                            <form onSubmit={submitPayment} className="grid gap-4">
                                <SelectField label="Metodo" name="payment_method_id" value={paymentForm.data.payment_method_id} onChange={(event) => paymentForm.setData('payment_method_id', event.target.value)} error={paymentForm.errors.payment_method_id} required>
                                    {!paymentMethods.length ? <option value="">Cargando metodos...</option> : null}
                                    {paymentMethods.map((method) => <option key={method.id} value={method.id}>{method.name}</option>)}
                                </SelectField>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField label="Fecha" name="paid_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" />
                                    <FormField label="Monto" name="amount" type="number" step="0.01" min="0.01" max={sale.balance_due} value={paymentForm.data.amount} onChange={(event) => paymentForm.setData('amount', event.target.value)} error={paymentForm.errors.amount} required />
                                </div>
                                <FormField label="Referencia" name="reference" value={paymentForm.data.reference} onChange={(event) => paymentForm.setData('reference', event.target.value)} error={paymentForm.errors.reference} />
                                <button disabled={paymentForm.processing || !paymentMethods.length} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60" type="submit">
                                    Guardar pago
                                </button>
                            </form>
                        </section>
                    ) : null}
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function HeaderSection({ branch, fields, layout, primary, logoPath }) {
    const logo = layout.logo ?? {};
    const logoSrc = assetUrl(logoPath);

    return (
        <section className="grid grid-cols-2 gap-6" style={{ color: primary }}>
            <div className="text-left">
                {logo.show && logoSrc ? (
                    <img src={logoSrc} alt="Logo" className="mb-2 object-contain" style={{ width: `${logo.width_mm ?? 28}mm` }} />
                ) : null}
                {fields.branch_name ? <h1 className="text-base font-bold uppercase">{branch.name ?? 'FABRICA DE CALAMINAS'}</h1> : null}
                {fields.branch_address ? <p>{branch.address}</p> : null}
                {fields.branch_phone ? <p>Tel.: {branch.phone}</p> : null}
                {fields.branch_secondary_phone ? <p>Cel.: {branch.secondary_phone}</p> : null}
            </div>
            <div className="text-right">
                <p>DOCUMENTO SIN VALOR FISCAL</p>
                <p>*** Exija su factura ***</p>
                <p className="font-bold">NOTA DE VENTA</p>
            </div>
        </section>
    );
}

function DocumentSection({ sale, documentTitle, fields, secondary }) {
    return (
        <section className="mt-1 text-right" style={{ color: secondary }}>
            {fields.document_title ? <p className="font-bold">{documentTitle}</p> : null}
            {fields.receipt_number ? <p>Nro.: {sale.receipt_number}</p> : null}
            {fields.date ? <p>{new Date(sale.sold_at).toLocaleDateString('es-BO')}</p> : null}
        </section>
    );
}

function CustomerSection({ sale, branch, currency, fields }) {
    return (
        <section className="mt-3 grid grid-cols-2 gap-x-8 gap-y-1 border-t border-black pt-2">
            {fields.date ? <p><span className="font-bold">Fecha:</span> {new Date(sale.sold_at).toLocaleDateString('es-BO')}</p> : null}
            {fields.currency ? <p><span className="font-bold">Moneda:</span> {currency.name}</p> : null}
            {fields.seller ? <p><span className="font-bold">Vendedor:</span> {sale.user?.name}</p> : null}
            {fields.point_of_sale ? <p><span className="font-bold">Punto de venta:</span> {branch.point_of_sale_name}</p> : null}
            {fields.customer ? <p><span className="font-bold">Cliente:</span> {sale.customer_document} {sale.customer_name}</p> : null}
            {fields.sale_type ? <p><span className="font-bold">Tipo:</span> {sale.sale_type?.name}</p> : null}
            {fields.customer_contact ? <p><span className="font-bold">Contacto:</span> {sale.customer_contact}</p> : null}
            {fields.exchange_rate ? <p><span className="font-bold">Cambio:</span> 1 {currency.code} = {sale.exchange_rate_to_bob} Bs</p> : null}
            <p><span className="font-bold">Plazo entrega:</span> -</p>
            <p><span className="font-bold">Observaciones:</span> {sale.internal_notes ?? '-'}</p>
            <p><span className="font-bold">Anticipo:</span> {sale.advance_amount}</p>
        </section>
    );
}

function ItemsSection({ sale, fields, layout }) {
    const columns = itemColumns(sale.items ?? [], fields, layout.item_columns ?? []);

    return (
        <table className="mt-3 w-full border-collapse text-[0.92em]">
            <thead>
                <tr className="border-y border-black">
                    {columns.map((column) => (
                        <th key={column.key} className={`py-1 ${column.align === 'right' ? 'text-right' : 'text-left'}`}>{column.label}</th>
                    ))}
                </tr>
            </thead>
            <tbody>
                {sale.items.map((item, index) => (
                    <tr key={item.id ?? index} className="align-top">
                        {columns.map((column) => (
                            <td key={column.key} className={`py-1 ${column.align === 'right' ? 'text-right' : 'text-left'}`}>
                                {itemColumnValue(column.key, item, index)}
                            </td>
                        ))}
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

function itemColumns(items, fields, configuredColumns) {
    const staticColumns = DEFAULT_ITEM_COLUMNS.map((column, index) => ({ ...column, order: index + 1 }));
    const attributeColumns = itemAttributeColumns(items, fields).map((attribute, index) => ({
        key: `item_attribute_${attribute.code}`,
        label: attribute.name,
        show: true,
        align: 'left',
        order: staticColumns.length + index + 1,
    }));
    const saved = new Map((configuredColumns ?? []).map((column) => [column.key, column]));

    return [...staticColumns, ...attributeColumns]
        .map((column) => {
            const savedColumn = saved.get(column.key) ?? {};

            return {
                ...column,
                label: savedColumn.label || column.label,
                show: Object.hasOwn(savedColumn, 'show') ? Boolean(savedColumn.show) : fieldEnabled(fields, column.key),
                order: Number(savedColumn.order ?? column.order),
            };
        })
        .filter((column) => column.show)
        .sort((left, right) => left.order - right.order);
}

function itemAttributeColumns(items, fields) {
    const columns = new Map();

    items.forEach((item) => {
        (item.item_attributes ?? []).forEach((attribute) => {
            if (!columns.has(attribute.code) && fieldEnabled(fields, `item_attribute_${attribute.code}`)) {
                columns.set(attribute.code, {
                    code: attribute.code,
                    name: printableAttributeName(attribute.name, attribute.unit),
                });
            }
        });
    });

    return [...columns.values()];
}

function itemColumnValue(key, item, index) {
    if (key.startsWith('item_attribute_')) {
        return itemAttributeValue(item, key.replace('item_attribute_', ''));
    }

    const values = {
        item_number: index + 1,
        item_description: <p className="font-bold">{item.description}</p>,
        item_lot: item.coil?.lot_number ?? '-',
        item_model: item.product?.sku ?? '-',
        item_unit: item.display_unit_label ?? item.unit_label,
        item_quantity: item.display_quantity ?? '1.000',
        item_base: (item.calculation_mode ?? 'direct') === 'direct' ? '-' : item.meters,
        item_price: item.unit_price,
        item_subtotal: item.total,
    };

    return values[key] ?? '-';
}

function fieldEnabled(fields, field) {
    if (Object.hasOwn(fields ?? {}, field)) {
        return Boolean(fields[field]);
    }

    return field !== 'item_lot';
}

function printableAttributeName(name, unit) {
    const label = String(name ?? '').replace(/\s+util$/i, '').trim();

    return unit ? `${label} (${unit})` : label;
}

function itemAttributeValue(item, code) {
    const attribute = (item.item_attributes ?? []).find((entry) => entry.code === code);

    return attribute?.value ? attribute.value : '-';
}

function DeliveryProgress({ sale }) {
    const rows = (sale.items ?? []).map((item) => {
        const deliveries = item.delivery_items ?? item.deliveryItems ?? [];
        const delivered = deliveries.reduce((sum, deliveryItem) => sum + Number(deliveryItem.display_quantity || deliveryItem.meters || 0), 0);
        const total = Number(item.display_quantity || item.meters || 0);
        const pending = Math.max(total - delivered, 0);

        return {
            id: item.id,
            description: item.description,
            unit: item.display_unit_label ?? item.unit_label ?? '',
            total,
            delivered,
            pending,
            completed: pending <= 0,
        };
    });
    const completed = rows.length > 0 && rows.every((row) => row.completed);

    return (
        <section className="print-hidden mx-auto mb-4 max-w-4xl rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 className="font-semibold text-slate-950 dark:text-white">Estado de despacho</h3>
                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Seguimiento de entrega por producto de esta nota de venta.</p>
                </div>
                <span className={`rounded-full px-3 py-1 text-xs font-semibold ${completed ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200'}`}>
                    {completed ? 'Todo entregado' : 'Entrega parcial'}
                </span>
            </div>
            <div className="mt-4 grid gap-2">
                {rows.map((row) => (
                    <div key={row.id} className="rounded-lg border border-slate-100 px-3 py-2 text-sm dark:border-slate-800">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="font-medium text-slate-900 dark:text-slate-100">{row.description}</span>
                            <span className={row.completed ? 'text-emerald-600 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'}>
                                {row.completed ? 'Entregado completo' : 'Pendiente'}
                            </span>
                        </div>
                        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Total: {formatDeliveryQuantity(row.total, row.unit)} | Entregado: {formatDeliveryQuantity(row.delivered, row.unit)} | Pendiente: {formatDeliveryQuantity(row.pending, row.unit)}
                        </p>
                    </div>
                ))}
            </div>
        </section>
    );
}

function formatDeliveryQuantity(value, unit) {
    return `${Number(value ?? 0).toLocaleString('es-BO', { maximumFractionDigits: 3 })} ${unit ?? ''}`.trim();
}

function TotalsSection({ sale, currency, fields }) {
    return (
        <section className="mt-2 border-t border-black pt-2">
            {fields.subtotal ? <TotalLine label="Subtotal" value={sale.subtotal} /> : null}
            {fields.discount ? <TotalLine label="Descuento" value={sale.discount_total} /> : null}
            <TotalLine label="Total" value={`${currency.symbol} ${sale.total}`} strong />
            {fields.advance ? <TotalLine label={advanceLabel(sale)} value={sale.advance_amount} /> : null}
            {fields.balance_due ? <TotalLine label="Saldo por pagar" value={sale.balance_due} /> : null}
            <p className="mt-2 font-bold">Son: {amountToLiteral(Number(sale.total ?? 0), currency.name ?? currency.code)}</p>
        </section>
    );
}

function TotalLine({ label, value, strong = false }) {
    return (
        <div className={`grid grid-cols-[1fr_auto] gap-4 ${strong ? 'font-bold' : ''}`}>
            <span className="text-right">{label}</span>
            <span className="text-right">{value}</span>
        </div>
    );
}

function advanceLabel(sale) {
    if (sale.advanceOption?.type === 'amount' || sale.advance_option?.type === 'amount') {
        return 'Anticipo';
    }

    return `Anticipo ${sale.advance_percentage}%`;
}

function TermsSection({ sale }) {
    return (
        <section className="mt-4 whitespace-pre-line border-t border-black pt-2">
            {sale.terms ? `${sale.terms}\n` : ''}
            NOTA: NO SE ACEPTAN CAMBIOS NI DEVOLUCIONES.
        </section>
    );
}

function amountToLiteral(amount, currencyName) {
    const integer = Math.floor(Math.abs(amount));
    const cents = Math.round((Math.abs(amount) - integer) * 100);
    const currency = normalizeCurrency(currencyName);

    return `${numberToWords(integer).toUpperCase()} ${String(cents).padStart(2, '0')}/100 ${currency}`;
}

function normalizeCurrency(value) {
    const text = String(value ?? 'BOLIVIANOS').toUpperCase();

    if (text.includes('DOLAR') || text.includes('USD')) {
        return 'DOLARES';
    }

    return 'BOLIVIANOS';
}

function numberToWords(value) {
    if (value === 0) {
        return 'cero';
    }

    const units = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciseis', 'diecisiete', 'dieciocho', 'diecinueve'];
    const tens = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
    const hundreds = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

    const belowThousand = (number) => {
        if (number === 0) return '';
        if (number === 100) return 'cien';
        if (number < 20) return units[number];
        if (number < 30) return number === 20 ? 'veinte' : `veinti${units[number - 20]}`;
        if (number < 100) return `${tens[Math.floor(number / 10)]}${number % 10 ? ` y ${units[number % 10]}` : ''}`;
        return `${hundreds[Math.floor(number / 100)]}${number % 100 ? ` ${belowThousand(number % 100)}` : ''}`;
    };

    if (value < 1000) return belowThousand(value);
    if (value < 1000000) {
        const thousands = Math.floor(value / 1000);
        const rest = value % 1000;
        return `${thousands === 1 ? 'mil' : `${belowThousand(thousands)} mil`}${rest ? ` ${belowThousand(rest)}` : ''}`;
    }

    const millions = Math.floor(value / 1000000);
    const rest = value % 1000000;
    return `${millions === 1 ? 'un millon' : `${numberToWords(millions)} millones`}${rest ? ` ${numberToWords(rest)}` : ''}`;
}

async function convertQuotation(sale) {
    const receiptNumber = await promptAction({
        title: 'Convertir cotizacion',
        text: `Numero de nota de venta para ${sale.receipt_number}. Deja vacio para automatico.`,
        inputLabel: 'Numero de nota de venta',
        confirmButtonText: 'Convertir',
        placeholder: 'Dejar vacio para automatico',
        required: false,
    });

    if (receiptNumber === null) {
        return;
    }

    router.post(route('sales.convert', sale.id), {
        receipt_number: receiptNumber,
        sold_at: null,
    }, {
        preserveScroll: true,
        preserveState: false,
    });
}
