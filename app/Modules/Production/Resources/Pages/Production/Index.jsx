import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { currentDateTimeLocal } from '@/Utils/dateTime';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm, usePage } from '@inertiajs/react';

const numberFormatter = new Intl.NumberFormat('es-BO', {
    maximumFractionDigits: 3,
});

export default function Index({ orders, branches, products, formulas = [], coils, filters }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('production.manage');
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        search: filters.search ?? '',
        per_page: filters.per_page ?? 15,
    });
    const productionForm = useForm({
        branch_id: branches[0]?.id ?? '',
        order_number: '',
        produced_at: currentDateTimeLocal(),
        production_formula_id: '',
        input_product_id: products[0]?.id ?? '',
        input_product_coil_id: '',
        output_product_id: products[0]?.id ?? '',
        input_meters: '',
        output_meters: '',
        waste_meters: '0',
        labor_cost: '0',
        overhead_cost: '0',
        output_coil_barcode: '',
        output_lot_number: '',
        notes: '',
    });
    const formulaForm = useForm({
        branch_id: '',
        output_product_id: products[0]?.id ?? '',
        code: '',
        name: '',
        yield_quantity: '1',
        expected_waste_percentage: '0',
        standard_labor_cost: '0',
        standard_overhead_cost: '0',
        instructions: '',
        items: [emptyFormulaItem(products[0])],
        stages: [emptyStage()],
    });

    const selectedFormula = formulas.find((formula) => String(formula.id) === String(productionForm.data.production_formula_id));
    const inputProduct = products.find((product) => String(product.id) === String(productionForm.data.input_product_id));
    const outputProduct = products.find((product) => String(product.id) === String(productionForm.data.output_product_id));
    const availableCoils = coils.filter((coil) => String(coil.branch_id) === String(productionForm.data.branch_id) && String(coil.product_id) === String(productionForm.data.input_product_id));

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('production.index'), { preserveScroll: true, preserveState: true });
    };

    const submitProduction = (event) => {
        event.preventDefault();
        productionForm.post(route('production.store'), {
            preserveScroll: true,
            onSuccess: () => productionForm.reset('order_number', 'production_formula_id', 'input_meters', 'output_meters', 'waste_meters', 'labor_cost', 'overhead_cost', 'output_coil_barcode', 'output_lot_number', 'notes'),
        });
    };
    const submitFormula = (event) => {
        event.preventDefault();
        formulaForm.post(route('production.formulas.store'), {
            preserveScroll: true,
            onSuccess: () => formulaForm.reset('code', 'name', 'yield_quantity', 'expected_waste_percentage', 'standard_labor_cost', 'standard_overhead_cost', 'instructions', 'items', 'stages'),
        });
    };
    const applyFormula = (formulaId) => {
        const formula = formulas.find((candidate) => String(candidate.id) === String(formulaId));
        productionForm.setData({
            ...productionForm.data,
            production_formula_id: formulaId,
            output_product_id: formula?.output_product_id ?? productionForm.data.output_product_id,
            input_product_id: formula?.items?.[0]?.input_product_id ?? productionForm.data.input_product_id,
            input_meters: '',
            labor_cost: formula?.standard_labor_cost ?? '0',
            overhead_cost: formula?.standard_overhead_cost ?? '0',
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Produccion</h2>}>
            <Head title="Produccion" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Produccion" description="Transformacion de material con consumo de inventario, salida de producto terminado y trazabilidad de movimientos." />

                {canManage ? (
                    <Panel title="Registrar orden terminada">
                        <form onSubmit={submitProduction} className="mb-6 grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                            <SelectField label="Sucursal" name="branch_id" value={productionForm.data.branch_id} onChange={(event) => productionForm.setData('branch_id', event.target.value)} error={productionForm.errors.branch_id} required>
                                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                            </SelectField>
                            <FormField label="Numero de orden" name="order_number" value={productionForm.data.order_number} onChange={(event) => productionForm.setData('order_number', event.target.value)} error={productionForm.errors.order_number} required />
                            <FormField label="Fecha" name="produced_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" error={productionForm.errors.produced_at} />
                            <FormField label="Merma metros" name="waste_meters" type="number" step="0.001" min="0" value={productionForm.data.waste_meters} onChange={(event) => productionForm.setData('waste_meters', event.target.value)} error={productionForm.errors.waste_meters} />
                            <SelectField label="Formula/BOM" name="production_formula_id" value={productionForm.data.production_formula_id} onChange={(event) => applyFormula(event.target.value)} error={productionForm.errors.production_formula_id}>
                                <option value="">Produccion simple</option>
                                {formulas.map((formula) => <option key={formula.id} value={formula.id}>{formula.code} - {formula.name}</option>)}
                            </SelectField>

                            <SelectField label="Producto entrada" name="input_product_id" value={productionForm.data.input_product_id} onChange={(event) => productionForm.setData('input_product_id', event.target.value)} error={productionForm.errors.input_product_id} required={!selectedFormula} disabled={Boolean(selectedFormula)}>
                                {products.map((product) => <option key={product.id} value={product.id}>{product.name} ({tracking(product.inventory_tracking_mode)})</option>)}
                            </SelectField>
                            <SelectField label="Bobina entrada" name="input_product_coil_id" value={productionForm.data.input_product_coil_id} onChange={(event) => productionForm.setData('input_product_coil_id', event.target.value)} error={productionForm.errors.input_product_coil_id} disabled={inputProduct?.inventory_tracking_mode !== 'coil'}>
                                <option value="">Sin bobina</option>
                                {availableCoils.map((coil) => <option key={coil.id} value={coil.id}>{coil.barcode} · {coil.lot_number} · {coil.available_meters} m</option>)}
                            </SelectField>
                            <FormField label={selectedFormula ? 'Entrada calculada por formula' : 'Metros entrada'} name="input_meters" type="number" step="0.001" min="0.001" value={selectedFormula ? calculatedFormulaInput(selectedFormula, productionForm.data.output_meters) : productionForm.data.input_meters} onChange={(event) => productionForm.setData('input_meters', event.target.value)} error={productionForm.errors.input_meters} required={!selectedFormula} disabled={Boolean(selectedFormula)} />

                            <SelectField label="Producto salida" name="output_product_id" value={productionForm.data.output_product_id} onChange={(event) => productionForm.setData('output_product_id', event.target.value)} error={productionForm.errors.output_product_id} required={!selectedFormula} disabled={Boolean(selectedFormula)}>
                                {products.map((product) => <option key={product.id} value={product.id}>{product.name} ({tracking(product.inventory_tracking_mode)})</option>)}
                            </SelectField>
                            <FormField label="Metros salida" name="output_meters" type="number" step="0.001" min="0.001" value={productionForm.data.output_meters} onChange={(event) => productionForm.setData('output_meters', event.target.value)} error={productionForm.errors.output_meters} required />
                            <FormField label="Mano de obra Bs" name="labor_cost" type="number" step="0.1" min="0" value={productionForm.data.labor_cost} onChange={(event) => productionForm.setData('labor_cost', event.target.value)} error={productionForm.errors.labor_cost} />
                            <FormField label="Costo indirecto Bs" name="overhead_cost" type="number" step="0.1" min="0" value={productionForm.data.overhead_cost} onChange={(event) => productionForm.setData('overhead_cost', event.target.value)} error={productionForm.errors.overhead_cost} />
                            <FormField label="Barcode bobina salida" name="output_coil_barcode" value={productionForm.data.output_coil_barcode} onChange={(event) => productionForm.setData('output_coil_barcode', event.target.value)} error={productionForm.errors.output_coil_barcode} disabled={outputProduct?.inventory_tracking_mode !== 'coil'} />
                            <FormField label="Lote salida" name="output_lot_number" value={productionForm.data.output_lot_number} onChange={(event) => productionForm.setData('output_lot_number', event.target.value)} error={productionForm.errors.output_lot_number} disabled={outputProduct?.inventory_tracking_mode !== 'coil'} />
                            <div className="sm:col-span-2 lg:col-span-4">
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300" htmlFor="notes">
                                    Notas
                                    <textarea id="notes" rows="3" value={productionForm.data.notes} onChange={(event) => productionForm.setData('notes', event.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                                </label>
                            </div>
                            <div className="sm:col-span-2 lg:col-span-4">
                                <button disabled={productionForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                                    Guardar produccion
                                </button>
                            </div>
                        </form>
                    </Panel>
                ) : null}

                {canManage ? (
                    <Panel title="Formulas de produccion (BOM)">
                        <form onSubmit={submitFormula} className="space-y-5 p-5">
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <SelectField label="Sucursal" name="formula_branch_id" value={formulaForm.data.branch_id} onChange={(event) => formulaForm.setData('branch_id', event.target.value)} error={formulaForm.errors.branch_id}>
                                    <option value="">Global</option>
                                    {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                </SelectField>
                                <FormField label="Codigo" name="formula_code" value={formulaForm.data.code} onChange={(event) => formulaForm.setData('code', event.target.value)} error={formulaForm.errors.code} required />
                                <FormField label="Nombre" name="formula_name" value={formulaForm.data.name} onChange={(event) => formulaForm.setData('name', event.target.value)} error={formulaForm.errors.name} required />
                                <SelectField label="Producto terminado" name="formula_output" value={formulaForm.data.output_product_id} onChange={(event) => formulaForm.setData('output_product_id', event.target.value)} error={formulaForm.errors.output_product_id} required>
                                    {products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
                                </SelectField>
                                <FormField label="Rendimiento" name="yield_quantity" type="number" step="0.001" min="0.001" value={formulaForm.data.yield_quantity} onChange={(event) => formulaForm.setData('yield_quantity', event.target.value)} error={formulaForm.errors.yield_quantity} required />
                                <FormField label="Merma esperada %" name="expected_waste_percentage" type="number" step="0.01" min="0" value={formulaForm.data.expected_waste_percentage} onChange={(event) => formulaForm.setData('expected_waste_percentage', event.target.value)} error={formulaForm.errors.expected_waste_percentage} />
                                <FormField label="Mano de obra estandar" name="standard_labor_cost" type="number" step="0.1" min="0" value={formulaForm.data.standard_labor_cost} onChange={(event) => formulaForm.setData('standard_labor_cost', event.target.value)} error={formulaForm.errors.standard_labor_cost} />
                                <FormField label="Costo indirecto estandar" name="standard_overhead_cost" type="number" step="0.1" min="0" value={formulaForm.data.standard_overhead_cost} onChange={(event) => formulaForm.setData('standard_overhead_cost', event.target.value)} error={formulaForm.errors.standard_overhead_cost} />
                            </div>
                            <div>
                                <p className="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-300">Insumos</p>
                                <div className="space-y-3">
                                    {formulaForm.data.items.map((item, index) => (
                                        <div key={index} className="grid gap-3 rounded-2xl bg-slate-50 p-3 dark:bg-slate-950 md:grid-cols-[1.5fr_110px_110px_120px_auto]">
                                            <SelectField label="Insumo" name={`item_${index}`} value={item.input_product_id} onChange={(event) => updateFormulaItem(formulaForm, index, 'input_product_id', event.target.value, products)}>
                                                {products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
                                            </SelectField>
                                            <FormField label="Cantidad" name={`qty_${index}`} type="number" step="0.001" min="0.001" value={item.quantity} onChange={(event) => updateFormulaItem(formulaForm, index, 'quantity', event.target.value)} />
                                            <FormField label="Unidad" name={`unit_${index}`} value={item.unit_label} onChange={(event) => updateFormulaItem(formulaForm, index, 'unit_label', event.target.value)} />
                                            <FormField label="Merma %" name={`waste_${index}`} type="number" step="0.01" min="0" value={item.waste_percentage} onChange={(event) => updateFormulaItem(formulaForm, index, 'waste_percentage', event.target.value)} />
                                            <button type="button" onClick={() => removeFormulaItem(formulaForm, index)} className="self-end rounded-md border border-red-200 px-3 py-2 text-sm font-semibold text-red-600">Quitar</button>
                                        </div>
                                    ))}
                                </div>
                                {formulaForm.errors.items ? <p className="mt-2 text-sm font-semibold text-red-600">{formulaForm.errors.items}</p> : null}
                                <button type="button" onClick={() => formulaForm.setData('items', [...formulaForm.data.items, emptyFormulaItem(products[0])])} className="mt-3 rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold dark:border-slate-700">Agregar insumo</button>
                            </div>
                            <div>
                                <p className="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-300">Etapas</p>
                                <div className="space-y-3">
                                    {formulaForm.data.stages.map((stage, index) => (
                                        <div key={index} className="grid gap-3 rounded-2xl bg-slate-50 p-3 dark:bg-slate-950 md:grid-cols-[1fr_110px_2fr_auto]">
                                            <FormField label="Etapa" name={`stage_${index}`} value={stage.name} onChange={(event) => updateStage(formulaForm, index, 'name', event.target.value)} />
                                            <FormField label="Minutos" name={`stage_minutes_${index}`} type="number" min="0" value={stage.estimated_minutes} onChange={(event) => updateStage(formulaForm, index, 'estimated_minutes', event.target.value)} />
                                            <FormField label="Descripcion" name={`stage_desc_${index}`} value={stage.description} onChange={(event) => updateStage(formulaForm, index, 'description', event.target.value)} />
                                            <button type="button" onClick={() => removeStage(formulaForm, index)} className="self-end rounded-md border border-red-200 px-3 py-2 text-sm font-semibold text-red-600">Quitar</button>
                                        </div>
                                    ))}
                                </div>
                                <button type="button" onClick={() => formulaForm.setData('stages', [...formulaForm.data.stages, emptyStage()])} className="mt-3 rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold dark:border-slate-700">Agregar etapa</button>
                            </div>
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300" htmlFor="formula_instructions">
                                Instrucciones
                                <textarea id="formula_instructions" rows="3" value={formulaForm.data.instructions} onChange={(event) => formulaForm.setData('instructions', event.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                            </label>
                            <button disabled={formulaForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                                Guardar formula
                            </button>
                        </form>
                    </Panel>
                ) : null}

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-4">
                    <SelectField label="Sucursal" name="branch_id" value={filterForm.data.branch_id} onChange={(event) => filterForm.setData('branch_id', event.target.value)}>
                        <option value="">Todas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <FormField label="Busqueda" name="search" value={filterForm.data.search} onChange={(event) => filterForm.setData('search', event.target.value)} />
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="100" value={filterForm.data.per_page} onChange={(event) => filterForm.setData('per_page', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('production.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <Panel title="Ordenes de produccion">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Orden</th>
                                    <th className="px-4 py-3 font-medium">Sucursal</th>
                                    <th className="px-4 py-3 font-medium">Entrada</th>
                                    <th className="px-4 py-3 font-medium">Salida</th>
                                    <th className="px-4 py-3 text-right font-medium">Merma</th>
                                    <th className="px-4 py-3 text-right font-medium">Costo</th>
                                    <th className="px-4 py-3 font-medium">Fecha</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {orders.data.map((order) => (
                                    <tr key={order.id}>
                                        <td className="px-4 py-3 font-medium">{order.order_number}</td>
                                        <td className="px-4 py-3">{order.branch?.name ?? '-'}</td>
                                        <td className="px-4 py-3">
                                            <p>{order.input_product?.name ?? '-'}</p>
                                            <p className="text-xs text-slate-500">{numberFormatter.format(Number(order.input_meters ?? 0))} m · {order.input_coil?.barcode ?? 'Global'}</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <p>{order.output_product?.name ?? '-'}</p>
                                            <p className="text-xs text-slate-500">{numberFormatter.format(Number(order.output_meters ?? 0))} m · {order.output_coil?.barcode ?? 'Global'}</p>
                                        </td>
                                        <td className="px-4 py-3 text-right">{numberFormatter.format(Number(order.waste_meters ?? 0))} m</td>
                                        <td className="px-4 py-3 text-right">
                                            <p>Bs {money(order.total_cost)}</p>
                                            <p className="text-xs text-slate-500">Unit. Bs {money(order.unit_cost)}</p>
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-3">{formatDate(order.produced_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>

                <div className="mt-6">
                    <Pagination links={orders.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function Panel({ title, children }) {
    return (
        <section className="mb-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h3 className="font-semibold text-slate-900 dark:text-slate-100">{title}</h3>
            </div>
            {children}
        </section>
    );
}

function tracking(mode) {
    return mode === 'coil' ? 'Bobina' : 'Global';
}

function emptyFormulaItem(product) {
    return {
        input_product_id: product?.id ?? '',
        quantity: '1',
        unit_label: product?.base_unit ?? '',
        waste_percentage: '0',
    };
}

function emptyStage() {
    return {
        name: '',
        description: '',
        estimated_minutes: '0',
        requires_confirmation: false,
    };
}

function updateFormulaItem(form, index, field, value, products = []) {
    form.setData('items', form.data.items.map((item, itemIndex) => {
        if (itemIndex !== index) {
            return item;
        }

        if (field === 'input_product_id') {
            const product = products.find((candidate) => String(candidate.id) === String(value));
            return { ...item, input_product_id: value, unit_label: product?.base_unit ?? item.unit_label };
        }

        return { ...item, [field]: value };
    }));
}

function removeFormulaItem(form, index) {
    form.setData('items', form.data.items.filter((_, itemIndex) => itemIndex !== index));
}

function updateStage(form, index, field, value) {
    form.setData('stages', form.data.stages.map((stage, stageIndex) => stageIndex === index ? { ...stage, [field]: value } : stage));
}

function removeStage(form, index) {
    form.setData('stages', form.data.stages.filter((_, stageIndex) => stageIndex !== index));
}

function calculatedFormulaInput(formula, outputQuantity) {
    const firstItem = formula?.items?.[0];
    if (!firstItem || !outputQuantity) {
        return '';
    }

    const scale = Number(outputQuantity || 0) / Math.max(Number(formula.yield_quantity || 1), 0.0001);

    return (Number(firstItem.quantity || 0) * scale).toFixed(3);
}

function money(value) {
    return Number(value || 0).toFixed(1);
}

function formatDate(value) {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('es-BO', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}
