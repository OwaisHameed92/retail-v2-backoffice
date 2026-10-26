<x-mail::message>
# Welcome to Switch & Save, {{ $firstName }}

Your account for **{{ $data->businessName }}** is ready, with your own online store. Below is everything you need to get it trading.

## Set up your online store

Sign in with **{{ $data->ownerEmail }}** and open **Online store**. There you can:

1. Add your products in **Products**, with their prices.
2. Choose which products show online, set each one’s stock, and add photos and short descriptions.
3. Add your logo, colours and banners, and set your collection or delivery times.
4. Fill in your trader details, then press **Go live**.

<x-mail::button :url="$storeUrl">
Set up your online store
</x-mail::button>

If you have not set your password yet, look for our "Set your password" email, or use "Forgot password" on the sign-in page.

Ready to add tills later? Your products are already in your account, so your tills pick them up as soon as they are set up. Just ask us.
@if ($showDirectDebit)

## Set up your Direct Debit

Your monthly fee is collected by Direct Debit through GoCardless. Once you have signed in, open **Billing** and choose **Set up Direct Debit**. It takes about two minutes.
@if ($data->directDebitDays)

Please do this within {{ $data->directDebitDays }} {{ $data->directDebitDays === 1 ? 'day' : 'days' }}, or your online store goes offline until it is set up.
@endif

<x-mail::button :url="$data->billingUrl">
Set up Direct Debit
</x-mail::button>
@endif

Thanks for choosing Switch & Save. We are here if you need a hand getting set up.

The Switch & Save team
</x-mail::message>
