{{--
    Alt bilgi — HTML ve düz metin sürümü AYNI metni kullanır. Destek sözü
    ve künye config/site.php'den: fiyat sayfası ve yasal metinlerle tek
    kaynak (adres ya da saat değişince posta eski bilgide kalmasın).
--}}
{{ __('Destek: :email · WhatsApp :whatsapp (her gün :hours, Türkiye saati)', ['email' => config('site.contact_email'), 'whatsapp' => config('site.whatsapp_display'), 'hours' => config('site.support_hours')]) }}

{{ __('Bu e-postayı 34Pazar hesabınla ya da sana gönderilen bir davetle ilgili bir işlem nedeniyle aldın.') }}

{{ config('site.brand', '34Pazar') }} · {{ config('site.operator') }} · {{ config('site.street') }}, {{ config('site.postal_code') }} {{ config('site.city') }}, {{ __(config('site.country')) }}
