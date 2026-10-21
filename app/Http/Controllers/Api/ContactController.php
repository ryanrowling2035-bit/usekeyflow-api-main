<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResources\GetAllContactResource;
use App\Http\Resources\ContactResources\GetClosingByContactResource;
use App\Http\Resources\ContactResources\GetContactByUserId;
use App\Http\Resources\ContactResources\GetContactResource;
use App\Http\Resources\ContactResources\GetEnquiryByContactResource;
use App\Http\Resources\ContactResources\GetListingByContactResource;
use App\Http\Resources\ContactResources\GetOfferByContactResource;
use App\Jobs\NotifyDataExportJob;
use App\Mail\DynamicMail;
use App\Models\Contact;
use App\Models\Enquiry;
use App\Models\EnquiryLinkedListing;
use App\Models\Property;
use App\Models\User;
use App\Services\ContactNoteService;
use App\Services\ContactService;
use App\Services\MailService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ContactService $contactService,
        protected MailService $mailService,
        protected ContactNoteService $contactNoteService
    ) {}

    public function selectContacts(Request $request)
    {
        try {
            // A contact merged into another is never offered as a choice — picking it
            // would attach the new record to an alias instead of the surviving contact.
            $query = Contact::select('id', 'name', 'email', 'phone', 'nationality', 'residencyStatus', 'source')
                ->notMerged();

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            }

            if ($request->filled('propertyLinkedEnquiryContacts')) {
                $propertyId = $request->input('propertyLinkedEnquiryContacts');
                $contactIds = Enquiry::whereHas('linkedListings', function ($q) use ($propertyId) {
                    $q->where('propertyId', $propertyId);
                })->pluck('contactId')->unique()->toArray();

                $query->whereIn('id', $contactIds);
            } elseif ($request->filled('enquiryLinkedPropertyContacts')) {
                $enquiryId = $request->input('enquiryLinkedPropertyContacts');
                $contactIds = EnquiryLinkedListing::where('enquiryId', $enquiryId)
                    ->pluck('propertyId')->unique()->toArray();

                $ownerIds = Property::whereIn('id', $contactIds)
                    ->pluck('physicalOwnerId')->unique()->toArray();

                $query->whereIn('id', $ownerIds);
            } elseif (isAgent()) {
                $query->accessibleByAgent(user()->id);
            }

            if ($request->filled('minOffers')) {
                $query->has('offers', '>=', (int) $request->input('minOffers'));
            }

            if ($request->filled('minClosings')) {
                $query->has('closings', '>=', (int) $request->input('minClosings'));
            }

            if ($request->filled('minEnquiry')) {
                $query->has('enquiries', '>=', (int) $request->input('minEnquiry'));
            }

            $contacts = $query->orderBy('name')->limit(30)->get()->map(function ($contact) {
                return [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'identifier' => $contact->name.' - '.$contact->email.' - '.$contact->phone,
                    'nationality' => $contact->nationality,
                    'residencyStatus' => $contact->residencyStatus,
                    'source' => $contact->source,
                ];
            });

            return $this->success($contacts, 'Contacts retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : selectContacts', $e);
        }
    }

    public function getAllContacts(Request $request)
    {
        try {
            $contacts = $this->contactService->getAll($request);

            return $this->success(
                GetAllContactResource::collection($contacts),
                'Contacts retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : getAllContacts', $e);
        }
    }

    public function addContact(Request $request)
    {
        // contactType is multi-value; accept a single string / comma-separated value
        // too and normalise to a clean array for backward compatibility.
        if ($request->has('contactType')) {
            $request->merge(['contactType' => Contact::normalizeContactTypes($request->input('contactType'))]);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'salutation' => 'nullable',
            'contactType' => 'nullable|array',
            'contactType.*' => 'in:'.implode(',', array_keys(Constants::CONTACT_TYPE)),
            'contactCategory' => 'nullable|in:'.implode(',', array_keys(Constants::CONTACT_CATEGORIES)),
            // A phone number is deliberately not unique: two people genuinely share
            // one (a couple, a family, an office line). The contact is created either
            // way and the response carries isDuplicate so the UI can offer a merge.
            'phone' => 'nullable|string',
            'alternatePhone' => 'nullable',
            'otherPhone' => 'nullable|string|max:20',
            // Email is not unique either. Salesforce reuses generic addresses
            // (info@, sales@) across genuinely different people, which is why the
            // unique index was dropped in June — the validator outlived it and was
            // still blocking saves the database happily allows.
            'email' => 'nullable|email|max:255',
            'source' => ['nullable', 'string', fn ($attr, $value, $fail) => Constants::isKnownLeadSource($value) ?: $fail('The selected source is not a recognised lead source.')],
            'contactSource' => 'nullable|string|max:255',
            'dob' => 'nullable|date',
            'notes' => 'nullable',
            'address' => 'nullable',
            'nationality' => 'nullable|string|max:255',
            'residencyStatus' => 'nullable|string|max:50',
            'contactProfile' => 'nullable',
            'contactOwner' => 'nullable|exists:users,id',
            'leadSourceDetails' => 'nullable|string|max:255',
            'bayutWhatsappInitiated' => 'nullable|boolean',
            'tenantWhatsappInitiated' => 'nullable|boolean',
            'offPlanWhatsappInitiated' => 'nullable|boolean',
            'lastContacted' => 'nullable|date',
            'routingSource' => 'nullable|string|max:255',
            'outcome' => 'nullable|string',
            'qualificationContact' => 'nullable|string',
            'leadRating' => 'nullable|string',
            'whatsappLead' => 'nullable|boolean',
            'referralSource' => 'nullable|string|max:255',
            'referralCompany' => 'nullable|string|max:255',
            'referralPercentDue' => 'nullable|string|max:50',
            'referralContact' => 'nullable|string|max:255',
            'referralFeePaidThru' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'zipCode' => 'nullable|string|max:20',
            'otherAddress' => 'nullable|string|max:1000',
            'otherCountry' => 'nullable|string|max:255',
            'otherState' => 'nullable|string|max:255',
            'otherCity' => 'nullable|string|max:255',
            'otherZipCode' => 'nullable|string|max:20',
            'company' => 'nullable|string|max:255',
            'pastClient' => 'nullable|boolean',
            'title' => 'nullable|string|max:255',
            'communityPreference' => 'nullable|array',
            'communityPreference.*' => 'string',
            'companyName' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $data = $validator->validated();
            $data['createdBy'] = user()->id;
            $data['contactOwner'] = $data['contactOwner'] ?? user()->id;

            $contact = $this->contactService->create($request, $data);

            $params = [
                'action' => 'created',
                'module' => Constants::MODULE_CONTACT,
                'moduleId' => $contact->id,
                'description' => "Created contact: {$contact->name}",
            ];
            logUserActivity($params);

            return $this->success(
                new GetContactResource($contact),
                'Contact created successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : addContact', $e);
        }
    }

    public function show(int $id)
    {
        try {
            // Agents may only view their assigned contacts (own, or linked to
            // their listings/enquiries); admins have full access.
            if (isAgent() && ! Contact::whereKey($id)->accessibleByAgent(user()->id)->exists()) {
                return $this->error('You do not have access to this contact.', 403);
            }

            $contact = $this->contactService->getById($id);

            if (! $contact) {
                return $this->error('The requested contact was not found.', 422);
            }

            return $this->success(
                new GetContactResource($contact),
                'Contact retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : show', $e);
        }
    }

    public function update(Request $request, int $id)
    {
        // Agents may only edit their assigned contacts (own, or linked to their
        // listings/enquiries); admins have full access.
        if (isAgent() && ! Contact::whereKey($id)->accessibleByAgent(user()->id)->exists()) {
            return $this->error('You do not have access to this contact.', 403);
        }

        // contactType is multi-value; accept a single string / comma-separated value
        // too and normalise to a clean array for backward compatibility.
        if ($request->has('contactType')) {
            $request->merge(['contactType' => Contact::normalizeContactTypes($request->input('contactType'))]);
        }

        $validator = Validator::make($request->all(), [
            'salutation' => 'nullable|string|max:10',
            'name' => 'sometimes|required|string',
            'contactType' => 'nullable|array',
            'contactType.*' => 'in:'.implode(',', array_keys(Constants::CONTACT_TYPE)),
            'contactCategory' => 'nullable|in:'.implode(',', array_keys(Constants::CONTACT_CATEGORIES)),
            // Not unique — see the note in addContact(). Editing a contact onto a
            // number someone else already has is allowed; it just shows as a
            // duplicate afterwards.
            'phone' => 'nullable|string',
            'alternatePhone' => 'nullable|string|max:20',
            'otherPhone' => 'nullable|string|max:20',
            // Not unique — see the note in addContact(). This rule was also making
            // 2,124 existing duplicate-email contacts impossible to edit at all,
            // since the twin matched even when the email was not being changed.
            'email' => 'nullable|email|max:255',
            'source' => ['nullable', 'string', fn ($attr, $value, $fail) => Constants::isKnownLeadSource($value) ?: $fail('The selected source is not a recognised lead source.')],
            'contactSource' => 'nullable|string|max:255',
            'dob' => 'nullable|date',
            'notes' => 'nullable',
            'address' => 'nullable|string|max:1000',
            'nationality' => 'nullable|string|max:255',
            'residencyStatus' => 'nullable|string|max:50',
            'contactProfile' => 'nullable',
            'removeContactProfile' => 'nullable|string',
            'contactOwner' => 'nullable|exists:users,id',
            'leadSourceDetails' => 'nullable|string|max:255',
            'bayutWhatsappInitiated' => 'nullable|boolean',
            'tenantWhatsappInitiated' => 'nullable|boolean',
            'offPlanWhatsappInitiated' => 'nullable|boolean',
            'lastContacted' => 'nullable|date',
            'routingSource' => 'nullable|string|max:255',
            'outcome' => 'nullable|string',
            'qualificationContact' => 'nullable|string',
            'leadRating' => 'nullable|string',
            'whatsappLead' => 'nullable|boolean',
            'referralSource' => 'nullable|string|max:255',
            'referralCompany' => 'nullable|string|max:255',
            'referralPercentDue' => 'nullable|string|max:50',
            'referralContact' => 'nullable|string|max:255',
            'referralFeePaidThru' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'zipCode' => 'nullable|string|max:20',
            'otherAddress' => 'nullable|string|max:1000',
            'otherCountry' => 'nullable|string|max:255',
            'otherState' => 'nullable|string|max:255',
            'otherCity' => 'nullable|string|max:255',
            'otherZipCode' => 'nullable|string|max:20',
            'company' => 'nullable|string|max:255',
            'pastClient' => 'nullable|boolean',
            'title' => 'nullable|string|max:255',
            'communityPreference' => 'nullable|array',
            'communityPreference.*' => 'string',
            'companyName' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $data = $validator->validated();

            $data['createdBy'] = user()->id;

            $existingContact = $this->contactService->getById($id);
            if (! $existingContact) {
                return $this->error('The requested contact was not found.', 422);
            }
            $oldValues = $existingContact->only(array_keys($data));

            $contact = $this->contactService->update($request, $id, $data);

            if (! $contact) {
                return $this->error('The requested contact was not found.', 422);
            }

            $params = [
                'action' => 'updated',
                'module' => Constants::MODULE_CONTACT,
                'moduleId' => $contact->id,
                'description' => "Updated contact: {$contact->name}",
                'oldValues' => $oldValues,
                'newValues' => $contact->only(array_keys($data)),
            ];
            logUserActivity($params);

            return $this->success(
                new GetContactResource($contact),
                'Contact updated successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : update', $e);
        }
    }

    public function destroy(int $id)
    {
        try {
            // Deleting contacts is restricted to admins — agents may only
            // view/edit their assigned contacts, never delete.
            if (isAgent()) {
                return $this->error('You are not allowed to delete contacts.', 403);
            }

            $contact = $this->contactService->getById($id);

            if (! $contact) {
                return $this->error('The requested contact was not found.', 422);
            }

            $name = $contact->name;
            $recordId = $contact->id;

            $deleted = $this->contactService->delete($id);

            if (! $deleted) {
                return $this->error('The requested contact was not found.', 422);
            }

            $params = [
                'action' => 'deleted',
                'module' => Constants::MODULE_CONTACT,
                'moduleId' => $recordId,
                'description' => "Deleted contact: {$name}",
            ];
            logUserActivity($params);

            return $this->success([], 'Contact deleted successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : destroy', $e);
        }
    }

    /**
     * Merge one or more contacts into a master.
     *
     * Non-destructive: no field is rewritten and no enquiry, listing, offer or note
     * changes hands. The merged contacts become aliases of the master — hidden from
     * the contact list, the select lookups and search, with their records rolled up
     * under the master. revertMergeContacts() undoes it exactly.
     */
    public function mergeContacts(Request $request)
    {
        // mergeIds is multi-value; accept a single id (5) or a comma-separated
        // string ("5,7,9") too and normalise to an array, the same way contactType
        // is handled on create.
        if ($request->has('mergeIds')) {
            $request->merge(['mergeIds' => $this->normalizeIdList($request->input('mergeIds'))]);
        }

        $validator = Validator::make($request->all(), [
            'masterId' => 'required|integer|exists:contacts,id',
            'mergeIds' => 'required|array|min:1',
            'mergeIds.*' => 'required|integer|exists:contacts,id',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $data = $validator->validated();

            $master = $this->contactService->merge($data['masterId'], $data['mergeIds'], user()->id);

            $mergedNames = $master->mergedContacts->pluck('name')->implode(', ');

            logUserActivity([
                'action' => 'merged',
                'module' => Constants::MODULE_CONTACT,
                'moduleId' => $master->id,
                'description' => "Merged into contact {$master->name}: {$mergedNames}",
            ]);

            return $this->success(
                new GetContactResource($this->contactService->getById($master->id)),
                'Contacts merged successfully.'
            );
        } catch (\InvalidArgumentException $e) {
            // A guard rejection is a caller mistake, not a fault — report the reason
            // rather than the generic support message exceptionResponse() returns.
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : mergeContacts', $e);
        }
    }

    /**
     * Undo a merge. The given contacts become ordinary, visible contacts again.
     * Nothing has to be put back, because merging moved nothing.
     */
    public function revertMergeContacts(Request $request)
    {
        // Same shapes as mergeContacts(): a single id, "5,7,9", or an array.
        if ($request->has('contactIds')) {
            $request->merge(['contactIds' => $this->normalizeIdList($request->input('contactIds'))]);
        }

        $validator = Validator::make($request->all(), [
            'contactIds' => 'required|array|min:1',
            'contactIds.*' => 'required|integer|exists:contacts,id',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $restored = $this->contactService->revertMerge($validator->validated()['contactIds'], user()->id);

            foreach ($restored as $contact) {
                logUserActivity([
                    'action' => 'unmerged',
                    'module' => Constants::MODULE_CONTACT,
                    'moduleId' => $contact->id,
                    'description' => "Reverted merge of contact: {$contact->name}",
                ]);
            }

            return $this->success(
                GetAllContactResource::collection($restored),
                'Merge reverted successfully.'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : revertMergeContacts', $e);
        }
    }

    /**
     * Accept 5, "5", "5,7,9" or [5, 7, 9] and return a clean list of ids.
     *
     * Zero and non-numeric entries are dropped rather than passed through as 0,
     * which would otherwise fail validation with a confusing "0 does not exist"
     * instead of the real problem.
     */
    private function normalizeIdList($value): array
    {
        $ids = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_unique(array_filter(
            array_map(fn ($id) => (int) trim((string) $id), $ids)
        )));
    }

    public function exportContactCsv(Request $request)
    {
        if (isAgent()) {
            if ($actor = user()) {
                NotifyDataExportJob::dispatch($actor->id, Constants::MODULE_CONTACT, 0, now()->toISOString(), 'blocked');
            }

            return $this->error('Agents are not allowed to export data.', 403);
        }

        try {
            // Stream the export lazily in chunks so memory stays flat regardless of
            // how many contacts exist (avoids the OOM/timeout 500 on large datasets).
            $exportQuery = $this->contactService->exportQuery($request);
            $totalContacts = (clone $exportQuery)->reorder()->count();
            $contacts = $exportQuery->lazy(500);

            $fileName = 'contacts_'.now()->format('Y_m_d_H_i_s').'.csv';

            $boolLabel = fn ($v) => is_null($v) ? null : ($v ? 'Yes' : 'No');

            $columnMap = [
                'id' => ['label' => 'ID', 'value' => fn ($c) => $c->id],
                'name' => ['label' => 'Name', 'value' => fn ($c) => $c->name],
                'salutation' => ['label' => 'Salutation', 'value' => fn ($c) => $c->salutation],
                'title' => ['label' => 'Title', 'value' => fn ($c) => $c->title],
                'contactType' => ['label' => 'Contact Type', 'value' => fn ($c) => collect(Contact::normalizeContactTypes($c->contactType))->map(fn ($t) => Constants::CONTACT_TYPE[$t] ?? $t)->implode(', ')],
                'company' => ['label' => 'Company', 'value' => fn ($c) => $c->company],
                'companyName' => ['label' => 'Company Name', 'value' => fn ($c) => $c->companyName],
                'phone' => ['label' => 'Phone', 'value' => fn ($c) => $c->phone],
                'alternatePhone' => ['label' => 'Alternate Phone', 'value' => fn ($c) => $c->alternatePhone],
                'otherPhone' => ['label' => 'Other Phone', 'value' => fn ($c) => $c->otherPhone],
                'email' => ['label' => 'Email', 'value' => fn ($c) => $c->email],
                'source' => ['label' => 'Source', 'value' => fn ($c) => Constants::leadSourceGroupLabel($c->source)],
                'contactSource' => ['label' => 'Contact Source', 'value' => fn ($c) => $c->contactSource],
                'leadSourceDetails' => ['label' => 'Lead Source Details', 'value' => fn ($c) => $c->leadSourceDetails],
                'routingSource' => ['label' => 'Routing Source', 'value' => fn ($c) => $c->routingSource],
                'referralSource' => ['label' => 'Referral Source', 'value' => fn ($c) => $c->referralSource],
                'referralCompany' => ['label' => 'Referral Company', 'value' => fn ($c) => $c->referralCompany],
                'referralContact' => ['label' => 'Referral Contact', 'value' => fn ($c) => $c->referralContact],
                'referralPercentDue' => ['label' => 'Referral Percent Due', 'value' => fn ($c) => $c->referralPercentDue],
                'referralFeePaidThru' => ['label' => 'Referral Fee Paid Thru', 'value' => fn ($c) => $c->referralFeePaidThru],
                'assignedAgent' => ['label' => 'Assigned Agent', 'value' => fn ($c) => $c->latestEnquiry?->agent?->name],
                'contactOwner' => ['label' => 'Contact Owner', 'value' => fn ($c) => $c->contactOwnerUser?->name],
                'outcome' => ['label' => 'Outcome', 'value' => fn ($c) => $c->outcome],
                'qualificationContact' => ['label' => 'Qualification Contact', 'value' => fn ($c) => $c->qualificationContact],
                'leadRating' => ['label' => 'Lead Rating', 'value' => fn ($c) => $c->leadRating],
                'whatsappLead' => ['label' => 'WhatsApp Lead', 'value' => fn ($c) => $boolLabel($c->whatsappLead)],
                'bayutWhatsappInitiated' => ['label' => 'Bayut WhatsApp Initiated', 'value' => fn ($c) => $boolLabel($c->bayutWhatsappInitiated)],
                'tenantWhatsappInitiated' => ['label' => 'Tenant WhatsApp Initiated', 'value' => fn ($c) => $boolLabel($c->tenantWhatsappInitiated)],
                'offPlanWhatsappInitiated' => ['label' => 'Off-Plan WhatsApp Initiated', 'value' => fn ($c) => $boolLabel($c->offPlanWhatsappInitiated)],
                'pastClient' => ['label' => 'Past Client', 'value' => fn ($c) => $boolLabel($c->pastClient)],
                'lastContacted' => ['label' => 'Last Contacted', 'value' => fn ($c) => $c->lastContacted],
                'dob' => ['label' => 'Date of Birth', 'value' => fn ($c) => $c->dob],
                'nationality' => ['label' => 'Nationality', 'value' => fn ($c) => $c->nationality],
                'residencyStatus' => ['label' => 'Residency Status', 'value' => fn ($c) => $c->residencyStatus ? (Constants::USER_RESIDENCY_STATUS[$c->residencyStatus] ?? $c->residencyStatus) : null],
                'communityPreference' => ['label' => 'Community Preference', 'value' => fn ($c) => $c->communityPreference],
                'address' => ['label' => 'Address', 'value' => fn ($c) => $c->address],
                'country' => ['label' => 'Country', 'value' => fn ($c) => $c->country],
                'state' => ['label' => 'State', 'value' => fn ($c) => $c->state],
                'city' => ['label' => 'City', 'value' => fn ($c) => $c->city],
                'zipCode' => ['label' => 'Zip Code', 'value' => fn ($c) => $c->zipCode],
                'otherAddress' => ['label' => 'Other Address', 'value' => fn ($c) => $c->otherAddress],
                'otherCountry' => ['label' => 'Other Country', 'value' => fn ($c) => $c->otherCountry],
                'otherState' => ['label' => 'Other State', 'value' => fn ($c) => $c->otherState],
                'otherCity' => ['label' => 'Other City', 'value' => fn ($c) => $c->otherCity],
                'otherZipCode' => ['label' => 'Other Zip Code', 'value' => fn ($c) => $c->otherZipCode],
                'notes' => ['label' => 'Notes', 'value' => fn ($c) => $c->notes],
                'contactProfile' => ['label' => 'Contact Profile', 'value' => fn ($c) => $c->contactProfile ? getImageUrl($c->contactProfile) : null],
                'creator' => ['label' => 'Created By', 'value' => fn ($c) => $c->creator?->name],
                'created_at' => ['label' => 'Created At', 'value' => fn ($c) => convertToTimezone($c->created_at)],
                'updated_at' => ['label' => 'Updated At', 'value' => fn ($c) => convertToTimezone($c->updated_at)],
            ];

            logUserActivity([
                'action' => 'exported',
                'module' => Constants::MODULE_CONTACT,
                'moduleId' => null,
                'description' => 'Exported contacts to CSV',
            ]);

            if ($actor = user()) {
                NotifyDataExportJob::dispatch($actor->id, Constants::MODULE_CONTACT, $totalContacts, now()->toISOString());
            }

            return exportCsvStream($request, $contacts, $columnMap, $fileName);
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : exportContactCsv', $e);
        }
    }

    public function importCsv(Request $request)
    {

        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        try {
            $file = $request->file('file');

            if (($handle = fopen($file->getRealPath(), 'r')) === false) {
                return $this->error('Could not open file.', 400);
            }

            $header = fgetcsv($handle);
            $expectedHeader = ['Name', 'Salutation', 'Contact Type', 'Phone', 'Alternate Phone', 'Email', 'Source', 'Assigned Agent Email', 'Date of Birth', 'Notes', 'Address'];

            if ($header !== $expectedHeader) {
                return $this->error('Invalid CSV template.', 422);
            }

            $created = 0;
            $skipped = 0;
            // Rows rejected as an exact name+phone re-import.
            $duplicateRows = 0;
            // Rows imported that share a number with someone already on file.
            $phoneDuplicates = 0;
            $duplicates = [];

            $currentUserId = user()->id;
            $agentCache = [];

            // Identity on import is name + phone, not phone alone. Same number AND
            // same name is the same person arriving twice, so the row is skipped;
            // same number with a different name is a second real person on a shared
            // line (a spouse, a colleague, an office number), so it is imported and
            // shows as a duplicate in the CRM for review.
            //
            // Keyed by the full normalised number from Contact::normalizePhone(), so
            // a CSV writing "00971502…" can't slip past "+971502…" — and equally so a
            // "+20 1000000000" is never taken for a "+971 000000000". The country
            // code is part of the number.
            //
            // fullNumber => [nameKey => true]. Loaded once instead of a query per
            // row, and chunked so 165k contacts don't hydrate in one go. Rows created
            // by this import are added as we go, which also catches a number repeated
            // inside the same file.
            $existingByPhone = [];

            DB::table('contacts')
                ->whereNull('deleted_at')
                ->whereNotNull('phone')
                ->where('phone', '<>', '')
                ->select('phone', 'name')
                ->orderBy('id')
                ->chunk(5000, function ($rows) use (&$existingByPhone) {
                    foreach ($rows as $row) {
                        $number = Contact::normalizePhone($row->phone);

                        if ($number !== null) {
                            $existingByPhone[$number][Contact::nameKeyFor($row->name)] = true;
                        }
                    }
                });

            while (($row = fgetcsv($handle)) !== false) {
                $data = array_combine($header, $row);

                $phone = trim($data['Phone'] ?? '');

                // Skip: a phone number is required to import a contact.
                if ($phone === '') {
                    $skipped++;

                    continue;
                }

                $number = Contact::normalizePhone($phone);
                $nameKey = Contact::nameKeyFor($data['Name'] ?? null);

                if ($number !== null && isset($existingByPhone[$number])) {
                    // Same number and same name — already on file, so this row is a
                    // re-import rather than a new person. An empty name never counts
                    // as a match, or every unnamed row would collide.
                    if ($nameKey !== '' && isset($existingByPhone[$number][$nameKey])) {
                        $duplicateRows++;
                        $duplicates[] = $phone;

                        continue;
                    }

                    // Same number, different name: a genuine second person sharing a
                    // line. Imported, and flagged so it surfaces for review.
                    $phoneDuplicates++;
                }

                // Resolve the contact owner from the CSV "Assigned Agent Email".
                // Skip the row when no email is provided or the email does not match a
                // user. Cache each lookup so a repeated agent email costs only one query.
                $agentEmail = trim($data['Assigned Agent Email'] ?? '');

                if ($agentEmail === '') {
                    $skipped++;

                    continue;
                }

                if (! array_key_exists($agentEmail, $agentCache)) {
                    $agentCache[$agentEmail] = User::where('email', $agentEmail)->value('id');
                }

                if (! $agentCache[$agentEmail]) {
                    $skipped++;

                    continue;
                }

                Contact::create([
                    'name' => $data['Name'],
                    'salutation' => $data['Salutation'],
                    'contactType' => collect(Contact::normalizeContactTypes($data['Contact Type'] ?? null))
                        ->map(fn ($t) => Constants::normalizeSourceKey(Constants::CONTACT_TYPE, $t))
                        ->all(),
                    'phone' => $phone,
                    'alternatePhone' => $data['Alternate Phone'],
                    'email' => $data['Email'],
                    'contactOwner' => $agentCache[$agentEmail],
                    'createdBy' => $currentUserId,
                    'source' => $data['Source'] ?? 'Manual',
                    'dob' => $data['Date of Birth'] ?: null,
                    'notes' => $data['Notes'] ?: null,
                    'address' => $data['Address'],
                ]);

                if ($number !== null) {
                    $existingByPhone[$number][$nameKey] = true;
                }

                $created++;
            }

            fclose($handle);

            $params = [
                'action' => 'imported',
                'module' => Constants::MODULE_CONTACT,
                'moduleId' => null,
                'description' => "Imported contacts from CSV: {$created} created, {$skipped} skipped, {$duplicateRows} duplicate, {$phoneDuplicates} on a shared number",
            ];
            logUserActivity($params);

            return $this->success([
                'created' => $created,
                'skipped' => $skipped,
                // Not imported: same name and same number as an existing contact.
                'duplicateRows' => $duplicateRows,
                'duplicates' => $duplicates,
                // Imported, but sharing a number with an existing contact.
                'phoneDuplicates' => $phoneDuplicates,
            ], 'CSV imported successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : importCsv', $e);
        }
    }

    public function sendEmail(int $id)
    {
        try {
            // Agents may only email their assigned contacts.
            if (isAgent() && ! Contact::whereKey($id)->accessibleByAgent(user()->id)->exists()) {
                return $this->error('You do not have access to this contact.', 403);
            }

            $contact = Contact::find($id);

            if (! $contact) {
                return $this->error('The requested contact was not found.', 422);
            }

            if (empty($contact->email)) {
                return $this->error('Contact email not available.', 422);
            }

            $replacements = [
                'name' => ucwords($contact->name ?? 'Contact'),
                'email' => $contact->email,
                'company_name' => getCompanyName(),
            ];

            // $this->mailService->sendEmail(
            //     Constants::EMAIL_SLUGS['contact']['form_submission'],
            //     $contact->email,
            //     $replacements
            // );

            $params = [
                'action' => 'sent_email',
                'module' => Constants::MODULE_CONTACT,
                'moduleId' => $contact->id,
                'description' => "Sent email to contact: {$contact->name}",
            ];
            logUserActivity($params);

            return $this->success(null, 'Email sent successfully to '.$contact->email);
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : sendEmail', $e);
        }
    }

    public function getContactByUserId(Request $request, $id)
    {
        try {
            $contacts = $this->contactService->getContactsByAssignedAgent($id, $request);

            return $this->success(
                GetContactByUserId::collection($contacts),
                'Contacts retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : getContactByUserId', $e);
        }
    }

    public function getLinkedEnquiriesByContactId(Request $request, $contactId)
    {
        try {
            $enquiries = $this->contactService->getLinkedEnquiriesByContactId($contactId, $request);

            if (is_null($enquiries)) {
                return $this->error('The requested contact was not found.', 422);
            }

            if ($enquiries->isEmpty()) {
                return $this->error('No enquiries found.', 422);
            }

            return $this->success(
                GetEnquiryByContactResource::collection($enquiries),
                'Linked Enquiries retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : getLinkedEnquiriesByContactId', $e);
        }
    }

    public function getListingsByOwnerId(Request $request, $contactId)
    {
        try {
            $listings = $this->contactService->getListingsByOwner($contactId, $request);

            if (is_null($listings)) {
                return $this->error('The requested contact was not found.', 422);
            }

            return $this->success(
                GetListingByContactResource::collection($listings),
                'Listings retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : getListingsByOwnerId', $e);
        }
    }

    public function getOffersByContactId(Request $request, $contactId)
    {
        try {
            $offers = $this->contactService->getOffersByContact($contactId, $request);

            if (is_null($offers)) {
                return $this->error('The requested contact was not found.', 422);
            }

            return $this->success(GetOfferByContactResource::collection($offers), 'Offers retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : getOffersByContactId', $e);
        }
    }

    public function getClosingsByContactId(Request $request, $contactId)
    {
        try {
            $closings = $this->contactService->getClosingsByContact($contactId, $request);

            if (is_null($closings)) {
                return $this->error('The requested contact was not found.', 422);
            }

            return $this->success(
                GetClosingByContactResource::collection($closings),
                'Closings retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : getClosingsByContactId', $e);
        }
    }

    public function getFormatDataContacts(Request $request)
    {
        try {
            $perPage = $request->filled('per_page') && $request->per_page > 0
                ? (int) $request->per_page
                : 10;

            $query = Contact::select('id', 'name', 'email')->notMerged();

            if ($request->filled('search')) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $contacts = $query->orderByDesc('id')->paginate($perPage);

            $formatContacts = $contacts->getCollection()->map(function ($data) {
                return [
                    'id' => $data->id,
                    'name' => $data->name.' ('.$data->email.')',
                ];
            });

            $contacts->setCollection($formatContacts);

            return $this->success($contacts, 'Contacts retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : getFormatDataContacts', $e);
        }
    }

    public function composeEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'contactId' => 'required|exists:contacts,id',
            'to' => 'required|email',
            'cc' => 'nullable|array',
            'cc.*' => 'email',
            'subject' => 'required|string',
            'body' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        $contactId = $request->contactId;

        // Agents may only email their assigned contacts.
        if (isAgent() && ! Contact::whereKey($contactId)->accessibleByAgent(user()->id)->exists()) {
            return $this->error('You do not have access to this contact.', 403);
        }

        try {
            $html = view('EmailTemplate.templateLayout', [
                'title' => $request->subject,
                'body' => $request->body,
            ])->render();

            $mail = Mail::to($request->to);

            if (! empty($request->cc)) {
                $mail->cc($request->cc);
            }

            $agentUser = user();

            $mail->send(
                new DynamicMail(
                    $request->subject,
                    $html,
                    [],
                    $agentUser?->email,
                    $agentUser?->name
                )
            );

            $this->contactNoteService->create([
                'contactId' => $contactId,
                'content' => json_encode([
                    'to' => $request->to,
                    'cc' => $request->cc ?? [],
                    'subject' => $request->subject,
                    'body' => $request->body,
                ]),
                'type' => 'email',
                'parent' => 0,
                'createdBy' => user()->id,
            ]);

            if (! empty($request->cc)) {
                foreach ($request->cc as $ccEmail) {
                    $this->mailService->logContactEmail($ccEmail, $request->body);
                }
            }

            logUserActivity([
                'action' => 'compose_email',
                'module' => Constants::MODULE_CONTACT,
                'moduleId' => $contactId,
                'description' => "Composed email for contact #{$contactId} to {$request->to}".(! empty($request->cc) ? ' cc: '.implode(', ', $request->cc) : ''),
                'extraData' => [
                    'to' => $request->to,
                    'cc' => ! empty($request->cc) ? implode(', ', $request->cc) : null,
                    'subject' => $request->subject,
                    'emailContent' => $request->body,
                ],
            ]);

            return $this->success(null, 'Email sent successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactController : composeEmail', $e);
        }
    }
}
