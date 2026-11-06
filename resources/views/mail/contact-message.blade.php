34pazar.com iletişim formundan yeni mesaj

Konu: {!! $topicLabel !!}
@if ($serviceTitle)
Hizmet: {!! $serviceTitle !!}
@endif
Ad: {!! $data['name'] !!}
E-posta: {!! $data['email'] !!}
Telefon: {!! $data['phone'] ?? '—' !!}

Mesaj:
{!! $data['message'] !!}

--
Bu postayı yanıtlarsan cevap doğrudan gönderene gider.
Bütün mesajlar: {{ url('/admin/contact-messages') }}
