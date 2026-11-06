<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\ContactMessageMail;
use App\Support\Security\FormShield;
use App\Support\Site\ContactForm;
use App\Support\Site\Services;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * /iletisim formunun gönderimi (6 Kas 2026 · site sprint 1).
 *
 * Sıra kayıt formundakiyle aynı (`RegisteredUserController`):
 *   1. Bot kalkanı DOĞRULAMADAN ÖNCE (`FormShield`). Tuzağı dolduran bot
 *      başarı sayfası görür ama hiçbir şey kaydedilmez, posta gitmez.
 *   2. Doğrulama.
 *   3. Kayıt (`contact_messages`) — posta denemesinden ÖNCE: posta
 *      sağlayıcısı düşse de mesaj kaybolmaz.
 *   4. Düz metin posta `site.contact_form_to`'ya; başarılıysa `mailed_at`.
 * Hız sınırı rotada (`throttle:contact`).
 */
final class ContactMessageController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        if (! FormShield::admit(ContactForm::FORM, $request)) {
            return $this->done();
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s.\-]{5,40}$/'],
            'topic' => ['required', 'string', Rule::in(array_keys(ContactForm::TOPICS))],
            'service' => ['nullable', 'string', Rule::in(array_keys(Services::all()))],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ], [
            'phone.regex' => 'Telefon numarasında yalnız rakam, boşluk ve + ( ) - kullanılabilir.',
        ], [
            'name' => 'ad',
            'email' => 'e-posta',
            'phone' => 'telefon',
            'topic' => 'konu',
            'service' => 'hizmet',
            'message' => 'mesaj',
        ]);

        $row = [
            'name' => trim($data['name']),
            'email' => mb_strtolower(trim($data['email'])),
            'phone' => ($data['phone'] ?? null) !== null && trim($data['phone']) !== '' ? trim($data['phone']) : null,
            'topic' => $data['topic'],
            // Hizmet yalnız teklif konusunda anlamlı; başka konuda düşer.
            'service' => $data['topic'] === 'teklif' ? ($data['service'] ?? null) : null,
            'message' => trim($data['message']),
        ];

        $id = (string) Str::uuid();
        DB::table('contact_messages')->insert(['id' => $id, ...$row, 'created_at' => now()]);

        try {
            Mail::to((string) config('site.contact_form_to'))->send(new ContactMessageMail($row));
            DB::table('contact_messages')->where('id', $id)->update(['mailed_at' => now()]);
        } catch (Throwable $e) {
            // Kayıt duruyor; yönetim ekranı "posta gitmedi" der. E-posta ve
            // mesaj içeriği loglanmaz.
            Log::warning('contact_form.mail_failed', ['id' => $id, 'error' => $e::class]);
        }

        return $this->done();
    }

    private function done(): RedirectResponse
    {
        return redirect()->to(route('site.contact').'#form')->with('contact_sent', true);
    }
}
