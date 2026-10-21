<?php

namespace App\Traits;

trait WordFormatter
{
    // protected function toValueLabelArray(array $items): array
    // {
    //     return collect($items)->map(function ($item) {
    //         return [
    //             'value' => $item,
    //             'label' => ucwords(str_replace(['_', '-'], ' ', $item)),
    //         ];
    //     })->values()->toArray();
    // }

    private function toValueLabelArray(array $data)
    {
        return collect($data)->map(function ($item, $key) {

            if (is_string($key)) {
                return [
                    'value' => $key,
                    'label' => $item,
                ];
            }

            $label = $item;

            $value = $this->formatValue($label);

            return [
                'value' => $value,
                'label' => $label,
            ];
        })->values()->toArray();
    }

    private function formatValue($string)
    {
        $string = trim($string);

        $string = strtolower($string);

        $string = str_replace(' ', '', ucwords($string));

        return lcfirst($string);
    }
}
