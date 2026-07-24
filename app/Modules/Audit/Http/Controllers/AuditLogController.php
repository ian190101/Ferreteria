<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Models\User;
use App\Support\SystemRoles;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $audits = Audit::query()
            ->with('user:id,name,email')
            ->where(fn ($query) => $this->withoutSystemUserAudits($query))
            ->when(! $user->isSuperAdministrator(), fn ($query) => $query->where('user_id', $user->id))
            ->when($request->string('event')->isNotEmpty(), fn ($query) => $query->where('event', $request->string('event')->toString()))
            ->when($user->isSuperAdministrator() && $request->integer('user_id'), fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($request->string('auditable_type')->isNotEmpty(), fn ($query) => $query->where('auditable_type', $request->string('auditable_type')->toString()))
            ->when($request->string('ip_address')->isNotEmpty(), fn ($query) => $query->where('ip_address', $request->string('ip_address')->toString()))
            ->when($request->date('from'), fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($request->date('to'), fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest('id')
            ->paginate($request->integer('per_page', 15))
            ->through(fn (Audit $audit) => [
                'id' => $audit->id,
                'created_at' => $audit->created_at,
                'event' => $audit->event,
                'event_label' => $this->eventLabel($audit->event),
                'auditable_type' => $audit->auditable_type,
                'auditable_label' => $this->modelLabel($audit->auditable_type),
                'auditable_id' => $audit->auditable_id,
                'description' => $this->description($audit),
                'user' => $audit->user,
                'ip_address' => $audit->ip_address,
                'old_values' => $audit->old_values,
                'new_values' => $audit->new_values,
            ])
            ->withQueryString();

        return Inertia::render('Audit/Index', [
            'audits' => $audits,
            'filters' => $request->only(['event', 'user_id', 'auditable_type', 'ip_address', 'from', 'to', 'per_page']),
            'users' => User::query()
                ->withoutSystemSuperadmins()
                ->when(! $user->isSuperAdministrator(), fn ($query) => $query->whereKey($user->id))
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'events' => Audit::query()
                ->select('event')
                ->distinct()
                ->orderBy('event')
                ->pluck('event'),
            'auditableTypes' => Audit::query()
                ->where(fn ($query) => $this->withoutSystemUserAudits($query))
                ->when(! $user->isSuperAdministrator(), fn ($query) => $query->where('user_id', $user->id))
                ->select('auditable_type')
                ->distinct()
                ->orderBy('auditable_type')
                ->pluck('auditable_type')
                ->map(fn (string $type) => ['value' => $type, 'label' => $this->modelLabel($type)])
                ->values(),
            'canViewGlobal' => $user->isSuperAdministrator(),
        ]);
    }

    private function withoutSystemUserAudits($query)
    {
        $reservedUserIds = User::query()
            ->whereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('name', SystemRoles::reserved()))
            ->select('id');

        return $query
            ->where(fn ($nested) => $nested
                ->whereNull('user_id')
                ->orWhereNotIn('user_id', clone $reservedUserIds))
            ->where(fn ($nested) => $nested
                ->where('auditable_type', '!=', User::class)
                ->orWhereNull('auditable_type')
                ->orWhereNotIn('auditable_id', clone $reservedUserIds));
    }

    private function eventLabel(?string $event): string
    {
        return [
            'created' => 'Creacion',
            'updated' => 'Edicion',
            'deleted' => 'Eliminacion',
            'restored' => 'Restauracion',
        ][$event] ?? ucfirst((string) $event);
    }

    private function modelLabel(?string $type): string
    {
        $short = class_basename((string) $type);

        return [
            'Sale' => 'Venta / cotizacion',
            'SaleItem' => 'Detalle de venta',
            'SalePayment' => 'Pago de cliente',
            'Purchase' => 'Compra de mercaderia',
            'PurchasePayment' => 'Pago a proveedor',
            'Product' => 'Producto',
            'ProductCoil' => 'Bobina',
            'ProductBranchStock' => 'Stock por sucursal',
            'InventoryMovement' => 'Movimiento de inventario',
            'InventoryAdjustment' => 'Ajuste de inventario',
            'InventoryTransfer' => 'Transferencia de inventario',
            'CashRegisterSession' => 'Caja',
            'BankAccount' => 'Cuenta bancaria',
            'BankTransaction' => 'Movimiento bancario',
            'Expense' => 'Gasto',
            'Customer' => 'Cliente',
            'Supplier' => 'Proveedor',
            'ReceiptTemplate' => 'Plantilla de comprobante',
            'BusinessProfile' => 'Perfil empresarial aplicado',
            'BusinessProfileDraft' => 'Borrador de perfil empresarial',
            'BusinessProfileVersion' => 'Version anterior de perfil empresarial',
            'User' => 'Usuario',
            'Branch' => 'Sucursal',
        ][$short] ?? $short;
    }

    private function description(Audit $audit): string
    {
        return "{$this->eventLabel($audit->event)} en {$this->modelLabel($audit->auditable_type)} #{$audit->auditable_id}";
    }
}
