export const baseUnitOf = (product) => product?.unit?.symbol ?? product?.base_unit ?? 'unidad';

export function activeConversions(product) {
    return (product?.unit_conversions ?? product?.unitConversions ?? [])
        .filter((row) => row.is_active !== false && Number(row.factor_to_base) > 0);
}

export default function UnitConversionGuide({ product, unit, mode = 'direct', direction = 'entrada' }) {
    if (!product) return null;
    const base = baseUnitOf(product);
    const selected = unit || base;
    const conversion = activeConversions(product).find((row) => (row.unit?.symbol ?? row.symbol) === selected);
    const thickness = product.thickness;
    const hasKgPerMeter = Number(thickness?.kg_per_meter) > 0;
    const hasMetersPerKg = Number(thickness?.kg_to_meter_factor) > 0;
    const missing = mode === 'weight' ? !hasKgPerMeter && !hasMetersPerKg : mode === 'direct' && selected !== base && !conversion;

    return <aside aria-label="Conversión y efecto en inventario" className="space-y-1 rounded-md border border-sky-200 bg-sky-50 p-3 text-sm text-sky-950 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100">
        <p><strong>El stock se controla en {base}.</strong> La {direction} del lote y del producto usa esa misma unidad.</p>
        {mode === 'weight' ? <>
            <p>{hasKgPerMeter ? `Metros = peso en kg ÷ ${thickness.kg_per_meter} kg/m.` : hasMetersPerKg ? `Metros = peso en kg × ${thickness.kg_to_meter_factor} m/kg.` : 'Configura el peso por metro en Espesores y asocia ese espesor al producto.'} Una tonelada equivale a 1000 kg.</p>
            {base !== 'm' ? <p className="font-semibold text-amber-800 dark:text-amber-200">Revisa la configuración: este cálculo produce metros y la unidad base actual es {base}.</p> : null}
        </> : mode === 'length' ? <p>Cantidad de piezas × largo de cada pieza en {base} = cantidad de {direction}. El largo se indica en esta línea; no se aplica otra equivalencia adicional.</p> : <p>{selected === base ? `1 ${base} = 1 ${base}. Ingresa la cantidad en ${base}.` : conversion ? `1 ${selected} = ${conversion.factor_to_base} ${base}. Cantidad × equivalencia = cantidad de ${direction}.` : `Falta la equivalencia de ${selected} a ${base}. Configúrala en Editar producto → Equivalencias de unidades.`}</p>}
        {missing ? <p role="status" className="font-semibold text-amber-800 dark:text-amber-200">Falta configurar la conversión. No se debe interpretar como una equivalencia de 1 a 1.</p> : null}
        <p className="text-xs">El resumen es una vista previa; al guardar se valida y calcula la cantidad definitiva.</p>
    </aside>;
}
