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
    maxLength = 1000,
} = {}) {
    const result = await Swal.fire({
        title,
        text,
        input: 'textarea',
        inputLabel,
        inputPlaceholder: placeholder,
        inputAttributes: {
            maxlength: maxLength,
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

export async function infoAction({
    title = 'Informacion',
    html = '',
    text = '',
    confirmButtonText = 'Entendido',
    icon = 'info',
} = {}) {
    await Swal.fire({
        title,
        html,
        text,
        icon,
        confirmButtonText,
        background: isDarkMode() ? '#0f172a' : '#ffffff',
        color: isDarkMode() ? '#e2e8f0' : '#0f172a',
        confirmButtonColor: brandColor(),
    });
}

export async function chooseAction({
    title = 'Selecciona una opcion',
    text = '',
    confirmButtonText = 'Continuar',
    denyButtonText = 'Otra opcion',
    cancelButtonText = 'Cancelar',
    icon = 'question',
    confirmValue = true,
    denyValue = false,
} = {}) {
    const result = await Swal.fire({
        title,
        text,
        icon,
        showCancelButton: true,
        showDenyButton: true,
        confirmButtonText,
        denyButtonText,
        cancelButtonText,
        reverseButtons: true,
        background: isDarkMode() ? '#0f172a' : '#ffffff',
        color: isDarkMode() ? '#e2e8f0' : '#0f172a',
        confirmButtonColor: brandColor(),
        denyButtonColor: isDarkMode() ? '#475569' : '#64748b',
        cancelButtonColor: isDarkMode() ? '#334155' : '#94a3b8',
    });

    if (result.isConfirmed) {
        return confirmValue;
    }

    if (result.isDenied) {
        return denyValue;
    }

    return null;
}

export async function dangerConfirmAction({
    title = 'Confirmar eliminacion permanente',
    html = '',
    text = 'Esta accion eliminara definitivamente los datos seleccionados de la base de datos (NO es softdelete). No se podra recuperar.',
    confirmButtonText = 'Si, eliminar definitivamente',
    cancelButtonText = 'Cancelar',
    requiredText = null,
    icon = 'warning',
    confirmButtonColor = null,
} = {}) {
    const swalOptions = {
        title,
        icon,
        showCancelButton: true,
        confirmButtonText,
        cancelButtonText,
        reverseButtons: true,
        background: isDarkMode() ? '#0f172a' : '#ffffff',
        color: isDarkMode() ? '#e2e8f0' : '#0f172a',
        confirmButtonColor: confirmButtonColor || brandColor(),
        cancelButtonColor: isDarkMode() ? '#475569' : '#64748b',
    };

    if (requiredText) {
        swalOptions.html = html || `<p class="mb-3">${text}</p><p class="text-sm font-semibold text-rose-600 dark:text-rose-400">Escribe <strong>${requiredText}</strong> para confirmar:</p>`;
        swalOptions.input = 'text';
        swalOptions.inputPlaceholder = requiredText;
        swalOptions.inputValidator = (value) => {
            if ((value ?? '').trim().toUpperCase() !== requiredText.trim().toUpperCase()) {
                return `Debes escribir exactamente "${requiredText}" para proceder.`;
            }
        };
    } else {
        if (html) {
            swalOptions.html = html;
        } else {
            swalOptions.text = text;
        }
    }

    const result = await Swal.fire(swalOptions);
    return result.isConfirmed ? (result.value ?? true) : false;
}
