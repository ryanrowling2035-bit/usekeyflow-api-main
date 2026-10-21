<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\Property;

class MatchingService
{
    public function isIntlOffplan(?string $listingType): bool
    {
        return $listingType !== null
            && in_array($listingType, Constants::PROPERTY_INT_AND_OFF_LISTING_TYPES, true);
    }

    private ?array $rules = null;

    public function getRules(): array
    {
        if ($this->rules === null) {
            // Reads come from the request-cached global_settings snapshot, so this
            // never re-queries even when MatchingService is resolved more than once.
            $this->rules = [
                'rentalPriceVariance' => (float) (globalSetting('_matchingRentalPriceVariance') ?? 10),
                'salesPriceVarianceUpTo' => (float) (globalSetting('_matchingSalesPriceVarianceUpTo') ?? 10),
                'salesPriceVarianceAbove' => (float) (globalSetting('_matchingSalesPriceVarianceAbove') ?? 20),
                'salesPriceThreshold' => (float) (globalSetting('_matchingSalesPriceThreshold') ?? 3000000),
            ];
        }

        return $this->rules;
    }

    /**
     * Get the correct variance % based on listing type and price.
     * - Rent → rentalPriceVariance
     * - Sale/other → salesPriceVarianceUpTo if price <= threshold, salesPriceVarianceAbove otherwise
     */
    public function getPriceVariance(?string $listingType = null, float $price = 0): float
    {
        $rules = $this->getRules();

        if ($listingType && strtolower($listingType) === 'rent') {
            return $rules['rentalPriceVariance'];
        }

        if ($price > $rules['salesPriceThreshold']) {
            return $rules['salesPriceVarianceAbove'];
        }

        return $rules['salesPriceVarianceUpTo'];
    }

    public function applyPriceFilterFromProperty($query, $value, string $column = 'price')
    {
        if (! is_numeric($value) || $value <= 0) {
            return $query;
        }

        $query->where($column, '=', floatval($value));

        return $query;
    }

    public function applyPriceRangeFilter($query, $priceMin, $priceMax, bool $isExactMatch = false, ?string $listingType = null)
    {
        if (! $priceMin && ! $priceMax) {
            return $query;
        }

        if ($isExactMatch) {
            if ($priceMin) {
                $query->where('priceMin', $priceMin);
            }
            if ($priceMax > $priceMin) {
                $query->where('priceMax', $priceMax);
            }

            return $query;
        }

        $referencePrice = max(floatval($priceMin ?: 0), floatval($priceMax ?: 0));

        // When listingType is null, apply both Rent and Sale variance rules with OR
        if (! $listingType) {
            $rentalVariance = $this->getPriceVariance('rent', $referencePrice);
            $salesVariance = $this->getPriceVariance('sale', $referencePrice);

            $rentalBounds = $this->calculateBounds($priceMin, $priceMax, $rentalVariance);
            $salesBounds = $this->calculateBounds($priceMin, $priceMax, $salesVariance);

            $query->where(function ($q) use ($rentalBounds, $salesBounds) {
                // Rent enquiries with rental variance
                $q->where(function ($sub) use ($rentalBounds) {
                    $sub->where('listingType', 'rent')
                        ->whereRaw('CAST(priceMin AS DECIMAL(20,2)) >= ?', [$rentalBounds['min']])
                        ->whereRaw('CAST(priceMax AS DECIMAL(20,2)) <= ?', [$rentalBounds['max']]);
                })
                // Sale/other enquiries with sales variance
                    ->orWhere(function ($sub) use ($salesBounds) {
                        $sub->where('listingType', '!=', 'rent')
                            ->whereRaw('CAST(priceMin AS DECIMAL(20,2)) >= ?', [$salesBounds['min']])
                            ->whereRaw('CAST(priceMax AS DECIMAL(20,2)) <= ?', [$salesBounds['max']]);
                    });
            });

            return $query;
        }

        $variance = $this->getPriceVariance($listingType, $referencePrice);
        $bounds = $this->calculateBounds($priceMin, $priceMax, $variance);

        $query->where(function ($q) use ($bounds) {
            $q->whereRaw('CAST(priceMin AS DECIMAL(20,2)) >= ?', [$bounds['min']])
                ->whereRaw('CAST(priceMax AS DECIMAL(20,2)) <= ?', [$bounds['max']]);
        });

        return $query;
    }

    public function applyPriceRangeFilterFromProperty($query, $priceMin, $priceMax, ?string $listingType = null)
    {
        $priceMin = is_numeric($priceMin) && floatval($priceMin) > 0 ? floatval($priceMin) : null;
        $priceMax = is_numeric($priceMax) && floatval($priceMax) > 0 ? floatval($priceMax) : null;

        if ($priceMin === null && $priceMax === null) {
            return $query;
        }
        if ($priceMin !== null && $priceMax !== null && $priceMin > $priceMax) {
            [$priceMin, $priceMax] = [$priceMax, $priceMin];
        }

        $reference = max($priceMin ?? 0, $priceMax ?? 0);
        $applyBounds = function ($sub, float $variance) use ($priceMin, $priceMax) {
            if ($priceMin !== null) {
                $sub->where('price', '>=', floor($priceMin * (1 - $variance / 100)));
            }
            if ($priceMax !== null) {
                $sub->where('price', '<=', ceil($priceMax * (1 + $variance / 100)));
            }
        };

        if (! $listingType) {
            $rentalVariance = $this->getPriceVariance('rent', $reference);
            $salesVariance = $this->getPriceVariance('sale', $reference);
            $query->where(function ($q) use ($applyBounds, $rentalVariance, $salesVariance) {
                $q->where(function ($sub) use ($applyBounds, $rentalVariance) {
                    $sub->where('listingType', 'rent');
                    $applyBounds($sub, $rentalVariance);
                })->orWhere(function ($sub) use ($applyBounds, $salesVariance) {
                    $sub->where('listingType', '!=', 'rent');
                    $applyBounds($sub, $salesVariance);
                });
            });

            return $query;
        }

        $variance = $this->getPriceVariance($listingType, $reference);
        $applyBounds($query, $variance);

        return $query;
    }

    /**
     * Match the property's community against any of the enquiry's 3 community fields.
     */
    public function applyEnquiryCommunitiesFilterForProperty($query, $enquiry, string $column = 'pfCommunity')
    {
        $communities = array_values(array_filter(
            [$enquiry->community, $enquiry->community_2, $enquiry->community_3],
            fn ($v) => $v !== null && $v !== ''
        ));

        if (empty($communities)) {
            return $query;
        }

        return $query->whereIn($column, $communities);
    }

    /**
     * Enquiry → Listings: filter the property query to off-plan/international rows whose
     * 6 unit configs satisfy the enquiry's bedrooms/size/price criteria. A property qualifies if
     * ANY of its 6 slots qualifies. Within a slot, only axes that have a populated meta value are
     * evaluated (a slot with only a bedroom set is checked against bedrooms only). Price uses
     * the sales variance.
     */
    public function applyOffplanUnitFiltersToPropertyQuery(
        $query,
        ?int $bedMin,
        ?int $bedMax,
        ?float $sizeMin,
        ?float $sizeMax,
        ?float $priceMin,
        ?float $priceMax,
    ) {
        $priceBounds = null;
        if ($priceMin !== null || $priceMax !== null) {
            $reference = max($priceMin ?? 0, $priceMax ?? 0);
            $variance = $this->getPriceVariance(Constants::PROPERTY_PURPOSE_OFFPLAN, $reference);
            $priceBounds = $this->calculateBounds($priceMin, $priceMax, $variance);
        }

        $hasAny = $bedMin !== null || $bedMax !== null
            || $sizeMin !== null || $sizeMax !== null
            || $priceBounds !== null;

        if (! $hasAny) {
            return $query;
        }

        $slotClauses = [];
        $bindings = [];

        foreach (range(1, 6) as $n) {
            $bedKey = "unitType{$n}";
            $areaKey = "unitTypeBuildUpArea{$n}";
            $priceKey = "unitTypePrice{$n}";

            // Flat correlated subquery — aggregate property_meta with MAX(CASE WHEN ...) and apply
            // slot conditions via HAVING. Avoids a derived table, which MySQL won't let reference
            // properties.id without LATERAL (8.0.14+).
            $bedExpr = 'MAX(CASE WHEN metaKey = ? THEN metaValue END)';
            $areaExpr = 'MAX(CASE WHEN metaKey = ? THEN metaValue END)';
            $priceExpr = 'MAX(CASE WHEN metaKey = ? THEN metaValue END)';

            $having = ["({$bedExpr} IS NOT NULL OR {$areaExpr} IS NOT NULL OR {$priceExpr} IS NOT NULL)"];
            $havingBindings = [$bedKey, $areaKey, $priceKey];

            if ($bedMin !== null) {
                $having[] = "({$bedExpr} IS NULL OR CAST({$bedExpr} AS DECIMAL(20,2)) >= ?)";
                array_push($havingBindings, $bedKey, $bedKey, $bedMin);
            }
            if ($bedMax !== null) {
                $having[] = "({$bedExpr} IS NULL OR CAST({$bedExpr} AS DECIMAL(20,2)) <= ?)";
                array_push($havingBindings, $bedKey, $bedKey, $bedMax);
            }
            if ($sizeMin !== null) {
                $having[] = "({$areaExpr} IS NULL OR CAST({$areaExpr} AS DECIMAL(20,2)) >= ?)";
                array_push($havingBindings, $areaKey, $areaKey, $sizeMin);
            }
            if ($sizeMax !== null) {
                $having[] = "({$areaExpr} IS NULL OR CAST({$areaExpr} AS DECIMAL(20,2)) <= ?)";
                array_push($havingBindings, $areaKey, $areaKey, $sizeMax);
            }
            if ($priceBounds !== null) {
                $having[] = "({$priceExpr} IS NULL OR CAST({$priceExpr} AS DECIMAL(20,2)) >= ?)";
                array_push($havingBindings, $priceKey, $priceKey, $priceBounds['min']);
                $having[] = "({$priceExpr} IS NULL OR CAST({$priceExpr} AS DECIMAL(20,2)) <= ?)";
                array_push($havingBindings, $priceKey, $priceKey, $priceBounds['max']);
            }

            $slotClauses[] = 'EXISTS (SELECT 1 FROM property_meta WHERE propertyId = properties.id'
                ." AND metaKey IN (?, ?, ?) AND metaValue IS NOT NULL AND metaValue <> ''"
                .' GROUP BY propertyId HAVING '.implode(' AND ', $having).')';

            array_push($bindings, $bedKey, $areaKey, $priceKey);
            $bindings = array_merge($bindings, $havingBindings);
        }

        return $query->whereRaw('('.implode(' OR ', $slotClauses).')', $bindings);
    }

    /**
     * Listing → Enquiries: when the listing is off-plan/international, qualify enquiries whose
     * range columns overlap with ANY of the property's 6 unit configs. Within a slot, only axes
     * with a populated meta value contribute a constraint. Price uses sales variance.
     */
    public function applyOffplanUnitFiltersToEnquiryQuery($query, Property $property)
    {
        $meta = $property->relationLoaded('meta')
            ? $property->meta->pluck('metaValue', 'metaKey')
            : $property->meta()->pluck('metaValue', 'metaKey');

        $slots = [];
        foreach (range(1, 6) as $n) {
            $bedroom = $this->numericOrNull($meta->get("unitType{$n}"));
            $area = $this->numericOrNull($meta->get("unitTypeBuildUpArea{$n}"));
            $price = $this->numericOrNull($meta->get("unitTypePrice{$n}"));

            if ($bedroom === null && $area === null && $price === null) {
                continue;
            }

            $slots[] = compact('bedroom', 'area', 'price');
        }

        if (empty($slots)) {
            return $query;
        }

        return $query->where(function ($outer) use ($slots) {
            foreach ($slots as $slot) {
                $outer->orWhere(function ($q) use ($slot) {
                    if ($slot['bedroom'] !== null) {
                        $q->where(function ($qq) use ($slot) {
                            $qq->whereNull('bedroomsMin')->orWhere('bedroomsMin', '<=', $slot['bedroom']);
                        })->where(function ($qq) use ($slot) {
                            $qq->whereNull('bedroomsMax')->orWhere('bedroomsMax', '>=', $slot['bedroom']);
                        });
                    }
                    if ($slot['area'] !== null) {
                        $q->where(function ($qq) use ($slot) {
                            $qq->whereNull('sizeMin')->orWhereRaw('CAST(sizeMin AS DECIMAL(20,2)) <= ?', [$slot['area']]);
                        })->where(function ($qq) use ($slot) {
                            $qq->whereNull('sizeMax')->orWhereRaw('CAST(sizeMax AS DECIMAL(20,2)) >= ?', [$slot['area']]);
                        });
                    }
                    if ($slot['price'] !== null) {
                        $variance = $this->getPriceVariance(Constants::PROPERTY_PURPOSE_OFFPLAN, $slot['price']);
                        $lowerBound = floor($slot['price'] * (1 - $variance / 100));
                        $upperBound = ceil($slot['price'] * (1 + $variance / 100));
                        // enquiry range must overlap with [lowerBound, upperBound]
                        $q->where(function ($qq) use ($upperBound) {
                            $qq->whereNull('priceMin')->orWhereRaw('CAST(priceMin AS DECIMAL(20,2)) <= ?', [$upperBound]);
                        })->where(function ($qq) use ($lowerBound) {
                            $qq->whereNull('priceMax')->orWhereRaw('CAST(priceMax AS DECIMAL(20,2)) >= ?', [$lowerBound]);
                        });
                    }
                });
            }
        });
    }

    private function numericOrNull($value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function calculateBounds($priceMin, $priceMax, float $variance): array
    {
        if ($priceMin && ! $priceMax) {
            return [
                'min' => floor($priceMin * (1 - $variance / 100)),
                'max' => ceil($priceMin * (1 + $variance / 100)),
            ];
        } elseif ($priceMax && ! $priceMin) {
            return [
                'min' => floor($priceMax * (1 - $variance / 100)),
                'max' => ceil($priceMax * (1 + $variance / 100)),
            ];
        }

        return [
            'min' => floor($priceMin * (1 - $variance / 100)),
            'max' => ceil($priceMax * (1 + $variance / 100)),
        ];
    }
}
