import FormField from '../../../../../Shared/Resources/Components/FormField';
import { useState } from 'react';

const detailFields = [
    { key: 'warranty_days', label: 'Garantía (días)', type: 'number', placeholder: 'Ej.: 30', helpText: 'Cuántos días de garantía ofreces.', min: 0, step: 1 },
    { key: 'material_type', label: 'Tipo de material', placeholder: 'Ej.: acero galvanizado', helpText: 'El material o la clasificación del producto.' },
    { key: 'dosage', label: 'Dosis o proporción de mezcla', placeholder: 'Ej.: 1 parte de cemento por 3 de arena', helpText: 'Completa solo si el producto necesita una dosis o mezcla.' },
    { key: 'digital_url', label: 'Enlace del contenido digital', type: 'url', placeholder: 'https://ejemplo.com/contenido', helpText: 'Dirección donde se encuentra el archivo o contenido.' },
    { key: 'sessions', label: 'Cantidad de sesiones incluidas', type: 'number', placeholder: 'Ej.: 5', min: 0, step: 1 },
    { key: 'rental_rules', label: 'Condiciones de alquiler', placeholder: 'Ej.: devolver limpio y con sus accesorios' },
    { key: 'health_info', label: 'Información sanitaria', placeholder: 'Ej.: conservar en un lugar fresco y seco' },
];

export default function ProductAdditionalDetails({ settings, onChange, errors = {} }) {
    const [expanded, setExpanded] = useState(() => detailFields.some(({ key }) => settings[key] !== undefined && settings[key] !== ''));
    const hasErrors = Boolean(errors.catalog_settings) || detailFields.some(({ key }) => errors[`catalog_settings.${key}`]);
    const updateDetail = (field, value) => {
        // Conserva otras configuraciones existentes al editar un dato del formulario.
        const nextSettings = { ...settings };

        if (value === '') {
            delete nextSettings[field.key];
        } else {
            nextSettings[field.key] = field.type === 'number' ? Number(value) : value;
        }

        onChange(nextSettings);
    };

    return (
        <details open={expanded || hasErrors} onToggle={(event) => setExpanded(event.currentTarget.open)} className="rounded-lg border border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-900">
            <summary className="cursor-pointer rounded-lg px-4 py-3 text-sm font-semibold text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary dark:text-slate-100">
                Detalles adicionales <span className="ml-2 text-xs font-normal text-slate-500 dark:text-slate-400">Opcional</span>
            </summary>
            <div className="px-4 pb-4">
                <p className="mb-4 text-sm text-slate-600 dark:text-slate-300">Completa solo lo que corresponda a este producto. Puedes dejar todos estos campos vacíos.</p>
                <div className="grid gap-4 sm:grid-cols-2">
                    {detailFields.map((field) => (
                        <FormField
                            key={field.key}
                            label={field.label}
                            type={field.type}
                            placeholder={field.placeholder}
                            helpText={field.helpText}
                            min={field.min}
                            step={field.step}
                            name={`catalog_settings.${field.key}`}
                            value={typeof settings[field.key] === 'string' || typeof settings[field.key] === 'number' ? settings[field.key] : ''}
                            onChange={(event) => updateDetail(field, event.target.value)}
                            error={errors[`catalog_settings.${field.key}`]}
                        />
                    ))}
                </div>
                <p className="mt-4 text-xs text-slate-500 dark:text-slate-400">Estos detalles guardan información del producto; no generan cobros ni aplican condiciones automáticamente.</p>
            </div>
            {errors.catalog_settings ? <p role="alert" className="px-4 pb-4 text-sm text-red-600">{errors.catalog_settings}</p> : null}
        </details>
    );
}
