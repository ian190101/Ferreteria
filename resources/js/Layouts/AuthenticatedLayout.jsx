import ApplicationLogo from '@/Components/ApplicationLogo';
import AppearanceSwitch from '@/Components/AppearanceSwitch';
import IconGlyph from '@/Components/Icon';
import { assetUrl, updateFavicon } from '@/Utils/assets';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { roleLabel } from '../../../app/Modules/Users/Resources/Utils/permissionLabels';

const iconPaths = {
    dashboard: 'M4 13h7V4H4v9Zm0 7h7v-5H4v5Zm9 0h7v-9h-7v9Zm0-11h7V4h-7v5Z',
    alertas: 'M12 3 2 20h20L12 3Zm0 5 5.2 9H6.8L12 8Zm-1 3h2v3h-2v-3Zm0 4h2v2h-2v-2Z',
    inventario: 'M4 7 12 3l8 4-8 4-8-4Zm0 3 8 4 8-4v7l-8 4-8-4v-7Z',
    ventas: 'M5 4h14v16H5V4Zm3 4h8V6H8v2Zm0 4h8v-2H8v2Zm0 4h5v-2H8v2Z',
    clientes: 'M8 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm8 1a3 3 0 1 1 0-6 3 3 0 0 1 0 6ZM2 20a6 6 0 0 1 12 0H2Zm12.5 0a7.5 7.5 0 0 0-2-5.1A5 5 0 0 1 22 18v2h-7.5Z',
    compras: 'M6 6h15l-2 8H8L6 6Zm0 0L5 3H2v2h1.5l3 11.5A2 2 0 0 0 8.4 18H20v-2H8.4l-.4-2M9 22a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm9 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z',
    produccion: 'M4 14h3v6H4v-6Zm6-5h3v11h-3V9Zm6-5h3v16h-3V4ZM3 21h18v-2H3v2Z',
    pagos: 'M4 6h16v12H4V6Zm2 3v6h12V9H6Zm3 1h6v4H9v-4Z',
    reportes: 'M5 3h14v18H5V3Zm3 4h8V5H8v2Zm0 4h8V9H8v2Zm0 4h5v-2H8v2Z',
    exportaciones: 'M5 20h14v-2H5v2Zm7-17-5 5h3v6h4V8h3l-5-5Z',
    configuracion: 'M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8Zm8.5 4a7.7 7.7 0 0 0-.1-1l2-1.5-2-3.5-2.4 1a8.2 8.2 0 0 0-1.8-1L15.8 3h-4l-.4 3a8.2 8.2 0 0 0-1.8 1l-2.4-1-2 3.5 2 1.5a7.7 7.7 0 0 0 0 2l-2 1.5 2 3.5 2.4-1a8.2 8.2 0 0 0 1.8 1l.4 3h4l.4-3a8.2 8.2 0 0 0 1.8-1l2.4 1 2-3.5-2-1.5c.1-.3.1-.7.1-1Z',
    informacion: 'M11 10h2v7h-2v-7Zm0-3h2v2h-2V7Zm1-5a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16Z',
};

export default function AuthenticatedLayout({ header, children }) {
    const { auth, branding } = usePage().props;
    const user = auth.user;
    const permissions = auth.permissions;
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [appearance, setAppearance] = useState(() => {
        const savedMode = localStorage.getItem('appearance-mode');

        if (savedMode === 'light' || savedMode === 'dark') {
            return savedMode;
        }

        return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
    });

    const navigation = useMemo(() => buildNavigation(permissions), [permissions]);
    const activeItem = navigation
        .flatMap((section) => section.items)
        .find((item) => item.active);

    useEffect(() => {
        const useDark = appearance === 'dark';

        document.documentElement.classList.toggle('dark', useDark);
        document.documentElement.style.colorScheme = useDark ? 'dark' : 'light';
        localStorage.setItem('appearance-mode', appearance);
        localStorage.removeItem('appearance-glass');
        document.documentElement.classList.remove('liquid-glass');
    }, [appearance]);

    useEffect(() => {
        updateFavicon(assetUrl(branding?.logoPath));
    }, [branding?.logoPath]);

    return (
        <div className="min-h-screen bg-[radial-gradient(circle_at_top_left,rgb(var(--color-primary)/0.10),transparent_34rem),linear-gradient(180deg,#f8fafc,#eef2f7)] text-slate-900 dark:bg-[radial-gradient(circle_at_top_left,rgb(var(--color-primary)/0.22),transparent_32rem),linear-gradient(180deg,#020617,#0f172a)] dark:text-slate-100">
            <aside className="app-surface fixed inset-y-0 left-0 z-40 hidden w-72 border-r border-white/60 bg-white/80 shadow-[0_20px_60px_rgb(15_23_42/0.08)] backdrop-blur-2xl dark:border-white/10 dark:bg-slate-950/80 lg:flex lg:flex-col">
                <SidebarContent navigation={navigation} user={user} branding={branding} />
            </aside>

            {sidebarOpen ? (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <button aria-label="Cerrar menu" className="absolute inset-0 bg-slate-950/50" type="button" onClick={() => setSidebarOpen(false)} />
                    <aside className="app-surface relative flex h-full w-[min(20rem,86vw)] flex-col border-r border-white/60 bg-white/90 shadow-xl backdrop-blur-2xl dark:border-white/10 dark:bg-slate-950/90">
                        <SidebarContent navigation={navigation} user={user} branding={branding} onNavigate={() => setSidebarOpen(false)} />
                    </aside>
                </div>
            ) : null}

            <div className="lg:pl-72">
                <header className="sticky top-0 z-30 border-b border-white/60 bg-slate-50/78 backdrop-blur-2xl dark:border-white/10 dark:bg-slate-950/72">
                    <div className="flex min-h-16 flex-wrap items-center justify-between gap-2 px-3 py-2 sm:flex-nowrap sm:gap-4 sm:px-6 sm:py-0 lg:px-8">
                        <div className="flex min-w-0 flex-1 items-center gap-3">
                            <button
                                aria-label="Abrir menu"
                                className="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-200/80 bg-white/80 text-slate-700 shadow-sm backdrop-blur transition hover:border-brand-primary hover:text-brand-primary dark:border-white/10 dark:bg-white/10 dark:text-slate-200 lg:hidden"
                                type="button"
                                onClick={() => setSidebarOpen(true)}
                            >
                                <IconGlyph name="menu" />
                            </button>
                            <div className="min-w-0">
                                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                                    {activeItem?.section ?? 'Panel operativo'}
                                </p>
                                <div className="truncate">
                                    {header ?? <h2 className="text-lg font-semibold text-slate-950 dark:text-white">Panel</h2>}
                                </div>
                            </div>
                        </div>

                        <div className="flex shrink-0 items-center gap-2 sm:gap-3">
                            <AppearanceSwitch mode={appearance} onModeChange={setAppearance} />
                            <div className="hidden rounded-full border border-slate-200/80 bg-white/75 px-3 py-2 text-xs text-slate-500 shadow-sm backdrop-blur-xl dark:border-white/10 dark:bg-white/10 dark:text-slate-400 xl:block">
                                Sucursal activa
                                <span className="ms-2 font-semibold text-slate-900 dark:text-slate-100">{user.branch?.name ?? 'Central'}</span>
                            </div>
                            <div className="flex items-center gap-3 rounded-full border border-slate-200/80 bg-white/75 px-2 py-1.5 shadow-sm backdrop-blur-xl dark:border-white/10 dark:bg-white/10">
                                <div className="flex h-9 w-9 items-center justify-center rounded-full bg-brand-primary text-sm font-bold text-white shadow-sm shadow-brand-primary/30">
                                    {initials(user.name)}
                                </div>
                                <div className="hidden text-sm sm:block">
                                    <p className="font-semibold leading-5 text-slate-950 dark:text-white">{user.name}</p>
                                    <p className="text-xs text-slate-500 dark:text-slate-400">{auth.roles?.[0] ? roleLabel(auth.roles[0]) : 'Usuario'}</p>
                                </div>
                                <Link href={route('logout')} method="post" as="button" className="rounded-full px-3 py-1.5 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-white/10 dark:hover:text-white">
                                    Salir
                                </Link>
                            </div>
                        </div>
                    </div>
                </header>

                <main className="min-h-[calc(100vh-4rem)] px-0 py-0">
                    {children}
                </main>
            </div>
        </div>
    );
}

function SidebarContent({ navigation, user, branding, onNavigate }) {
    const navRef = useRef(null);

    useEffect(() => {
        const nav = navRef.current;

        if (!nav) {
            return undefined;
        }

        const savedScroll = Number(sessionStorage.getItem('app-sidebar-scroll') ?? 0);
        nav.scrollTop = Number.isFinite(savedScroll) ? savedScroll : 0;

        const saveScroll = () => {
            sessionStorage.setItem('app-sidebar-scroll', String(nav.scrollTop));
        };

        nav.addEventListener('scroll', saveScroll, { passive: true });

        return () => {
            saveScroll();
            nav.removeEventListener('scroll', saveScroll);
        };
    }, []);

    return (
        <>
            <div className="flex h-20 items-center gap-3 border-b border-slate-200 px-5 dark:border-slate-800">
                <div className="flex h-11 w-11 items-center justify-center overflow-hidden rounded-2xl bg-brand-primary/10 text-brand-primary ring-1 ring-brand-primary/15">
                    <BrandLogo logoPath={branding?.logoPath} />
                </div>
                <div className="min-w-0">
                    <p className="truncate text-sm font-bold uppercase tracking-[0.12em] text-slate-950 dark:text-white">Fabrica de Calaminas</p>
                    <p className="truncate text-xs text-slate-500 dark:text-slate-400">{user.branch?.name ?? 'Sucursal Central'}</p>
                </div>
            </div>

            <nav ref={navRef} className="flex-1 space-y-6 overflow-y-auto px-4 py-5">
                {navigation.map((section) => (
                    <div key={section.label}>
                        <p className="mb-2 px-3 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-400 dark:text-slate-500">{section.label}</p>
                        <div className="space-y-1">
                            {section.items.map((item) => (
                                <SidebarLink key={item.label} item={item} onNavigate={onNavigate} />
                            ))}
                        </div>
                    </div>
                ))}
            </nav>

            <div className="border-t border-slate-200 p-4 dark:border-slate-800">
                <Link href={route('profile.edit')} onClick={onNavigate} className="flex items-center gap-3 rounded-2xl border border-slate-200/80 bg-white/50 p-3 transition hover:border-brand-primary/40 hover:bg-white/70 dark:border-white/10 dark:bg-white/10 dark:hover:bg-white/10">
                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-slate-900 text-sm font-bold text-white dark:bg-white dark:text-slate-950">
                        {initials(user.name)}
                    </div>
                    <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-slate-950 dark:text-white">{user.name}</p>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">Perfil y seguridad</p>
                    </div>
                </Link>
            </div>
        </>
    );
}

function BrandLogo({ logoPath }) {
    const [failed, setFailed] = useState(false);
    const logoSrc = assetUrl(logoPath);

    if (logoSrc && !failed) {
        return <img src={logoSrc} alt="" className="h-full w-full object-contain p-1.5" onError={() => setFailed(true)} />;
    }

    return <ApplicationLogo className="h-7 w-7 fill-current" />;
}

function SidebarLink({ item, onNavigate }) {
    return (
        <Link
            href={item.href}
            prefetch
            cacheFor="30s"
            onClick={onNavigate}
            className={[
                'group flex items-center gap-3 rounded-2xl px-3 py-2.5 text-sm font-semibold transition',
                item.active
                    ? 'bg-brand-primary text-white shadow-lg shadow-brand-primary/20'
                    : 'text-slate-600 hover:bg-white/70 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white',
            ].join(' ')}
        >
            <span className={[
                'flex h-8 w-8 items-center justify-center rounded-xl transition',
                item.active
                    ? 'bg-white/15 text-white'
                    : 'bg-white/70 text-slate-500 group-hover:bg-white group-hover:text-brand-primary dark:bg-white/10 dark:text-slate-400 dark:group-hover:bg-white/10',
            ].join(' ')}>
                <Icon path={iconPaths[item.icon] ?? iconPaths.dashboard} />
            </span>
            <span className="truncate">{item.label}</span>
        </Link>
    );
}

function Icon({ path }) {
    return (
        <svg aria-hidden="true" className="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
            <path d={path} />
        </svg>
    );
}

function buildNavigation(permissions) {
    const can = (permission) => permissions.includes(permission);
    const item = (condition, section, label, href, active, icon) => condition ? { section, label, href, active, icon } : null;

    return [
        {
            label: 'Operacion',
            items: [
                item(can('dashboard.view'), 'Operacion', 'Panel', route('dashboard'), route().current('dashboard'), 'dashboard'),
                item(can('alerts.view'), 'Operacion', 'Alertas', route('alerts.index'), route().current('alerts.*'), 'alertas'),
                item(can('reports.view'), 'Operacion', 'Reportes', route('reports.index'), route().current('reports.*'), 'reportes'),
            ].filter(Boolean),
        },
        {
            label: 'Comercial',
            items: [
                item(can('sales.view'), 'Comercial', 'Ventas', route('sales.index'), route().current('sales.index') || route().current('sales.create') || route().current('sales.show') || route().current('sales.settings.*') || route().current('sales.templates.*'), 'ventas'),
                item(can('sales.deliveries.view'), 'Comercial', 'Despachos', route('sales.deliveries.index'), route().current('sales.deliveries.*'), 'ventas'),
                item(can('sales.returns.view'), 'Comercial', 'Devoluciones', route('sales.returns.index'), route().current('sales.returns.*'), 'ventas'),
                item(can('customers.view'), 'Comercial', 'Clientes', route('customers.index'), route().current('customers.*'), 'clientes'),
            ].filter(Boolean),
        },
        {
            label: 'Inventario',
            items: [
                item(can('inventory.products.view'), 'Inventario', 'Stock central', route('inventory.stock.index'), route().current('inventory.stock.*'), 'inventario'),
                item(can('inventory.products.view'), 'Inventario', 'Productos', route('inventory.products.index'), route().current('inventory.products.*') || route().current('inventory.thicknesses.*') || route().current('inventory.coils.*'), 'inventario'),
                item(can('inventory.adjustments.view'), 'Inventario', 'Ajustes', route('inventory.adjustments.index'), route().current('inventory.adjustments.*'), 'inventario'),
                item(can('inventory.movements.view'), 'Inventario', 'Kardex', route('inventory.movements.index'), route().current('inventory.movements.*'), 'reportes'),
                item(can('inventory.reservations.view'), 'Inventario', 'Reservas', route('inventory.reservations.index'), route().current('inventory.reservations.*'), 'inventario'),
                item(can('inventory.transfers.view'), 'Inventario', 'Transferencias', route('inventory.transfers.index'), route().current('inventory.transfers.*'), 'inventario'),
            ].filter(Boolean),
        },
        {
            label: 'Finanzas',
            items: [
                item(can('purchases.view'), 'Finanzas', 'Compras', route('purchases.index'), route().current('purchases.*'), 'compras'),
                item(can('payments.view'), 'Finanzas', 'Pagos clientes', route('payments.index'), route().current('payments.index'), 'pagos'),
                item(can('payments.view'), 'Finanzas', 'Pagos proveedores', route('payments.purchase-payments.index'), route().current('payments.purchase-payments.*'), 'pagos'),
                item(can('payment-promises.view'), 'Finanzas', 'Cobranza', route('payments.promises.index'), route().current('payments.promises.*'), 'pagos'),
                item(can('credit-notes.view'), 'Finanzas', 'Notas credito', route('payments.credit-notes.index'), route().current('payments.credit-notes.*'), 'pagos'),
                item(can('expenses.view'), 'Finanzas', 'Gastos', route('expenses.index'), route().current('expenses.*'), 'pagos'),
                item(can('cash.view'), 'Finanzas', 'Caja', route('cash.index'), route().current('cash.*'), 'pagos'),
                item(can('banks.view'), 'Finanzas', 'Bancos', route('banks.index'), route().current('banks.*'), 'pagos'),
            ].filter(Boolean),
        },
        {
            label: 'Administracion',
            items: [
                item(can('production.view'), 'Administracion', 'Produccion', route('production.index'), route().current('production.*'), 'produccion'),
                item(can('users.view'), 'Administracion', 'Usuarios', route('users.index'), route().current('users.*'), 'configuracion'),
                item(can('branches.view'), 'Administracion', 'Sucursales', route('branches.index'), route().current('branches.*'), 'configuracion'),
                item(can('audit.view'), 'Administracion', 'Auditoria', route('audit.index'), route().current('audit.*'), 'reportes'),
                item(can('settings.manage'), 'Administracion', 'Exportaciones', route('exports.index'), route().current('exports.*'), 'exportaciones'),
                item(can('settings.manage'), 'Administracion', 'Informacion', route('settings.info.index'), route().current('settings.info.*'), 'informacion'),
                item(can('settings.manage'), 'Administracion', 'Sistema', route('settings.system.index'), route().current('settings.system.*'), 'configuracion'),
            ].filter(Boolean),
        },
    ].filter((section) => section.items.length > 0);
}

function initials(name) {
    return String(name ?? 'U')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}
