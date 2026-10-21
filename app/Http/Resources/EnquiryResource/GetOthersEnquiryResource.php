<?php

namespace App\Http\Resources\EnquiryResource;

class GetOthersEnquiryResource extends GetEnquiryResource
{
    public function toArray($request)
    {
        $data = parent::toArray($request);

        unset($data['contactId'], $data['contact'], $data['phone']);

        $data['isOwnedByCurrentAgent'] = false;

        return $data;
    }
}
