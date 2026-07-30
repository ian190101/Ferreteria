<?php

namespace App\Modules\SystemSuperadmin\Services;

class BusinessProfileDiffService
{
    public function compare(array $before, array $after): array
    {
        $before = BusinessProfileConfiguration::normalized($before);
        $after = BusinessProfileConfiguration::normalized($after);
        $sections = array_unique([...array_keys($before), ...array_keys($after)]);
        $changes = [];

        foreach ($sections as $section) {
            $sectionChanges = $this->compareSection($before[$section] ?? null, $after[$section] ?? null, $section);

            if ($sectionChanges !== []) {
                $changes[$section] = $sectionChanges;
            }
        }

        return $changes;
    }

    private function compareSection(mixed $before, mixed $after, string $prefix): array
    {
        if (! is_array($before) || ! is_array($after)) {
            return $before === $after ? [] : [[
                'key' => $prefix,
                'before' => $before,
                'after' => $after,
            ]];
        }

        $keys = array_unique([...array_keys($before), ...array_keys($after)]);
        $changes = [];

        foreach ($keys as $key) {
            $path = $prefix.'.'.$key;
            $left = $before[$key] ?? null;
            $right = $after[$key] ?? null;

            if (is_array($left) && is_array($right) && ! array_is_list($left) && ! array_is_list($right)) {
                array_push($changes, ...$this->compareSection($left, $right, $path));
                continue;
            }

            if ($left !== $right) {
                $changes[] = [
                    'key' => $path,
                    'before' => $left,
                    'after' => $right,
                ];
            }
        }

        return $changes;
    }
}
