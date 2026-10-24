import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ProductLotsPanel from '../../../Components/ProductLotsPanel';
import LotRegistrationFields, { newLotRow } from '../../../Components/LotRegistrationFields';

export default function Index(props) {
    const { product, canRegisterLots = false, branches = [], filters = {} } = props;
    const [branchId, setBranchId] = useState(filters.branch_id ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [registering, setRegistering] = useState(false);
    const form = useForm({ lot_registrations: [newLotRow(filters.branch_id || branches[0]?.id)] });
    const submit = (event) => {
        event.preventDefault();
        form.post(route('inventory.lots.store', product.id), { preserveScroll: true, onSuccess: () => { form.reset(); setRegistering(false); } });
    };
    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Inventario</h2>}>
        <Head title={`Lotes de ${product.name}`} />
        <section className="mx-auto max-w-7xl space-y-5 px-4 py-8 sm:px-6">
            <div className="flex flex-wrap items-start justify-between gap-3"><div>
                <Link href={route('inventory.products.index')} className="text-sm text-brand-primary">Volver a productos</Link>
                <h1 className="mt-2 text-2xl font-bold">Lotes de {product.name}</h1>
                <p className="text-sm text-slate-600 dark:text-slate-300">Cada bobina física tiene su propio lote. El saldo general suma sus existencias una sola vez.</p>
            </div>{canRegisterLots ? <button type="button" onClick={() => setRegistering(!registering)} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white">{registering ? 'Cerrar ingreso' : '+ Agregar lotes / bobinas'}</button> : null}</div>
            {registering && canRegisterLots ? <form onSubmit={submit} className="rounded-lg bg-white p-4 dark:bg-slate-900"><LotRegistrationFields baseUnit={product.base_unit} rows={form.data.lot_registrations} onChange={(rows) => form.setData('lot_registrations', rows)} branches={branches} defaultBranchId={branchId || branches[0]?.id} errors={form.errors} disabled={form.processing} /><p className="mt-3 text-sm text-slate-600">Este ingreso inicial aumenta el stock. Para entradas con documento de proveedor utiliza Compras.</p><button type="submit" disabled={form.processing || !form.data.lot_registrations.length} className="mt-4 rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white">Registrar lotes y existencias</button></form> : null}
            <form onSubmit={(event) => { event.preventDefault(); router.get(route('inventory.lots.index', product.id), { branch_id: branchId, status }, { preserveScroll: true }); }} className="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900 sm:grid-cols-[1fr_1fr_auto]">
                <label className="text-sm">Sucursal<select value={branchId} onChange={(event) => setBranchId(event.target.value)} className="mt-1 block w-full rounded-md border-slate-300 dark:bg-slate-900"><option value="">Todas las permitidas</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
                <label className="text-sm">Estado<select value={status} onChange={(event) => setStatus(event.target.value)} className="mt-1 block w-full rounded-md border-slate-300 dark:bg-slate-900"><option value="">Todos</option><option value="available">Disponible</option><option value="depleted">Agotado</option><option value="voided">Anulado</option></select></label>
                <button type="submit" className="self-end rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white">Filtrar</button>
            </form>
            <ProductLotsPanel overview={props} />
        </section>
    </AuthenticatedLayout>;
}
