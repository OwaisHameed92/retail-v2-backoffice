{{-- OS-10: the CSS inlined into a shop's customer emails: the Switch & Save base, recoloured with the shop's brand. --}}
@php($b = isset($brand) && $brand instanceof \App\Domain\OnlineStore\Mail\StoreMailBrand ? $brand : \App\Domain\OnlineStore\Mail\StoreMailBrand::plain(''))
{!! file_get_contents(resource_path('views/vendor/mail/html/themes/switch-save.css')) !!}

/* The shop's brand */

a {
    color: {{ $b->linkColour }};
}

.inner-body {
    border-top: 4px solid {{ $b->colour }};
}

.button-blue,
.button-primary {
    background-color: {{ $b->colour }};
    border-bottom: 12px solid {{ $b->colour }};
    border-left: 24px solid {{ $b->colour }};
    border-right: 24px solid {{ $b->colour }};
    border-top: 12px solid {{ $b->colour }};
    color: {{ $b->onColour }};
}

.panel {
    border-left: {{ $b->colour }} solid 4px;
}

.panel-content {
    background-color: {{ $b->tint }};
}
