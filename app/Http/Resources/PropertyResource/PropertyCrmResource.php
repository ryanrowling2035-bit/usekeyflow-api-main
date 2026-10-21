<?php

namespace App\Http\Resources\PropertyResource;

use App\Constants\Constants;
use App\Services\FileStorageService;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyCrmResource extends JsonResource
{
    /**
     * Combined Bayut + Property Finder amenity label map. Both amenity fields look their stored
     * slugs up against this merged set so either source's slug resolves regardless of field.
     * Keys don't overlap (camelCase vs kebab-case), so the union loses nothing.
     */
    private const AMENITY_LABELS = Constants::PROPERTY_BAYUT_AMENITIES_KEYS + Constants::PF_AMENITIES['all'];

    /**
     * document_centers.tags values (Constants::DOCUMENT_TAGS[property] keys) the website's
     * Documents section shows. Everything else — passports, title deeds, signed contracts,
     * master plans, site maps, … — stays out of the feed.
     */
    private const WEBSITE_DOCUMENT_TAGS = ['Floorplan', 'Brochure'];

    public function toArray($request)
    {
        $meta = $this->relationLoaded('meta')
            ? $this->meta->pluck('metaValue', 'metaKey')
            : collect();

        $flyer = $this->decodeJson($meta->get('flyerData'));
        $payments = $this->decodeJson($meta->get('paymentPlans'));
        $terms = $this->decodeJson($meta->get('termsConditions'));

        $managedTypes = array_filter(array_map(
            fn ($v) => trim(strtolower($v)),
            explode(',', (string) $this->managedType)
        ));

        // The "International / Off Plan" record type (0125e0000007uwxAAA) covers both off-plan
        // and international listings on the source side, mirroring how the import treats them as
        // one group, so populate it for either listing type rather than off-plan primary only.
        // Prefer the listing-type derivation; fall back to the imported source
        // Salesforce RecordTypeId when the listing isn't int'l/off-plan.
        $recordTypeId = in_array($this->listingType, Constants::PROPERTY_INT_AND_OFF_LISTING_TYPES, true)
            ? '0125e0000007uwxAAA'
            : $meta->get('recordTypeId');

        return [
            'data' => [
                'access__c' => $this->access,
                'currencyisocode' => 'UAE Dirham',
                'facilities__c' => $this->formatDescription($meta->get('facilities')),
                // flyerData may be a named-key object (flyerHighlightN, from the Salesforce
                // import) or a legacy positional array (from the CRM UI); read either shape.
                'flyer_highlight_1__c' => $flyer['flyerHighlight1'] ?? $flyer[0] ?? null,
                'flyer_highlight_2__c' => $flyer['flyerHighlight2'] ?? $flyer[1] ?? null,
                'flyer_highlight_3__c' => $flyer['flyerHighlight3'] ?? $flyer[2] ?? null,
                'flyer_highlight_4__c' => $flyer['flyerHighlight4'] ?? $flyer[3] ?? null,
                'flyer_highlight_5__c' => $flyer['flyerHighlight5'] ?? $flyer[4] ?? null,
                'furnished__c' => $this->humanize($meta->get('pfFurnishingType')),
                'managed_type__c' => $this->mapManagedType(),
                'metadesc__c' => null,
                'metakeywords__c' => null,
                'name' => $this->title,
                'num_baths__c' => formatDecimal($this->bathrooms),
                'num_beds__c' => $this->bedrooms !== null ? (string) $this->bedrooms : null,
                'ownership_term__c' => $this->ownershipTerm,
                'payment_plan_1_type__c' => data_get($payments, '0.type'),
                'payment_plan_2_type__c' => data_get($payments, '1.type'),
                'payment_plan_3_type__c' => data_get($payments, '2.type'),
                'payment_plan_4_type__c' => data_get($payments, '3.type'),
                'payment_schedule__c' => data_get($payments, '0.schedule'),
                'payment_plan_5_type__c' => data_get($payments, '4.type'),
                'payment_plan_6_type__c' => data_get($payments, '5.type'),
                'pba_uaefields__broker_s_listing_id__c' => $this->referenceNumber,
                'pba_uaefields__city_dubizzle__c' => optional($this->city)->name,
                'pba_uaefields__city_propertyfinder__c' => optional($this->city)->name,
                'pba_uaefields__commercial_amenities__c' => $this->mapAmenities($this->pfAmenities, self::AMENITY_LABELS),
                'pba_uaefields__community_propertyfinder__c' => optional($this->community)->name,
                'pba_uaefields__completion_status__c' => $this->humanizeCompletion(),
                'pba_uaefields__developer_name__c' => $meta->get('pfDeveloper'),
                'pba_uaefields__furnished__c' => $this->yesNoFurnished($meta->get('bayutFurnishingStatus') ?? $meta->get('pfFurnishingType')),
                'pba_uaefields__price_unit__c' => $this->humanizePriceFrequency(),
                'pba_uaefields__private_amenities__c' => $this->mapAmenities($this->pfAmenities, self::AMENITY_LABELS),
                'pba_uaefields__propertyfinder_region__c' => $meta->get('pfRegion'),
                'pba_uaefields__property_propertyfinder__c' => optional($this->buildingTower)->name,
                'pba_uaefields__property_sub_type__c' => $this->mapPropertySubType($meta->get('pfSubType')),
                'pba_uaefields__rera_permit_number__c' => $this->reraPermitNo,
                'pba_uaefields__sub_community_propertyfinder__c' => optional($this->subCommunity)->name,
                'pba_uaefields__view__c' => $this->view,
                'pba__address_pb__c' => $meta->get('propertyAddress'),
                'pba__area_pb__c' => $this->castFloat($this->sizeArea),
                'pba__city_pb__c' => optional($this->city)->name,
                'pba__country_pb__c' => $meta->get('propertyCountry'),
                // Prefer the flyer template description; fall back to the listing description when it's empty.
                // Normalized into discrete <p> paragraphs so the website's "first paragraph dark,
                // rest grey" styling has real paragraph boundaries to key off.
                'pba__description_pb__c' => $this->formatDescription($this->description ?: $meta->get('templateDescription')),
                'pba__geocodeaccuracy_pb__c' => $meta->get('geocodeAccuracy'),
                'pba__latitude_pb__c' => $this->pfLatitude,
                'pba__latitude_property__c' => $this->pfLatitude,
                'pba__listingtype__c' => $this->mapListingType(),
                'pba__listing_agent_email__c' => optional($this->agent)->email,
                'pba__listing_agent_firstname__c' => $this->getAgentFirstName(),
                'pba__listing_agent_lastname__c' => $this->getAgentLastName(),
                'pba__listing_agent_mobil_phone__c' => optional($this->agent)->phone,
                'pba__listing_agent_phone__c' => optional($this->agent)->phone,
                'pba__listing_agent_photo__c' => $this->agent && $this->agent->profilePhoto ? getImageUrl($this->agent->profilePhoto) : null,
                'pba__listing_website__c' => $meta->get('websiteUrl'),
                'pba__longitude_pb__c' => $this->pfLongitude,
                'pba__measurement_pb__c' => 'SQFT',
                'pba__propertytype__c' => $this->propertyTypeLabel() ?? $this->pfPropertyType ?? $this->bayutPropertyType,
                'pba__status__c' => $this->mapStatus(),
                'pba__street_pb__c' => null,
                'pba__unitnumber__c' => $meta->get('pfUnitNumber'),
                'pba__view_pb__c' => $this->view,
                'special_offer__c' => null,
                'type__c' => null,
                'transaction_number__c' => $meta->get('transactionNumber'),
                'unit_number__c' => $meta->get('pfUnitNumber'),
                'projected_roi__c' => null,
                'location_proximity__c' => $this->formatDescription($meta->get('locationProximityForWebsite')),
                'unit_type_1__c' => $meta->get('unitType1'),
                'unit_type_2__c' => $meta->get('unitType2'),
                'unit_type_3__c' => $meta->get('unitType3'),
                'unit_type_4__c' => $meta->get('unitType4'),
                'unit_type_5__c' => $meta->get('unitType5'),
                'unit_type_6__c' => $meta->get('unitType6'),
                'key_feature_for_website__c' => $this->formatDescription($meta->get('keyFeaturesForWebsite')),
                'createddate' => $this->formatDate($this->created_at),
                'annual_rental_yeild__c' => $this->castFloat($meta->get('annualRentalPrice')),
                'deposit__c' => $this->castFloat($this->deposit),
                'expire_on__c' => $meta->get('expireOn'),
                'id' => $meta->get('pfExternalId') ?? (string) $this->id,
                'keyflow_id' => (string) $this->id,
                'salesforce_id' => $this->relationLoaded('salesforceIdMap')
                    ? optional($this->salesforceIdMap)->salesforceId
                    : null,
                'isdeleted' => (bool) $this->deleted_at,
                'is_featured__c' => in_array(Constants::MANAGED_TYPE_FEATURE, $managedTypes, true),
                'is_hot__c' => false,
                'lastmodifieddate' => $this->formatDate($this->updated_at),
                'ownerid' => $this->createdBy !== null ? (string) $this->createdBy : null,
                // The stored shape is {value, type}; read the amount from N.value. Fall back to the
                // legacy 'plan' key so any manually-entered listing in that shape still resolves.
                'payment_plan_1__c' => data_get($payments, '0.value') ?? data_get($payments, '0.plan'),
                'payment_plan_2__c' => data_get($payments, '1.value') ?? data_get($payments, '1.plan'),
                'payment_plan_3__c' => data_get($payments, '2.value') ?? data_get($payments, '2.plan'),
                'payment_plan_4__c' => data_get($payments, '3.value') ?? data_get($payments, '3.plan'),
                'payment_plan_5__c' => data_get($payments, '4.value') ?? data_get($payments, '4.plan'),
                'payment_plan_6__c' => data_get($payments, '5.value') ?? data_get($payments, '5.plan'),
                'pba_uaefields__available_from__c' => $meta->get('pfAvailableFrom'),
                'pba_uaefields__available_to__c' => $meta->get('pfAvailableTo'),
                'pba_uaefields__parking__c' => $this->castFloat($this->parkingSpaces),
                'pba_uaefields__price_on_request__c' => filter_var($meta->get('pfPriceOnRequest'), FILTER_VALIDATE_BOOLEAN),
                'pba__allowedforautopublish__c' => false,
                'pba__bedrooms_pb__c' => $this->castFloat($this->bedrooms),
                'pba__completiondate_pb__c' => $meta->get('completionDate'),
                'pba__fullbathrooms_pb__c' => $this->castFloat($this->bathrooms),
                'pba__furnished_pb__c' => $this->boolFurnished($meta->get('bayutFurnishingStatus') ?? $meta->get('pfFurnishingType')),
                // Listings quoted as a range carry no single price; fall back to the range's lower
                // bound from the termsConditions blob ({currency, price, priceTo}).
                'pba__listingprice_pb__c' => $this->castFloat($this->price) ?? $this->castFloat(data_get($terms, 'price')),
                'pba__lotsize_pb__c' => $this->castFloat($this->plotSize),
                'pba__solddate__c' => null,
                'pba__systemallowedforportals__c' => filter_var($meta->get('systemAllowedForPortals'), FILTER_VALIDATE_BOOLEAN),
                'pba__systemisarchived__c' => false,
                'pba__totalarea_pb__c' => $this->castFloat($this->sizeArea),
                // Upper bound of the asking range, from the termsConditions meta blob
                // ({currency, price, priceTo}); null on listings quoted at a single price.
                'price_to__c' => $this->castFloat(data_get($terms, 'priceTo')),
                'security_deposit__c' => $this->castFloat($this->deposit),
                'systemmodstamp' => $this->formatDate($this->updated_at),
                'recordtypeid' => $recordTypeId,
                'pba__numberoffloors__c' => $this->castFloat($meta->get('pfNumberOfFloors')),
                'pba_uaefields__service_charge__c' => null,
                'built_up_area_unit_type_1__c' => $meta->get('unitTypeBuildUpArea1'),
                'built_up_area_unit_type_2__c' => $meta->get('unitTypeBuildUpArea2'),
                'built_up_area_unit_type_3__c' => $meta->get('unitTypeBuildUpArea3'),
                'built_up_area_unit_type_4__c' => $meta->get('unitTypeBuildUpArea4'),
                'built_up_area_unit_type_5__c' => $meta->get('unitTypeBuildUpArea5'),
                'built_up_area_unit_type_6__c' => $meta->get('unitTypeBuildUpArea6'),
                'price_starting_from_unit_type_1__c' => $meta->get('unitTypePrice1'),
                'price_starting_from_unit_type_2__c' => $meta->get('unitTypePrice2'),
                'projected_sales_price__c' => $this->castFloat($meta->get('propertySalesValue')),
                'price_starting_from_unit_type_3__c' => $meta->get('unitTypePrice3'),
                'price_starting_from_unit_type_4__c' => $meta->get('unitTypePrice4'),
                'price_starting_from_unit_type_5__c' => $meta->get('unitTypePrice5'),
                'price_starting_from_unit_type_6__c' => $meta->get('unitTypePrice6'),
                'pba_uaefields__stories__c' => $this->castFloat($meta->get('pfNumberOfFloors')),
            ],

            'media' => [
                'images' => $this->getImages(),
                'videos' => $this->getVideos(),
                'documents' => $this->getDocuments(),
                'qrCode' => $this->getQrImage(),
            ],
        ];
    }

    private function getAgentFirstName()
    {
        $name = optional($this->agent)->name;
        if (! $name) {
            return null;
        }

        return explode(' ', $name)[0] ?? null;
    }

    private function getAgentLastName()
    {
        $name = optional($this->agent)->name;

        if (! $name) {
            return null;
        }

        $parts = explode(' ', trim($name));

        array_shift($parts);

        return ! empty($parts) ? implode(' ', $parts) : null;
    }

    private function mapStatus()
    {
        $labels = Constants::PROPERTY_STATUS + Constants::PROPERTY_INT_AND_OFF_STATUS;

        return $labels[$this->status] ?? $this->status;
    }

    /**
     * Inverse of the import PURPOSE_MAP: emit the original Salesforce ListingType string so the
     * feed keeps Off Plan and International distinct (the import maps them to separate listing
     * types, so the export must not collapse them back into one).
     */
    private function mapListingType()
    {
        return match ($this->listingType) {
            Constants::PROPERTY_PURPOSE_SALE => 'Sale',
            Constants::PROPERTY_PURPOSE_RENT => 'Rent',
            Constants::PROPERTY_PURPOSE_OFFPLAN => 'Off Plan',
            Constants::PROPERTY_PURPOSE_INTERNATIONAL => 'International',
            default => $this->listingType !== null ? ucfirst($this->listingType) : null,
        };
    }

    /**
     * Inverse of the import MANAGED_MAP: turn the stored canonical key(s) back into the
     * human-readable Salesforce label(s) (e.g. vacationHomes => "Vacation Homes") so a
     * "contains Vacation Homes" rule on the consumer side matches.
     */
    private function mapManagedType()
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $this->managedType)));
        $labels = array_map(fn ($p) => Constants::PROPERTY_MANAGED_TYPE[$p] ?? $p, $parts);

        return $labels ? implode(', ', $labels) : null;
    }

    /**
     * Inverse of the import resolveType(): rebuild the "{Category} - {Type}" sub-type string from
     * the stored category + property type so the consumer's "Property Sub Type contains Commercial"
     * classification works off the feed. Falls back to any explicit pfSubType meta, then the bare
     * category label so commercial detection still fires even when the type can't be resolved.
     */
    private function mapPropertySubType(?string $metaSubType = null)
    {
        if ($metaSubType) {
            return $metaSubType;
        }

        $categoryLabel = Constants::PROPERTY_CLASSIFICATION_TYPE[$this->category] ?? null;
        $typeLabel = $this->propertyTypeLabel();

        if ($typeLabel === null) {
            return $categoryLabel;
        }

        return $categoryLabel ? "{$categoryLabel} - {$typeLabel}" : $typeLabel;
    }

    /** Resolve the stored pf/bayut type slug back to its mapping label, scoped by category. */
    private function propertyTypeLabel(): ?string
    {
        foreach (Constants::PROPERTY_TYPE_MAPPING as $row) {
            if (($row['category'] ?? null) !== $this->category) {
                continue;
            }
            if ($this->pfPropertyType !== null && ($row['pf'] ?? null) === $this->pfPropertyType) {
                return $row['label'];
            }
            if ($this->bayutPropertyType !== null && ($row['bayut'] ?? null) === $this->bayutPropertyType) {
                return $row['label'];
            }
        }

        return null;
    }

    private function humanizeCompletion()
    {
        if (! $this->completionStatus) {
            return null;
        }

        return Constants::COMPLETION_STATUS[$this->completionStatus] ?? $this->completionStatus;
    }

    private function humanizePriceFrequency()
    {
        if (! $this->pfPriceFrequency) {
            return null;
        }

        return Constants::PROPERTY_PRICE_FREQUENCIES[$this->pfPriceFrequency] ?? $this->pfPriceFrequency;
    }

    private function humanize($val)
    {
        return $val ? ucfirst($val) : null;
    }

    private function yesNoFurnished($val)
    {
        if (! $val) {
            return null;
        }

        return strtolower($val) === 'unfurnished' ? 'No' : 'Yes';
    }

    private function boolFurnished($val)
    {
        if (! $val) {
            return false;
        }

        return strtolower($val) !== 'unfurnished';
    }

    /**
     * Map stored amenity keys/slugs back to their human-readable labels and join with ';' — the
     * inverse of the import, so the feed emits the Private/Commercial Amenities labels Salesforce
     * expects rather than the internal pf/bayut codes. Unmapped values pass through unchanged.
     */
    private function mapAmenities($values, array $labels): ?string
    {
        if (! is_array($values) || $values === []) {
            return null;
        }

        return implode(';', array_map(fn ($v) => $labels[$v] ?? $v, $values));
    }

    private function castFloat($val)
    {
        if ($val === null || $val === '') {
            return null;
        }

        return is_numeric($val) ? (float) $val : null;
    }

    private function decodeJson($val): array
    {
        if (! $val) {
            return [];
        }
        if (is_array($val)) {
            return $val;
        }
        $decoded = json_decode($val, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Normalize a listing description into a sequence of clean <p> paragraphs. The source is
     * inconsistent — some descriptions already use <p> tags, most are a single <p> with <br>
     * breaks, a few are plain text — so every block/line boundary (p, div, br, li, ul/ol, h1–h6)
     * is treated as a paragraph split, inline emphasis is kept, and each non-empty line is
     * re-wrapped in its own <p>. This gives the website's "first paragraph dark, rest grey"
     * styling real paragraph boundaries regardless of how the description was authored.
     */
    private function formatDescription($html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        // A whitespace/nbsp-only <span> is the rich-text editor's rendering of an indent tab
        // (the "-<span>&nbsp;&nbsp;…</span>Heading" section markers); restore it as a real tab.
        $text = preg_replace('#<span\b[^>]*>(?:\s|\xC2\xA0)*</span>#i', "\t", $html);

        // <br> and block-level closing tags become CRLF line breaks so the description keeps its
        // paragraph/section structure as plain text instead of HTML markup.
        $text = preg_replace('#<br\s*/?>#i', "\r\n", $text);
        $text = preg_replace('#</(?:p|div|li|ul|ol|h[1-6])\s*>#i', "\r\n", $text);

        // Drop every remaining tag, then decode entities (&quot; &#39; &amp; …) to plain text.
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);

        // Trim leading indentation on each line — keeps the "-\t" markers but drops stray leading
        // whitespace (e.g. a leftover tab before a paragraph) — then strip trailing blank lines.
        $lines = array_map(fn ($line) => ltrim($line, " \t"), explode("\r\n", $text));
        $text = rtrim(implode("\r\n", $lines));

        return $text === '' ? null : $text;
    }

    private function formatDate($date): ?string
    {
        if (! $date) {
            return null;
        }

        return $date->format('Y-m-d\TH:i:s').'.000Z';
    }

    private function getImages()
    {
        if (! $this->relationLoaded('mediaThumbnails')) {
            return [];
        }

        $items = [];
        foreach ($this->mediaThumbnails as $media) {
            $items[] = $this->buildMediaItem($media);
        }

        return $items;
    }

    /**
     * The listing's government permit QR-code image, read from the properties.govtQR column (an
     * uploaded image URL/key — the same field the XML feed emits as qr_code). Surfaced so the
     * exclusive-links website can render it. Null when the listing has no QR on file.
     */
    private function getQrImage(): ?string
    {
        return $this->govtQR ? getImageUrl($this->govtQR) : null;
    }

    /**
     * Videos are stored as external URLs in the property_videos table (YouTube/Vimeo/etc.),
     * not as uploaded files in the media table — so read them from the videos relation
     * rather than filtering media on mediaType.
     */
    private function getVideos()
    {
        if (! $this->relationLoaded('videos')) {
            return [];
        }

        $items = [];
        foreach ($this->videos as $video) {
            if (! $video->videoUrl) {
                continue;
            }
            $items[] = $this->buildVideoItem($video);
        }

        return $items;
    }

    /**
     * Shape a property_videos row for the feed. filename/mimetype/baseurl don't apply to a
     * hosted URL, so they're omitted; the consumer reads url + title + isDefault.
     */
    private function buildVideoItem($video): array
    {
        return [
            'title' => $video->title,
            'tags' => $video->platform,
            'url' => $video->videoUrl,
            'isDefault' => (bool) $video->isDefault,
        ];
    }

    /**
     * Floor plans and brochures only, from the Document Center (module = property). Filtering on the
     * tags (not docType or the title) covers both sources: the property-media import stores the
     * verbatim Salesforce labels, and CRM uploads validate against the same DOCUMENT_TAGS keys —
     * whereas docType is only ever set for the handful of labels in LISTING_DOCUMENT_TYPE, so a
     * brochure has none. document_centers rows live in the private bucket, so the URL is signed
     * (temporaryUrl), mirroring DocumentCenterController; legacy "document-center/..." public keys
     * pass through getImageUrl instead.
     */
    private function getDocuments()
    {
        $items = [];

        if ($this->relationLoaded('documentCenters')) {
            $files = app(FileStorageService::class);
            foreach ($this->documentCenters as $document) {
                $tags = is_array($document->tags) ? $document->tags : [];
                if (array_intersect($tags, self::WEBSITE_DOCUMENT_TAGS) === []) {
                    continue;
                }
                $items[] = $this->buildDocumentCenterItem($document, $files);
            }
        }

        return $items;
    }

    /**
     * Shape a document_centers row for the feed. Public documents store a full public-bucket URL
     * served as-is; legacy CRM uploads store a "document-center/..." public key served directly;
     * private documents (incl. imported rows) store a private-bucket key/URL, signed on read. The
     * website reads the download link from `url`.
     */
    private function buildDocumentCenterItem($document, FileStorageService $files): array
    {
        $filePath = $document->filePath;
        $url = $filePath && (str_starts_with($filePath, 'document-center/') || $document->isPublic)
            ? getImageUrl($filePath)
            : $files->temporaryUrl($filePath);

        $filename = $document->title;

        // document_centers.tags is a JSON array (cast to array on the model). Null when untagged.
        $tags = $document->tags ?? null;

        return [
            'title' => $filename,
            'filename' => $filename,
            'tags' => is_array($tags) && $tags !== [] ? implode(',', array_values($tags)) : null,
            'mimetype' => $this->guessMimeType($filename),
            'url' => $url,
            'baseurl' => $url ? preg_replace('#/[^/]+$#', '', $url) : null,
            'isPublic' => (bool) $document->isPublic,
        ];
    }

    private function buildMediaItem($media): array
    {
        $url = getImageUrl($media->originalPath ?? $media->filePath);
        $filename = $media->fileName;

        // property_media_files.tags is a JSON array of the verbatim Salesforce labels
        // (Constants::IMAGE_TAGS), cast to array on the model. Null when the image is untagged.
        $tags = $media->tags ?? null;

        return [
            'title' => $filename,
            'filename' => $filename,
            'tags' => is_array($tags) && $tags !== [] ? implode(',', array_values($tags)) : null,
            'mimetype' => $this->guessMimeType($filename),
            'url' => $url,
            'baseurl' => $url ? preg_replace('#/[^/]+$#', '', $url) : null,
        ];
    }

    private function guessMimeType(?string $filename): ?string
    {
        if (! $filename) {
            return null;
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'pdf' => 'application/pdf',
            'doc', 'docx' => 'application/msword',
            'xls', 'xlsx' => 'application/vnd.ms-excel',
            default => 'application/octet-stream',
        };
    }
}
