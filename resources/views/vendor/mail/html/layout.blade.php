{{--
    34Pazar sistem postalarının TEK düzeni (25 Ekim 2026). Bütün Mailable'lar
    `markdown:` ile, bütün bildirimler MailMessage ile buradan geçer; düzen
    dışında posta yazılmaz (MailLayoutContractTest).

    Tablo tabanlı, 600 px. Tema CSS'i (themes/34pazar.css) gönderimde satır
    içine çevrilir; aşağıdaki <style> yalnız medya sorguları içindir — satır
    içine çevrilemezler, destekleyen istemci okur, desteklemeyen açık temada
    kalır. Gölge yok (kurumsal tasarım kararı).
--}}
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('site.brand', '34Pazar') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
@media only screen and (max-width: 620px) {
.inner-body, .footer { width: 100% !important; }
.content-cell { padding: 28px 22px !important; }
}
@media only screen and (max-width: 500px) {
.button { display: block !important; width: 100% !important; box-sizing: border-box; }
}
@media (prefers-color-scheme: dark) {
body, .wrapper, .body { background-color: #0b1b33 !important; }
.inner-body { background-color: #13233d !important; border-color: #22324d !important; }
.inner-body h1, .inner-body h2, .inner-body h3, .inner-body p, .inner-body li, .inner-body td, .inner-body strong { color: #e8ecf2 !important; }
.panel-content { background-color: #0b1b33 !important; }
.panel { border-color: #ce310d !important; }
.subcopy, .subcopy p, .footer p, .footer a { color: #a9b3c4 !important; }
.subcopy { border-top-color: #22324d !important; }
.logo-light { display: none !important; }
.logo-dark { display: block !important; max-height: none !important; }
.inner-body a:not(.button) { color: #ff8a66 !important; }
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
