<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Property;
use App\Services\MailService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PropertyEmailController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected MailService $mailService
    ) {}

    /**
     * Send multiple properties in a single email to multiple contacts.
     * POST /api/send/email/multiple/properties
     * Body: { "propertyIds": [1,2,3], "contactEmails": ["a@b.com","c@d.com"] }
     */
    // public function sendEmailMultipleProperties(Request $request)
    // {
    //     $request->validate([
    //         'propertyIds' => 'required|array|min:1',
    //         'propertyIds.*' => 'required|integer|exists:properties,id',
    //         'contactEmails' => 'required|array|min:1',
    //         'contactEmails.*' => 'required|email',
    //     ]);

    //     $contacts = Contact::whereIn('email', $request->contactEmails)
    //         ->get(['email', 'name'])
    //         ->keyBy('email');

    //     $validContacts = $contacts->keys()->toArray();
    //     $skippedEmails = array_diff($request->contactEmails, $validContacts);

    //     if (empty($validContacts)) {
    //         return $this->error('None of the provided emails exist in contacts', 422);
    //     }

    //     $properties = Property::with(['media:id,propertyId,mediaType,compressedPath,originalPath,filePath,fileName', 'physicalOwner:id,name'])
    //         ->whereIn('id', $request->propertyIds)
    //         ->get();

    //     if ($properties->isEmpty()) {
    //         return $this->error('No properties found.', 422);
    //     }

    //     try {
    //         $allAttachments = [];
    //         $propertyListHtml = '';

    //         foreach ($properties as $property) {
    //             $attachmentPhotos = [];
    //             $attachmentVideos = [];
    //             $attachmentFloorPlans = [];
    //             $attachmentDocuments = [];

    //             foreach ($property->media as $mediaFile) {
    //                 try {
    //                     if (in_array($mediaFile->mediaType, ['uploadPhotos', 'uploadVideos'])) {
    //                         $storedPath = $mediaFile->compressedPath ?? $mediaFile->originalPath ?? $mediaFile->filePath;
    //                     } else {
    //                         $storedPath = $mediaFile->originalPath ?? $mediaFile->filePath;
    //                     }

    //                     if (empty($storedPath)) {
    //                         continue;
    //                     }

    //                     $relativePath = preg_replace('#^/?storage/#', '', $storedPath);
    //                     $filePath = storage_path('app/public/'.$relativePath);
    //                     $fileName = $mediaFile->fileName ?? basename($storedPath);

    //                     if (! file_exists($filePath)) {
    //                         continue;
    //                     }

    //                     $attachment = [
    //                         'path' => $filePath,
    //                         'name' => $fileName,
    //                         'url' => getImageUrl($storedPath),
    //                     ];

    //                     if ($mediaFile->mediaType === 'uploadPhotos') {
    //                         $attachmentPhotos[] = $attachment;
    //                     } elseif ($mediaFile->mediaType === 'uploadVideos') {
    //                         $attachmentVideos[] = $attachment;
    //                     } elseif ($mediaFile->mediaType === 'floorPlans') {
    //                         $attachmentFloorPlans[] = $attachment;
    //                     } elseif ($mediaFile->mediaType === 'uploadDocuments') {
    //                         $attachmentDocuments[] = $attachment;
    //                     }
    //                 } catch (\Exception $e) {
    //                     Log::error('PropertyEmailController : sendEmailMultipleProperties : processingMediaFile : '.$e->getMessage());

    //                     continue;
    //                 }
    //             }

    //             $allAttachments = array_merge($allAttachments, $attachmentPhotos, $attachmentVideos, $attachmentFloorPlans, $attachmentDocuments);

    //             $attachmentListHtml = view('EmailTemplate.partials.propertyAttachmentList', [
    //                 'photos' => $attachmentPhotos,
    //                 'videos' => $attachmentVideos,
    //                 'floorPlans' => $attachmentFloorPlans,
    //                 'documents' => $attachmentDocuments,
    //             ])->render();

    //             $location = collect([
    //                 $property->community,
    //                 $property->subCommunity,
    //                 $property->city,
    //                 $property->state,
    //                 $property->country,
    //             ])->filter()->implode(', ');

    //             $propertyListHtml .= '<hr style="border: 1px solid #e5e7eb; margin: 20px 0;">'
    //                 .'<p><strong>Property Information:</strong></p>'
    //                 .'<p>'
    //                 .'<strong>Reference Number:</strong> '.$property->id.'<br>'
    //                 .'<strong>Property Title:</strong> '.($property->title ?? 'N/A').'<br>'
    //                 .'<strong>Location:</strong> '.($location ?: 'N/A').'<br>'
    //                 .'<strong>Status:</strong> '.($property->status ?? 'N/A').'<br>'
    //                 .'<strong>Listing Price:</strong> '.($property->price ?? 'N/A')
    //                 .'</p>'
    //                 .'<p><strong>Attached Documents:</strong></p>'
    //                 .$attachmentListHtml;

    //             logPropertyHistory([
    //                 'propertyId' => $property->id,
    //                 'field' => 'email_sent',
    //                 'newValue' => json_encode(['message' => 'Email sent to '.count($validContacts).' contact(s) with multiple properties']),
    //             ]);
    //         }

    //         $companyName = getCompanyName();
    //         $emailSlug = Constants::EMAIL_SLUGS['property']['sent_multiple_property_details_contact'];
    //         $emailData = [
    //             'fullName' => '',
    //             'companyName' => $companyName,
    //             'propertyList' => $propertyListHtml,
    //             'companyEmail' => config('mail.from.address'),
    //             'companyPhone' => config('app.phone', ''),
    //         ];

    //         $mailService = $this->mailService;
    //         $propertyCount = $properties->count();
    //         $firstPropertyId = $properties->first()->id;

    //         // Send emails in background after response is sent
    //         defer(function () use ($validContacts, $contacts, $emailSlug, $emailData, $allAttachments, $mailService, $propertyCount, $firstPropertyId) {
    //             $totalEmailsSent = 0;

    //             foreach ($validContacts as $contactEmail) {
    //                 try {
    //                     $data = $emailData;
    //                     $data['fullName'] = ucwords($contacts[$contactEmail]->name ?? '');

    //                     $mailService->sendEmail(
    //                         $emailSlug,
    //                         $contactEmail,
    //                         $data,
    //                         $allAttachments
    //                     );
    //                     $totalEmailsSent++;
    //                 } catch (\Exception $e) {
    //                     Log::error('PropertyEmailController : sendEmailMultipleProperties : sendEmail : '.$e->getMessage());
    //                 }

    //             }

    //             logUserActivity([
    //                 'action' => 'sent_email',
    //                 'module' => Constants::MODULE_PROPERTY,
    //                 'moduleId' => $firstPropertyId,
    //                 'description' => "Sent {$propertyCount} properties email to {$totalEmailsSent} contact(s)",
    //             ]);
    //         });

    //         $response = [
    //             'total_contacts' => count($request->contactEmails),
    //             'valid_contacts' => count($validContacts),
    //             'total_properties' => $propertyCount,
    //         ];

    //         if (! empty($skippedEmails)) {
    //             $response['skipped_emails'] = array_values($skippedEmails);
    //             $response['skipped_count'] = count($skippedEmails);
    //         }

    //         $message = "Email is being sent to ".count($validContacts)." contact(s) with {$propertyCount} property(s)";

    //         return $this->success($response, $message);
    //     } catch (\Exception $e) {
    //         Log::error('PropertyEmailController : sendEmailMultipleProperties : '.$e->getMessage());

    //         return $this->error('Something went wrong. Please contact support for assistance.', 422);
    //     }
    // }

    public function sendEmailMultipleProperties(Request $request)
    {
        \Log::info('START: sendEmailMultipleProperties');

        $request->validate([
            'propertyIds' => 'required|array|min:1',
            'propertyIds.*' => 'required|integer|exists:properties,id',
            'contactEmails' => 'required|array|min:1',
            'contactEmails.*' => 'required|email',
        ]);

        $contacts = Contact::whereIn('email', $request->contactEmails)
            ->get(['email', 'name'])
            ->keyBy('email');

        $validContacts = $contacts->keys()->toArray();
        $skippedEmails = array_diff($request->contactEmails, $validContacts);

        if (empty($validContacts)) {
            \Log::warning('No valid contacts found');

            return $this->error('None of the provided emails exist in contacts', 422);
        }

        $properties = Property::with([
            'media:id,propertyId,mediaType,compressedPath,originalPath,filePath,fileName',
            'physicalOwner:id,name',
            'city:locationId,name',
            'community:communityId,name',
            'subCommunity:propertySubCommunityId,name',
            'buildingTower:propertyTowerId,name',
        ])
            ->whereIn('id', $request->propertyIds)
            ->get();

        if ($properties->isEmpty()) {
            \Log::warning('No properties found');

            return $this->error('No properties found.', 422);
        }

        try {
            $allAttachments = [];
            $propertyListHtml = '';

            foreach ($properties as $property) {

                \Log::info('Processing property', ['id' => $property->id]);

                $attachmentPhotos = [];
                $attachmentVideos = [];
                $attachmentFloorPlans = [];
                $attachmentDocuments = [];

                foreach ($property->media as $mediaFile) {
                    try {
                        if (in_array($mediaFile->mediaType, ['uploadPhotos', 'uploadVideos'])) {
                            $storedPath = $mediaFile->compressedPath ?? $mediaFile->originalPath ?? $mediaFile->filePath;
                        } else {
                            $storedPath = $mediaFile->originalPath ?? $mediaFile->filePath;
                        }

                        if (empty($storedPath)) {
                            continue;
                        }

                        $relativePath = preg_replace('#^/?storage/#', '', $storedPath);
                        $filePath = storage_path('app/public/'.$relativePath);

                        if (! file_exists($filePath)) {
                            \Log::warning('File not found', ['path' => $filePath]);

                            continue;
                        }

                        $attachment = [
                            'path' => $filePath,
                            'name' => $mediaFile->fileName ?? basename($storedPath),
                            'url' => getImageUrl($storedPath),
                        ];

                        if ($mediaFile->mediaType === 'uploadPhotos') {
                            $attachmentPhotos[] = $attachment;
                        } elseif ($mediaFile->mediaType === 'uploadVideos') {
                            $attachmentVideos[] = $attachment;
                        } elseif ($mediaFile->mediaType === 'floorPlans') {
                            $attachmentFloorPlans[] = $attachment;
                        } elseif ($mediaFile->mediaType === 'uploadDocuments') {
                            $attachmentDocuments[] = $attachment;
                        }
                    } catch (\Exception $e) {
                        \Log::error('Media processing error: '.$e->getMessage());
                    }
                }

                $allAttachments = array_merge(
                    $allAttachments,
                    $attachmentPhotos,
                    $attachmentVideos,
                    $attachmentFloorPlans,
                    $attachmentDocuments
                );

                $attachmentListHtml = view('EmailTemplate.partials.propertyAttachmentList', [
                    'photos' => $attachmentPhotos,
                    'videos' => $attachmentVideos,
                    'floorPlans' => $attachmentFloorPlans,
                    'documents' => $attachmentDocuments,
                ])->render();

                $propertyListHtml .= '<hr style="border: 1px solid #e5e7eb; margin: 20px 0;">'
                    .'<p><strong>Property Information:</strong></p>'
                    .'<p>'
                    .'<strong>Reference Number:</strong> '.($property->referenceNumber ?? 'N/A').'<br>'
                    .'<strong>Property Title:</strong> '.($property->title ?? 'N/A').'<br>'
                    .'<strong>City:</strong> '.($property->city?->name ?? 'N/A').'<br>'
                    .'<strong>Community:</strong> '.($property->community?->name ?? 'N/A').'<br>'
                    .'<strong>Sub Community:</strong> '.($property->subCommunity?->name ?? 'N/A').'<br>'
                    .'<strong>Tower:</strong> '.($property->buildingTower?->name ?? 'N/A').'<br>'
                    .'<strong>Status:</strong> '.($property->status ?? 'N/A').'<br>'
                    .'<strong>Listing Price:</strong> '.($property->price ?? 'N/A')
                    .'</p>'
                    .'<p><strong>Attached Documents:</strong></p>'
                    .$attachmentListHtml;

                logPropertyHistory([
                    'propertyId' => $property->id,
                    'field' => 'email_sent',
                    'newValue' => json_encode([
                        'message' => 'Email sent to '.count($validContacts).' contact(s)',
                    ]),
                ]);
            }

            $companyName = getCompanyName();
            $emailSlug = Constants::EMAIL_SLUGS['property']['sent_multiple_property_details_contact'];

            $emailData = [
                'fullName' => '',
                'companyName' => $companyName,
                'propertyList' => $propertyListHtml,
                'companyEmail' => config('mail.from.address'),
                'companyPhone' => config('app.phone', ''),
            ];

            $propertyCount = $properties->count();
            $firstPropertyId = $properties->first()->id;

            \Log::info('Sending emails to contacts', ['count' => count($validContacts)]);

            // ✅ DIRECT SEND (NO defer)
            $totalEmailsSent = 0;
            $agentUser = user();
            $fromEmail = $agentUser?->email;
            $fromName = $agentUser?->name;

            foreach ($validContacts as $contactEmail) {
                try {
                    $data = $emailData;
                    $data['fullName'] = ucwords($contacts[$contactEmail]->name ?? '');

                    $this->mailService->sendEmail(
                        $emailSlug,
                        $contactEmail,
                        $data,
                        $allAttachments,
                        [],
                        $fromEmail,
                        $fromName
                    );

                    $totalEmailsSent++;
                } catch (\Exception $e) {
                    \Log::error('Send email error: '.$e->getMessage());
                }
            }

            \Log::info('Emails sent', ['total' => $totalEmailsSent]);

            logUserActivity([
                'action' => 'sent_email',
                'module' => Constants::MODULE_PROPERTY,
                'moduleId' => $firstPropertyId,
                'description' => "Sent {$propertyCount} properties email to {$totalEmailsSent} contact(s)",
            ]);

            $response = [
                'total_contacts' => count($request->contactEmails),
                'valid_contacts' => count($validContacts),
                'total_properties' => $propertyCount,
            ];

            if (! empty($skippedEmails)) {
                $response['skipped_emails'] = array_values($skippedEmails);
                $response['skipped_count'] = count($skippedEmails);
            }

            return $this->success($response, 'Email sent successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyEmailController : sendEmailMultipleProperties', $e);
        }
    }
}
