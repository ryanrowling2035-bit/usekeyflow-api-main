<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\Offer;
use setasign\Fpdi\Fpdi;

class OfferPdfService
{
    /**
     * Generate offer letter PDF by filling data on the existing PDF template.
     */
    public function generatePdf(Offer $offer, string $type = 'buyer'): string
    {
        $offer->loadMissing(['property', 'buyer', 'creator', 'sellerLandlordContact', 'assignedAgent']);

        $templatePath = $this->getTemplatePath($type);
        $data = $this->buildData($offer, $type);

        $pdf = new Fpdi;
        $pageCount = $pdf->setSourceFile($templatePath);

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $tplId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($tplId);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tplId);

            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(0, 0, 0);

            if ($pageNo === 1) {
                match ($type) {
                    Constants::OFFER_LETTER_TYPE_SELLER => $this->fillSellerPdf($pdf, $data),
                    Constants::OFFER_LETTER_TYPE_LANDLORD => $this->fillLandlordPdf($pdf, $data),
                    Constants::OFFER_LETTER_TYPE_TENANT => $this->fillTenantPdf($pdf, $data),
                    default => $this->fillBuyerPdf($pdf, $data),
                };
            } elseif ($type === Constants::OFFER_LETTER_TYPE_LANDLORD) {
                $this->fillLandlordSignaturePdf($pdf, $data);
            } elseif ($type === Constants::OFFER_LETTER_TYPE_TENANT) {
                $this->fillTenantSignaturePdf($pdf, $data);
            }
        }

        return $pdf->Output('S');
    }

    private function getTemplatePath(string $type): string
    {
        return match ($type) {
            Constants::OFFER_LETTER_TYPE_SELLER => public_path('offerPdf/offerLetterSeller.pdf'),
            Constants::OFFER_LETTER_TYPE_LANDLORD => public_path('offerPdf/offerLetterLandlord.pdf'),
            Constants::OFFER_LETTER_TYPE_TENANT => public_path('offerPdf/offerLetterTenant.pdf'),
            default => public_path('offerPdf/offerLetterBuyer.pdf'),
        };
    }

    private function setRentalFont(Fpdi $pdf): void
    {
        $pdf->SetFont('Helvetica', '', 11);
    }

    /**
     * Fill buyer offer letter PDF fields.
     */
    private function fillBuyerPdf(Fpdi $pdf, array $data): void
    {
        // Section 1: Buyer
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Text(43, 34.5, $data['name1']);       // Name 1
        $pdf->Text(128, 34.5, $data['mobile']);      // Mobile
        $pdf->Text(55, 59, $data['name2']);           // Name 2
        $pdf->Text(128, 42, $data['email']);          // Email

        $address = implode(' > ', array_filter([$data['pfCity'], $data['pfCommunity'], $data['pfSubCommunity'], $data['pfBuilding']]));
        // Section 2: Property
        $pdf->Text(43, 54, $address);         // Address
        $pdf->Text(130, 54, $data['bedrooms']);       // Bedrooms
        $pdf->Text(55, 78.5, $data['developer']);     // Developer
        $pdf->Text(130, 60.5, $data['areaSqft']);     // Area/Sqft
        // Section 3: Offer
        $pdf->Text(60, 72, $data['offerPriceFormatted']);    // Purchase Price
        $pdf->Text(150, 72, $data['listingPriceFormatted']); // All inclusive price

        // Section 4: Deposit
        $pdf->Text(58, 81, $data['depositFormatted']);   // Total Deposit

        // Section 6: Finance
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Text(38, 191, $data['financeAmountFormatted']); // AED amount

        // Section 7: Closing Date
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Text(83, 202, $data['closingDate']);       // Transfer date

        // Section 8: Signing - offer date
        $pdf->Text(126, 234, $data['offerDate']);         // Offer letter date
    }

    /**
     * Fill seller offer letter PDF fields.
     */
    private function fillSellerPdf(Fpdi $pdf, array $data): void
    {
        // Section 1: Seller
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Text(43, 35.5, $data['name1']);       // Name 1
        $pdf->Text(128, 35.5, $data['mobile']);      // Mobile
        $pdf->Text(55, 59, $data['name2']);           // Name 2
        $pdf->Text(128, 42, $data['email']);          // Email

        $address = implode(' > ', array_filter([$data['pfCity'], $data['pfCommunity'], $data['pfSubCommunity'], $data['pfBuilding']]));
        // Section 2: Property
        $pdf->Text(43, 55, $address);         // Address
        $pdf->Text(132, 55, $data['bedrooms']);       // Bedrooms
        $pdf->Text(55, 78.5, $data['developer']);     // Developer
        $pdf->Text(132, 61.5, $data['areaSqft']);     // Area/Sqft
        // Section 3: Offer
        $pdf->Text(58, 73, $data['offerPriceFormatted']);    // Purchase Price
        $pdf->Text(160, 73, $data['listingPriceFormatted']); // All inclusive price

        // Section 4: Deposit
        $pdf->Text(59, 82, $data['depositFormatted']);   // Total Deposit

        // Section 6: Finance
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Text(38, 191, $data['financeAmountFormatted']); // AED amount

        // Section 7: Closing Date
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Text(83, 201, $data['closingDate']);       // Transfer date

        // Section 8: Signing - offer date
        $pdf->Text(126, 234, $data['offerDate']);        // Offer letter date
    }

    /**
     * Fill tenant offer letter PDF fields.
     */
    private function fillTenantPdf(Fpdi $pdf, array $data): void
    {
        $this->setRentalFont($pdf);

        // Section 1: Applicant Details
        $this->writeText($pdf, 60, 94.5, $data['name1']);
        $this->writeText($pdf, 64, 100.2, $data['mobile']);
        $this->writeText($pdf, 61, 106.5, $data['email']);
        $this->writeText($pdf, 57, 112.5, $data['nationality']);
        $this->writeText($pdf, 75, 118.5, $data['occupationEmployer']);

        // Section 2: Property Details
        $this->writeText($pdf, 69, 143.2, $data['propertyReference']);
        $this->writeText($pdf, 66, 149.2, $data['propertyAddress'], 70);
        $this->writeText($pdf, 73, 155.2, $data['bedrooms']);

        // Section 3: Proposed Offer Terms
        $this->writeText($pdf, 68, 180.2, $data['offerPriceFormatted']);
        $this->writeText($pdf, 71, 186.2, $data['numberOfCheques']);
        $this->writeText($pdf, 75, 192, $data['depositFormatted']);
        $this->writeText($pdf, 67, 198, $data['agencyFeeFormatted']);
        $this->writeText($pdf, 84, 204, $data['closingDate']);
        $this->writeText($pdf, 59, 209.5, $data['leasePeriod']);
        $this->writeText($pdf, 74, 215.5, $data['numberOfOccupants']);
        $this->writeText($pdf, 45, 221.5, $data['pets']);
        $this->writeText($pdf, 67, 227.5, $data['specialConditions'], 80);
    }

    /**
     * Fill landlord offer summary PDF fields.
     */
    private function fillLandlordPdf(Fpdi $pdf, array $data): void
    {
        $this->setRentalFont($pdf);

        // Section 1: Tenant Details
        $this->writeText($pdf, 64, 97.7, $data['tenantName']);
        $this->writeText($pdf, 57, 103.7, $data['nationality']);
        $this->writeText($pdf, 75, 109.7, $data['occupationEmployer']);
        $this->writeText($pdf, 74, 115.2, $data['numberOfOccupants']);
        $this->writeText($pdf, 45, 121.2, $data['pets']);

        // Section 2: Property Details
        $this->writeText($pdf, 69, 146.2, $data['propertyReference']);
        $this->writeText($pdf, 66, 152.3, $data['propertyAddress'], 70);
        $this->writeText($pdf, 60, 158.3, $data['propertyType']);
        $this->writeText($pdf, 64, 164.3, $data['closingDate']);

        // Section 3: Offer Terms
        $this->writeText($pdf, 68, 189, $data['offerPriceFormatted']);
        $this->writeText($pdf, 71, 195, $data['numberOfCheques']);
        $this->writeText($pdf, 75, 201, $data['depositFormatted']);
        $this->writeText($pdf, 59, 207, $data['leasePeriod']);
        $this->writeText($pdf, 85, 213, $data['specialConditions'], 70);
    }

    private function fillTenantSignaturePdf(Fpdi $pdf, array $data): void
    {
        $this->setRentalFont($pdf);
        $this->writeText($pdf, 64, 115, $data['advisorName']);
        $this->writeText($pdf, 106, 115, $data['offerDate']);
    }

    private function fillLandlordSignaturePdf(Fpdi $pdf, array $data): void
    {
        $this->setRentalFont($pdf);
        $this->writeText($pdf, 64, 36.7, $data['advisorName']);
        $this->writeText($pdf, 111, 36.7, $data['offerDate']);
    }

    /**
     * Build data array from the offer model.
     */
    private function buildData(Offer $offer, string $type): array
    {
        $property = $offer->property;
        // A buyer merged into another contact appears on the letter as the surviving
        // contact — the offer still stores the alias's buyerId, merging moves nothing.
        $buyer = $offer->buyer?->masterOrSelf();
        $isOwnerOffer = in_array($type, [
            Constants::OFFER_LETTER_TYPE_SELLER,
            Constants::OFFER_LETTER_TYPE_LANDLORD,
        ], true);

        if ($property) {
            $property->load(['community', 'subCommunity', 'city', 'buildingTower', 'physicalOwner', 'agent']);
        }

        $offeredAmount = $offer->offeredAmount ?? 0;
        $depositAmount = $offer->deposits ?? ($offeredAmount * 10 / 100);
        $financeAmount = $offeredAmount * 75 / 100;
        $sellerLandlord = ($offer->sellerLandlordContact ?? $property?->physicalOwner)?->masterOrSelf();
        $tenantName = $buyer?->name ?? '';
        $advisor = $offer->assignedAgent ?? $property?->agent ?? $offer->creator;
        $address = implode(' > ', array_filter([$property?->city?->name, $property?->community?->name, $property?->subCommunity?->name, $property?->buildingTower?->name]));

        return [
            // Section 1: Buyer/Seller
            'name1' => $isOwnerOffer
                ? ($sellerLandlord?->name ?? $offer->sellerLandlord ?? '')
                : $tenantName,
            'name2' => '',
            'mobile' => $isOwnerOffer
                ? ($sellerLandlord?->phone ?? '')
                : ($buyer?->phone ?? ''),
            'email' => $isOwnerOffer
                ? ($sellerLandlord?->email ?? '')
                : ($buyer?->email ?? ''),
            'tenantName' => $tenantName,
            'nationality' => $buyer?->nationality ?? '',
            'occupationEmployer' => $buyer?->companyName ?? $buyer?->company ?? $buyer?->title ?? '',

            // Section 2: Property
            'pfCity' => $property?->city?->name ?? '',
            'pfCommunity' => $property?->community?->name ?? '',
            'pfSubCommunity' => $property?->subCommunity?->name ?? '',
            'pfBuilding' => $property?->buildingTower?->name ?? '',
            'propertyAddress' => $address,
            'propertyReference' => $property?->referenceNumber ?? $offer->referenceNo ?? '',
            'propertyType' => $property?->category ?? $property?->pfPropertyType ?? '',
            'developer' => $property?->developer ?? '',
            'bedrooms' => (string) ($property?->bedrooms ?? ''),
            'areaSqft' => $property?->sizeArea ? number_format($property->sizeArea) : '',

            // Section 3: Offer
            'offerPriceFormatted' => number_format($offeredAmount, 2),
            'listingPriceFormatted' => number_format($offer->listingPrice ?? 0, 2),

            // Section 4: Deposit
            'depositFormatted' => number_format($depositAmount, 2),
            'agencyFeeFormatted' => $offer->potentialCommission !== null
                ? number_format($offer->potentialCommission, 2)
                : ($property?->agencyFee ? number_format($property->agencyFee, 2) : ''),
            'numberOfCheques' => (string) ($property?->pfCheques ?? ''),
            'leasePeriod' => $offer->leaseTerms ?? '',
            'numberOfOccupants' => '',
            'pets' => '',
            'specialConditions' => $offer->terms ?? '',

            // Section 6: Finance
            'financeAmountFormatted' => number_format($financeAmount, 2),

            // Section 7: Closing Date
            'closingDate' => $offer->targetDate ?? '',

            // Section 8: Signing
            'offerDate' => $offer->offerDate ?? $offer->created_at?->format('Y-m-d') ?? '',
            'advisorName' => $advisor?->name ?? '',
        ];
    }

    private function writeText(Fpdi $pdf, float $x, float $y, mixed $value, int $maxLength = 45): void
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return;
        }

        if (strlen($text) > $maxLength) {
            $text = substr($text, 0, max(0, $maxLength - 3)).'...';
        }

        $pdf->Text($x, $y, $text);
    }
}
