import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';

function isDarkMode() {
    return document.documentElement.classList.contains('dark');
}

function brandColor() {
    return getComputedStyle(document.documentElement).getPropertyValue('--brand-primary').trim() || '#2563eb';
}

export async function confirmAction({
    title = 'Confirmar accion',
    text = 'Esta accion no se puede deshacer.',
    confirmButtonText = 'Si, continuar',
    cancelButtonText = 'Cancelar',
    icon = 'warning',
} = {}) {
    const result = await Swal.fire({
        title,
        text,
        icon,
        showCancelButton: true,
        confirmButtonText,
        cancelButtonText,
        reverseButtons: true,
        background: isDarkMode() ? '#0f172a' : '#ffffff',
        color: isDarkMode() ? '#e2e8f0' : '#0f172a',
        confirmButtonColor: brandColor(),
        cancelButtonColor: isDarkMode() ? '#475569' : '#64748b',
    });

    return result.isConfirmed;
}

export async function promptAction({
    title = 'Ingrese el motivo',
    text = '',
    inputLabel = 'Motivo',
    confirmButtonText = 'Continuar',
    cancelButtonText = 'Cancelar',
    placeholder = '',
    required = true,
} = {}) {
    const result = await Swal.fire({
        title,
        text,
        input: 'textarea',
        inputLabel,
        inputPlaceholder: placeholder,
        inputAttributes: {
            maxlength: 1000,
        },
        showCancelButton: true,
        confirmButtonText,
        cancelButtonText,
        reverseButtons: true,
        inputValidator: (value) => (required && !value?.trim() ? 'Debes ingresar un motivo.' : undefined),
        background: isDarkMode() ? '#0f172a' : '#ffffff',
        color: isDarkMode() ? '#e2e8f0' : '#0f172a',
        confirmButtonColor: brandColor(),
        cancelButtonColor: isDarkMode() ? '#475569' : '#64748b',
    });

    return result.isConfirmed ? (result.value ?? '').trim() : null;
}
