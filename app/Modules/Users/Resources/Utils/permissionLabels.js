const permissionGroups = {
    alerts: 'Alertas',
    audit: 'Auditoria',
    banks: 'Bancos',
    billing: 'Facturacion SIAT',
    branches: 'Sucursales',
    cash: 'Caja',
    customers: 'Clientes',
    dashboard: 'Panel principal',
    expenses: 'Gastos',
    inventory: 'Inventario',
    payments: 'Pagos',
    purchases: 'Compras',
    production: 'Produccion',
    reports: 'Reportes',
    sales: 'Ventas',
    settings: 'Configuracion',
    users: 'Usuarios y roles',
    'credit-notes': 'Notas de credito',
    'payment-promises': 'Promesas de pago',
};

const permissionNames = {
    'alerts.view': 'Ver alertas',
    'dashboard.view': 'Ver panel principal',
    'audit.view': 'Ver auditoria',
    'banks.view': 'Ver bancos',
    'banks.manage': 'Gestionar bancos',
    'billing.view': 'Ver facturacion SIAT',
    'billing.manage': 'Configurar, emitir, anular y sincronizar SIAT',
    'branches.view': 'Ver sucursales',
    'branches.manage': 'Gestionar sucursales',
    'cash.view': 'Ver caja',
    'cash.manage': 'Abrir, cerrar y gestionar caja',
    'customers.view': 'Ver clientes',
    'customers.manage': 'Gestionar clientes',
    'credit-notes.view': 'Ver notas de credito',
    'credit-notes.manage': 'Gestionar notas de credito',
    'expenses.view': 'Ver gastos',
    'expenses.manage': 'Gestionar gastos',
    'inventory.products.view': 'Ver productos y catalogos',
    'inventory.products.manage': 'Gestionar productos y catalogos',
    'inventory.coils.manage': 'Gestionar lotes/unidades fisicas',
    'inventory.adjustments.view': 'Ver ajustes de inventario',
    'inventory.adjustments.manage': 'Gestionar ajustes de inventario',
    'inventory.movements.view': 'Ver movimientos de inventario',
    'inventory.reservations.view': 'Ver reservas de inventario',
    'inventory.reservations.manage': 'Gestionar reservas de inventario',
    'inventory.transfers.view': 'Ver transferencias de inventario',
    'inventory.transfers.manage': 'Gestionar transferencias de inventario',
    'payments.view': 'Ver pagos y cuentas por cobrar',
    'payments.manage': 'Registrar y anular pagos',
    'payment-promises.view': 'Ver promesas de pago',
    'payment-promises.manage': 'Gestionar promesas de pago',
    'production.view': 'Ver produccion',
    'production.manage': 'Gestionar produccion',
    'purchases.view': 'Ver compras y proveedores',
    'purchases.manage': 'Gestionar compras y proveedores',
    'reports.view': 'Ver reportes',
    'sales.view': 'Ver ventas y cotizaciones',
    'sales.manage': 'Crear, convertir y anular ventas',
    'sales.prices.override': 'Editar precios en ventas',
    'sales.deliveries.view': 'Ver despachos',
    'sales.deliveries.manage': 'Gestionar despachos',
    'sales.returns.view': 'Ver devoluciones',
    'sales.returns.manage': 'Gestionar devoluciones',
    'settings.manage': 'Gestionar configuracion del sistema',
    'users.view': 'Ver usuarios y roles',
    'users.manage': 'Gestionar usuarios, roles y permisos',
};

const groupOrder = [
    'Ventas',
    'Panel principal',
    'Pagos',
    'Caja',
    'Compras',
    'Inventario',
    'Produccion',
    'Clientes',
    'Gastos',
    'Bancos',
    'Facturacion SIAT',
    'Sucursales',
    'Usuarios y roles',
    'Reportes',
    'Alertas',
    'Auditoria',
    'Configuracion',
];

const roleNames = {
    superadmin: 'Superadministrador',
    sistemasuperadmin: 'Superadministrador del sistema',
};

export function roleLabel(roleName) {
    return roleNames[roleName] ?? humanizePermission(roleName);
}

export function permissionLabel(permissionName) {
    return permissionNames[permissionName] ?? humanizePermission(permissionName);
}

export function permissionGroupLabel(groupName) {
    return permissionGroups[groupName] ?? humanizePermission(groupName);
}

export function sortedPermissionGroups(permissions) {
    return Object.entries(permissions)
        .map(([group, groupPermissions]) => ({
            key: group,
            label: permissionGroupLabel(group),
            permissions: [...groupPermissions].sort((first, second) => permissionLabel(first.name).localeCompare(permissionLabel(second.name), 'es')),
        }))
        .sort((first, second) => {
            const firstIndex = groupOrder.indexOf(first.label);
            const secondIndex = groupOrder.indexOf(second.label);

            if (firstIndex !== -1 || secondIndex !== -1) {
                return (firstIndex === -1 ? 999 : firstIndex) - (secondIndex === -1 ? 999 : secondIndex);
            }

            return first.label.localeCompare(second.label, 'es');
        });
}

function humanizePermission(value) {
    return String(value)
        .replaceAll('.', ' ')
        .replaceAll('-', ' ')
        .split(' ')
        .filter(Boolean)
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}
