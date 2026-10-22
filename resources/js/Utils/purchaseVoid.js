import axios from 'axios';
import { router } from '@inertiajs/react';
import { infoAction, promptAction } from '@/Utils/alerts';
import { showSystemError } from '@/Utils/systemErrors';

export async function confirmPurchaseVoid(purchaseId) {
    try {
        const { data } = await axios.get(route('purchases.void-preview', purchaseId));
        const payments = data.payments.map((payment) => `#${payment.id}: ${payment.amount} (${payment.method ?? 'Sin método'})`);
        const inventory = data.inventory.map((item) => `${item.product ?? 'Producto'}: ${item.quantity}${item.coil ? `, bobina ${item.coil}` : ''}`);

        return await promptAction({
            title: 'Anular compra y pagos',
            text: `Compra: ${data.document_number}. Sucursal: ${data.branch_id}. Pagos: ${payments.join('; ') || 'Ninguno'}. Inventario que se retirará: ${inventory.join('; ') || 'Ninguno'}. La operación es integral.`,
            confirmButtonText: 'Anular compra y pagos',
            maxLength: 255,
        });
    } catch (error) {
        await showSystemError(error);

        return null;
    }
}

export function submitPurchaseVoid(url, reason) {
    router.patch(url, { reason }, {
        preserveScroll: true,
        // Los rechazos se muestran mediante el manejador global y conservan los errores del formulario.
        onSuccess: () => infoAction({
            title: 'Compra anulada',
            text: 'La compra y sus pagos asociados se anularon correctamente.',
            icon: 'success',
        }),
    });
}
