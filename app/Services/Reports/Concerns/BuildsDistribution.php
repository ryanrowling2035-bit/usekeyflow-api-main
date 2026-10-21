<?php

namespace App\Services\Reports\Concerns;

/**
 * Shared helper for the Nationality / Community widgets: turns a
 * [key => ['count' => int, 'revenue' => float]] map into the top-N slices plus
 * an aggregated "Others" row, each shaped as {label, count, revenue}.
 */
trait BuildsDistribution
{
    /**
     * @param  array<string, array{count: int, revenue: float}>  $map
     * @param  callable(string):string  $label
     * @return array<int, array{label: string, count: int, revenue: float}>
     */
    protected function distributionWithRevenue(array $map, callable $label, int $limit = 5): array
    {
        uasort($map, fn ($a, $b) => $b['count'] <=> $a['count']);

        $result = [];
        $others = ['count' => 0, 'revenue' => 0.0];
        $index = 0;

        foreach ($map as $key => $data) {
            if ($index < $limit) {
                $result[] = [
                    'label' => $label((string) $key),
                    'count' => (int) $data['count'],
                    'revenue' => round($data['revenue'], 2),
                ];
            } else {
                $others['count'] += $data['count'];
                $others['revenue'] += $data['revenue'];
            }
            $index++;
        }

        if ($others['count'] > 0) {
            $result[] = ['label' => 'Others', 'count' => $others['count'], 'revenue' => round($others['revenue'], 2)];
        }

        return $result;
    }
}
