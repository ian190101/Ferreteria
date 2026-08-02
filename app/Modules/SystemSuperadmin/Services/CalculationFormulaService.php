<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\CalculationFormula;
use Illuminate\Validation\ValidationException;

class CalculationFormulaService
{
    /**
     * @return array<int, CalculationFormula>
     */
    public function formulasFor(?string $entityType = null, bool $onlyRuntimeEnabled = true): array
    {
        if ($onlyRuntimeEnabled && ! $this->runtimeEnabled()) {
            return [];
        }

        return CalculationFormula::query()
            ->where('is_active', true)
            ->when($entityType, fn ($query) => $query->where(fn ($scoped) => $scoped
                ->where('entity_type', $entityType)
                ->orWhereNull('entity_type')))
            ->orderBy('entity_type')
            ->orderBy('name')
            ->get()
            ->all();
    }

    public function resolve(string $code, ?string $entityType = null, bool $onlyRuntimeEnabled = true): ?CalculationFormula
    {
        if ($onlyRuntimeEnabled && ! $this->runtimeEnabled()) {
            return null;
        }

        return CalculationFormula::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->when($entityType, fn ($query) => $query->where(fn ($scoped) => $scoped
                ->where('entity_type', $entityType)
                ->orWhereNull('entity_type')))
            ->orderByRaw('entity_type is null')
            ->first();
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function evaluate(CalculationFormula|string $formula, array $variables, ?string $entityType = null): float|int
    {
        $formula = is_string($formula) ? $this->resolve($formula, $entityType) : $formula;

        if (! $formula || ! $this->runtimeEnabled()) {
            throw ValidationException::withMessages([
                'formula' => 'La formula no esta disponible para el perfil actual.',
            ]);
        }

        $allowedVariables = collect($formula->variables ?? [])
            ->pluck('code')
            ->filter()
            ->map(fn ($code) => (string) $code)
            ->values()
            ->all();

        $normalized = [];
        foreach ($variables as $key => $value) {
            if ($allowedVariables !== [] && ! in_array((string) $key, $allowedVariables, true)) {
                continue;
            }

            if (! is_numeric($value)) {
                throw ValidationException::withMessages([
                    'variables' => "La variable {$key} debe ser numerica.",
                ]);
            }

            $normalized[(string) $key] = (float) $value;
        }

        $result = $this->evaluateNode($formula->expression, $normalized);
        $precision = max(0, min(8, (int) $formula->precision));
        $rounded = round($result, $precision);

        return $formula->result_type === 'integer' ? (int) round($rounded) : $rounded;
    }

    /**
     * @param array<string, mixed> $expression
     */
    public function validateExpression(array $expression): void
    {
        $this->evaluateNode($expression, [], true);
    }

    public function runtimeEnabled(): bool
    {
        return (bool) data_get(ActiveBusinessProfile::payload(), 'feature_flags.formula_engine', false)
            && (
                ActiveBusinessProfile::capable('uses_formula_calculations')
                || ActiveBusinessProfile::capable('uses_construction_calculations')
            );
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function evaluateNode(mixed $node, array $variables, bool $validateOnly = false): float
    {
        if (is_numeric($node)) {
            return (float) $node;
        }

        if (! is_array($node)) {
            throw ValidationException::withMessages([
                'expression_text' => 'La formula debe ser un numero, variable o nodo JSON valido.',
            ]);
        }

        if (array_key_exists('value', $node)) {
            if (! is_numeric($node['value'])) {
                throw ValidationException::withMessages(['expression_text' => 'Los valores constantes deben ser numericos.']);
            }

            return (float) $node['value'];
        }

        if (array_key_exists('var', $node)) {
            $variable = (string) $node['var'];

            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $variable)) {
                throw ValidationException::withMessages(['expression_text' => 'Las variables solo pueden usar letras, numeros y guion bajo.']);
            }

            if ($validateOnly) {
                return 0.0;
            }

            if (! array_key_exists($variable, $variables)) {
                throw ValidationException::withMessages(['variables' => "Falta la variable {$variable}."]);
            }

            return (float) $variables[$variable];
        }

        $operator = (string) ($node['op'] ?? '');
        $args = $node['args'] ?? [];

        if (! in_array($operator, array_keys($this->operators()), true) || ! is_array($args)) {
            throw ValidationException::withMessages(['expression_text' => 'La formula usa un operador no permitido.']);
        }

        $values = array_map(fn ($arg) => $this->evaluateNode($arg, $variables, $validateOnly), $args);

        if ($validateOnly) {
            return 0.0;
        }

        return match ($operator) {
            'add' => array_sum($values),
            'subtract' => ($values[0] ?? 0) - ($values[1] ?? 0),
            'multiply' => array_reduce($values, fn ($carry, $value) => $carry * $value, 1.0),
            'divide' => $this->safeDivide($values[0] ?? 0, $values[1] ?? 0),
            'min' => min($values ?: [0]),
            'max' => max($values ?: [0]),
            'round' => round($values[0] ?? 0, (int) ($values[1] ?? 2)),
            'percentage' => (($values[0] ?? 0) * ($values[1] ?? 0)) / 100,
        };
    }

    private function safeDivide(float $left, float $right): float
    {
        if (abs($right) < 0.00000001) {
            throw ValidationException::withMessages(['expression_text' => 'La formula intenta dividir entre cero.']);
        }

        return $left / $right;
    }

    /**
     * @return array<string, string>
     */
    private function operators(): array
    {
        return BusinessProfileConfiguration::options()['formulaOperators'];
    }
}
