import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, Link, router, useForm } from '@inertiajs/react';

export default function BarcodeLabel({ product, template, templates = [], branches = [], quantity = 1, barcodeSvg }) {
    const form = useForm({
        branch_id: template.branch_id ?? '',
        name: template.name,
        paper_type: template.paper_type,
        label_width_mm: template.label_width_mm,
        label_height_mm: template.label_height_mm,
        margin_mm: template.margin_mm,
        barcode_height_mm: template.barcode_height_mm,
        font_size: template.font_size,
        show_product_name: Boolean(template.show_product_name),
        show_sku: Boolean(template.show_sku),
        show_price: Boolean(template.show_price),
        is_default: Boolean(template.is_default),
        is_active: Boolean(template.is_active),
    });

    const submit = (event) => {
        event.preventDefault();
        form.put(route('inventory.barcode-label-templates.update', template.id), { preserveScroll: true });
    };

    const labelStyle = {
        width: `${form.data.label_width_mm}mm`,
        minHeight: `${form.data.label_height_mm}mm`,
        padding: `${form.data.margin_mm}mm`,
        fontSize: `${form.data.font_size}px`,
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-800 dark:text-slate-200">Inventario</h2>}>
            <Head title="Etiqueta barcode" />
            <style>{`
                @media print {
                    body * { visibility: hidden !important; }
                    .barcode-print-area, .barcode-print-area * { visibility: visible !important; }
                    .barcode-print-area { position: absolute; inset: 0 auto auto 0; padding: 0; background: white; }
                    .barcode-label { page-break-inside: avoid; break-inside: avoid; }
                }
            `}</style>
            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                    <ModuleHeader title="Imprimir codigo de barras" description="Configura la etiqueta del producto, revisa la vista previa e imprime la cantidad necesaria." />
                    <Link href={route('inventory.products.index')} className="rounded-full border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200">Volver</Link>
                </div>

                <div className="grid gap-6 lg:grid-cols-[0.9fr_1.1fr]">
                    <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="text-lg font-bold text-slate-950 dark:text-white">Formato de etiqueta</h3>
                        <p className="mt-1 text-sm text-slate-500">Estos ajustes funcionan como una plantilla: tamano, papel, margen y datos visibles.</p>
                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <SelectField label="Plantilla" name="template_id" value={template.id} onChange={(event) => router.get(route('inventory.products.barcode-label.show', product.id), { template_id: event.target.value, quantity }, { preserveState: true })}>
                                {templates.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                            </SelectField>
                            <SelectField label="Sucursal" name="branch_id" value={form.data.branch_id} onChange={(event) => form.setData('branch_id', event.target.value)}>
                                <option value="">Global</option>
                                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                            </SelectField>
                            <FormField label="Nombre plantilla" name="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} required />
                            <SelectField label="Tipo de papel" name="paper_type" value={form.data.paper_type} onChange={(event) => form.setData('paper_type', event.target.value)} helpTooltip="Solo cambia la referencia del formato; las medidas exactas se controlan con ancho y alto.">
                                <option value="label_50x30">Etiqueta 50x30 mm</option>
                                <option value="label_60x40">Etiqueta 60x40 mm</option>
                                <option value="letter_grid">Hoja carta con varias etiquetas</option>
                                <option value="thermal">Termica</option>
                            </SelectField>
                            <FormField label="Ancho mm" name="label_width_mm" type="number" min="20" max="210" value={form.data.label_width_mm} onChange={(event) => form.setData('label_width_mm', event.target.value)} error={form.errors.label_width_mm} required />
                            <FormField label="Alto mm" name="label_height_mm" type="number" min="10" max="297" value={form.data.label_height_mm} onChange={(event) => form.setData('label_height_mm', event.target.value)} error={form.errors.label_height_mm} required />
                            <FormField label="Margen mm" name="margin_mm" type="number" min="0" max="20" value={form.data.margin_mm} onChange={(event) => form.setData('margin_mm', event.target.value)} error={form.errors.margin_mm} required />
                            <FormField label="Alto barcode mm" name="barcode_height_mm" type="number" min="8" max="120" value={form.data.barcode_height_mm} onChange={(event) => form.setData('barcode_height_mm', event.target.value)} error={form.errors.barcode_height_mm} required />
                            <FormField label="Tamano texto" name="font_size" type="number" min="6" max="24" value={form.data.font_size} onChange={(event) => form.setData('font_size', event.target.value)} error={form.errors.font_size} required />
                            <FormField label="Cantidad a imprimir" name="quantity" type="number" min="1" value={quantity} onChange={(event) => router.get(route('inventory.products.barcode-label.show', product.id), { template_id: template.id, quantity: event.target.value }, { preserveState: true })} />
                        </div>
                        <div className="mt-5 grid gap-3 sm:grid-cols-2">
                            <Check label="Mostrar producto" checked={form.data.show_product_name} onChange={(value) => form.setData('show_product_name', value)} />
                            <Check label="Mostrar SKU" checked={form.data.show_sku} onChange={(value) => form.setData('show_sku', value)} />
                            <Check label="Mostrar precio" checked={form.data.show_price} onChange={(value) => form.setData('show_price', value)} />
                            <Check label="Predeterminada" checked={form.data.is_default} onChange={(value) => form.setData('is_default', value)} />
                        </div>
                        <button className="mt-5 rounded-full bg-brand-primary px-5 py-2.5 text-sm font-semibold text-white" disabled={form.processing}>Guardar formato</button>
                    </form>

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 className="text-lg font-bold text-slate-950 dark:text-white">Vista previa</h3>
                                <p className="text-sm text-slate-500">Imprime solo cuando la vista previa sea correcta.</p>
                            </div>
                            <button type="button" onClick={() => window.print()} className="rounded-full bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white dark:bg-white dark:text-slate-950">Imprimir</button>
                        </div>
                        <div className="barcode-print-area mt-5 flex flex-wrap gap-3 print:block">
                            {Array.from({ length: Number(quantity || 1) }).map((_, index) => (
                                <div key={index} className="barcode-label flex flex-col items-center justify-center border border-dashed border-slate-300 bg-white text-center text-black print:break-inside-avoid" style={labelStyle}>
                                    {form.data.show_product_name ? <strong className="mb-1 max-w-full truncate">{product.name}</strong> : null}
                                    <div className="w-full" dangerouslySetInnerHTML={{ __html: barcodeSvg }} />
                                    <span className="mt-1 font-mono">{product.barcode}</span>
                                    {form.data.show_sku ? <span>SKU: {product.sku}</span> : null}
                                    {form.data.show_price ? <span>Bs {Number(product.sale_price ?? 0).toFixed(2)}</span> : null}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function Check({ label, checked, onChange }) {
    return (
        <label className="flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-800">
            <input type="checkbox" className="h-4 w-4 rounded border-slate-300 text-brand-primary focus:ring-brand-primary" checked={checked} onChange={(event) => onChange(event.target.checked)} />
            <span>{label}</span>
        </label>
    );
}
