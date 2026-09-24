<x-mail::message>
# Welcome to Switch & Save, {{ $firstName }}

Your account for **{{ $data->businessName }}** is ready. Below is everything you need to get your {{ $tillCount }} trading.
@if ($data->trialDays)

Your {{ $data->trialDays }}-day free trial starts when you activate your first till.
@endif

## Your licence keys

Each till has its own key. Enter it on that till the first time you open SSPOS.

<x-mail::licence-keys :tills="$tills" />

<x-mail::notice>
**Keep these keys safe.** This is the only time we send them. Treat them like a password: store them somewhere secure and do not share them outside your business.
</x-mail::notice>

## How to activate a till

1. On the till PC, download and install SSPOS from [{{ preg_replace('#^https?://#', '', $downloadUrl) }}]({{ $downloadUrl }}).
2. Open SSPOS and enter the licence key for that till.
3. The till connects to your account and is ready to trade. Repeat for each till.

## Your online portal

See your sales, products and reports from anywhere. Sign in with **{{ $data->ownerEmail }}**.

<x-mail::button :url="$data->loginUrl">
Sign in to your portal
</x-mail::button>

If you have not set your password yet, look for our "Set your password" email, or use "Forgot password" on the sign-in page.

Thanks for choosing Switch & Save. We are here if you need a hand getting set up.

The Switch & Save team
</x-mail::message>
