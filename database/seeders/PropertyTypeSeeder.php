<?php

namespace Database\Seeders;

use App\Models\PropertyType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PropertyTypeSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('property_types')->truncate();
        $propertyTypes = [
            // Bayut Residential
            ['name' => 'Villa', 'categoryType' => 'residential', 'typeSlug' => 'villa', 'publisher' => 'Bayut', 'tags' => ['villa', 'house', 'luxury', 'residential'], 'createdBy' => 1],
            ['name' => 'Apartments', 'categoryType' => 'residential', 'typeSlug' => 'apartments', 'publisher' => 'Bayut', 'tags' => ['apartment', 'flat', 'residential'], 'createdBy' => 1],
            ['name' => 'Residential Floor', 'categoryType' => 'residential', 'typeSlug' => 'residential-floor', 'publisher' => 'Bayut', 'tags' => ['floor', 'residential', 'full-floor', 'half-floor'], 'createdBy' => 1],
            ['name' => 'Residential Plot', 'categoryType' => 'residential', 'typeSlug' => 'residential-plots', 'publisher' => 'Bayut', 'tags' => ['plot', 'land', 'residential'], 'createdBy' => 1],
            ['name' => 'Townhouse', 'categoryType' => 'residential', 'typeSlug' => 'townhouses', 'publisher' => 'Bayut', 'tags' => ['townhouse', 'house', 'residential'], 'createdBy' => 1],
            ['name' => 'Residential Building', 'categoryType' => 'residential', 'typeSlug' => 'residential-building', 'publisher' => 'Bayut', 'tags' => ['building', 'residential', 'whole-building'], 'createdBy' => 1],
            ['name' => 'Penthouse', 'categoryType' => 'residential', 'typeSlug' => 'penthouse', 'publisher' => 'Bayut', 'tags' => ['penthouse', 'luxury', 'apartment', 'residential'], 'createdBy' => 1],
            ['name' => 'Villa Compound', 'categoryType' => 'residential', 'typeSlug' => 'villa-compound', 'publisher' => 'Bayut', 'tags' => ['compound', 'villa', 'gated', 'residential'], 'createdBy' => 1],
            ['name' => 'Hotel Apartments', 'categoryType' => 'residential', 'typeSlug' => 'hotel-apartments', 'publisher' => 'Bayut', 'tags' => ['hotel-apartment', 'serviced', 'residential'], 'createdBy' => 1],

            // Bayut Commercial
            ['name' => 'Offices', 'categoryType' => 'commercial', 'typeSlug' => 'offices', 'publisher' => 'Bayut', 'tags' => ['office', 'workspace', 'commercial'], 'createdBy' => 1],
            ['name' => 'Shops', 'categoryType' => 'commercial', 'typeSlug' => 'shops', 'publisher' => 'Bayut', 'tags' => ['shop', 'retail', 'commercial'], 'createdBy' => 1],
            ['name' => 'Warehouse', 'categoryType' => 'commercial', 'typeSlug' => 'warehouses', 'publisher' => 'Bayut', 'tags' => ['warehouse', 'storage', 'commercial'], 'createdBy' => 1],
            ['name' => 'Factory', 'categoryType' => 'commercial', 'typeSlug' => 'factories', 'publisher' => 'Bayut', 'tags' => ['factory', 'industrial', 'commercial'], 'createdBy' => 1],
            ['name' => 'Labour Camp', 'categoryType' => 'commercial', 'typeSlug' => 'labour-camps', 'publisher' => 'Bayut', 'tags' => ['labour-camp', 'staff-accommodation', 'commercial'], 'createdBy' => 1],
            ['name' => 'Commercial Building', 'categoryType' => 'commercial', 'typeSlug' => 'commercial-buildings', 'publisher' => 'Bayut', 'tags' => ['building', 'commercial', 'office'], 'createdBy' => 1],
            ['name' => 'Other Commercial', 'categoryType' => 'commercial', 'typeSlug' => 'commercial-properties', 'publisher' => 'Bayut', 'tags' => ['commercial', 'other', 'mixed-use'], 'createdBy' => 1],
            ['name' => 'Commercial Floor', 'categoryType' => 'commercial', 'typeSlug' => 'commercial-floor', 'publisher' => 'Bayut', 'tags' => ['floor', 'commercial', 'office-floor'], 'createdBy' => 1],
            ['name' => 'Commercial Plot', 'categoryType' => 'commercial', 'typeSlug' => 'commercial-plots', 'publisher' => 'Bayut', 'tags' => ['plot', 'land', 'commercial'], 'createdBy' => 1],
            ['name' => 'Bulk Units', 'categoryType' => 'commercial', 'typeSlug' => 'bulk-units', 'publisher' => 'Bayut', 'tags' => ['bulk', 'rent', 'sale', 'commercial'], 'createdBy' => 1],
            ['name' => 'Industrial Land', 'categoryType' => 'commercial', 'typeSlug' => 'industrial-land', 'publisher' => 'Bayut', 'tags' => ['industrial', 'land', 'commercial'], 'createdBy' => 1],
            ['name' => 'Mixed Use Land', 'categoryType' => 'commercial', 'typeSlug' => 'mixed-use-land', 'publisher' => 'Bayut', 'tags' => ['mixed-use', 'land', 'commercial'], 'createdBy' => 1],
            ['name' => 'Showroom', 'categoryType' => 'commercial', 'typeSlug' => 'showrooms', 'publisher' => 'Bayut', 'tags' => ['showroom', 'retail', 'commercial'], 'createdBy' => 1],
            ['name' => 'Commercial Villa', 'categoryType' => 'commercial', 'typeSlug' => 'commercial-villas', 'publisher' => 'Bayut', 'tags' => ['villa', 'commercial', 'office'], 'createdBy' => 1],

            // Property Finder Residential
            ['name' => 'Apartment', 'categoryType' => 'residential', 'typeSlug' => 'apartment', 'publisher' => 'Property Finder', 'tags' => ['apartment', 'flat', 'residential'], 'createdBy' => 1],
            ['name' => 'Villa', 'categoryType' => 'residential', 'typeSlug' => 'villa', 'publisher' => 'Property Finder', 'tags' => ['villa', 'house', 'residential'], 'createdBy' => 1],
            ['name' => 'Townhouse', 'categoryType' => 'residential', 'typeSlug' => 'townhouse', 'publisher' => 'Property Finder', 'tags' => ['townhouse', 'residential'], 'createdBy' => 1],
            ['name' => 'Penthouse', 'categoryType' => 'residential', 'typeSlug' => 'penthouse', 'publisher' => 'Property Finder', 'tags' => ['penthouse', 'luxury', 'residential'], 'createdBy' => 1],
            ['name' => 'Hotel Apartment', 'categoryType' => 'residential', 'typeSlug' => 'hotel-apartment', 'publisher' => 'Property Finder', 'tags' => ['hotel-apartment', 'serviced', 'residential'], 'createdBy' => 1],
            ['name' => 'Compound', 'categoryType' => 'residential', 'typeSlug' => 'compound', 'publisher' => 'Property Finder', 'tags' => ['compound', 'gated', 'residential'], 'createdBy' => 1],
            ['name' => 'Whole Building', 'categoryType' => 'residential', 'typeSlug' => 'whole-building', 'publisher' => 'Property Finder', 'tags' => ['building', 'whole', 'residential'], 'createdBy' => 1],
            ['name' => 'Full Floor', 'categoryType' => 'residential', 'typeSlug' => 'full-floor', 'publisher' => 'Property Finder', 'tags' => ['floor', 'full', 'residential'], 'createdBy' => 1],

            // Property Finder Commercial
            ['name' => 'Office Space', 'categoryType' => 'commercial', 'typeSlug' => 'office-space', 'publisher' => 'Property Finder', 'tags' => ['office', 'workspace', 'commercial'], 'createdBy' => 1],
            ['name' => 'Shop', 'categoryType' => 'commercial', 'typeSlug' => 'shop', 'publisher' => 'Property Finder', 'tags' => ['shop', 'retail', 'commercial'], 'createdBy' => 1],
            ['name' => 'Warehouse', 'categoryType' => 'commercial', 'typeSlug' => 'warehouse', 'publisher' => 'Property Finder', 'tags' => ['warehouse', 'storage', 'commercial'], 'createdBy' => 1],
            ['name' => 'Factory', 'categoryType' => 'commercial', 'typeSlug' => 'factory', 'publisher' => 'Property Finder', 'tags' => ['factory', 'industrial', 'commercial'], 'createdBy' => 1],
            ['name' => 'Labour Camp', 'categoryType' => 'commercial', 'typeSlug' => 'labor-camp', 'publisher' => 'Property Finder', 'tags' => ['labour-camp', 'staff-accommodation', 'commercial'], 'createdBy' => 1],
            ['name' => 'Show Room', 'categoryType' => 'commercial', 'typeSlug' => 'show-room', 'publisher' => 'Property Finder', 'tags' => ['showroom', 'retail', 'commercial'], 'createdBy' => 1],
            ['name' => 'Bulk Sale Unit', 'categoryType' => 'commercial', 'typeSlug' => 'bulk-sale-unit', 'publisher' => 'Property Finder', 'tags' => ['bulk', 'sale', 'commercial'], 'createdBy' => 1],
            ['name' => 'Land', 'categoryType' => 'commercial', 'typeSlug' => 'land', 'publisher' => 'Property Finder', 'tags' => ['land', 'plot', 'commercial'], 'createdBy' => 1],
        ];

        foreach ($propertyTypes as $type) {
            PropertyType::create($type);
        }
    }
}
