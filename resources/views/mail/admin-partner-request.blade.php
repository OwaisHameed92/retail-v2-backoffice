<x-mail::message>
@if ($data->event === \App\Domain\Mail\Data\PartnerRequestAlertData::PARTNER_CANCELLED)
# Partner cancelled an approved business

**{{ $data->partnerName }}** cancelled the deal for **{{ $data->businessName }}**, which is already set up on the portal.

<x-mail::facts :rows="$facts" />

<x-mail::button :url="$requestUrl">
Open the request
</x-mail::button>

Nothing was changed on the business. Decide what happens to it: it may already be trading.
@else
# {{ $data->event === \App\Domain\Mail\Data\PartnerRequestAlertData::SENT_AGAIN ? 'Partner request sent again' : 'New partner request' }}

**{{ $data->sellerName }}** from **{{ $data->partnerName }}** {{ $data->event === \App\Domain\Mail\Data\PartnerRequestAlertData::SENT_AGAIN ? 'sent' : 'sold' }} an EPOS to **{{ $data->businessName }}**{{ $data->event === \App\Domain\Mail\Data\PartnerRequestAlertData::SENT_AGAIN ? ' again after a rejection' : '' }}.

<x-mail::facts :rows="$facts" />

<x-mail::button :url="$requestUrl">
Review the request
</x-mail::button>

Check the details, then approve it to set the business up, or reject it with a reason the partner sees.
@endif
</x-mail::message>
