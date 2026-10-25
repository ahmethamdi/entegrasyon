<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('site.brand', '34Pazar') }}
</x-mail::header>
</x-slot:header>

{!! $slot !!}

@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
@include('vendor.mail.partials.footer')
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
