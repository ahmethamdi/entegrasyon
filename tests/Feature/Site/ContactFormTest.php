<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Mail\ContactMessageMail;
use App\Support\Security\FormShield;
use App\Support\Site\ContactForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Site sprint 1 · /iletisim formu: kayıt + düz metin posta, bot kalkanı,
 * hız sınırı, ön seçili konu ve yönetim listesi.
 */
final class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('contact');
    }

    /** @return array<string, mixed> */
    private function valid(array $override = []): array
    {
        return $this->shieldedForm(ContactForm::FORM, $override + [
            'name' => 'Ayşe Yılmaz',
            'email' => 'Ayse@Example.com',
            'phone' => '+90 532 000 00 00',
            'topic' => 'teklif',
            'service' => 'kurulum-ve-veri-tasima',
            'message' => 'Trendyol ve Shopify mağazamı bağlamak istiyorum, 300 ürün var.',
        ]);
    }

    #[Test]
    public function a_valid_message_is_stored_and_mailed_as_plain_text_to_the_configured_address(): void
    {
        Mail::fake();
        config(['site.contact_form_to' => 'form@34devs.test']);

        $this->post('/iletisim', $this->valid())
            ->assertRedirect(route('site.contact').'#form')
            ->assertSessionHas('contact_sent', true);

        $row = DB::table('contact_messages')->first();
        $this->assertNotNull($row);
        $this->assertSame('ayse@example.com', $row->email);
        $this->assertSame('teklif', $row->topic);
        $this->assertSame('kurulum-ve-veri-tasima', $row->service);
        $this->assertNotNull($row->mailed_at);

        Mail::assertSent(ContactMessageMail::class, function (ContactMessageMail $mail): bool {
            $this->assertTrue($mail->hasTo('form@34devs.test'));
            $this->assertTrue($mail->hasReplyTo('ayse@example.com'));
            $this->assertStringContainsString('Hizmet teklifi', $mail->envelope()->subject);
            // Düz metin: HTML şablonu yok.
            $content = $mail->content();
            $this->assertSame('mail.contact-message', $content->text);
            $this->assertNull($content->html);
            $this->assertNull($content->markdown);
            $this->assertStringContainsString('Hizmet: Kurulum ve veri taşıma', $mail->render());

            return true;
        });
    }

    #[Test]
    public function the_default_recipient_is_info_at_34devs(): void
    {
        // ALERT_/BETA_ ayarlarından bağımsız, açık anahtar; varsayılanı 34Devs kutusu.
        $this->assertSame('info@34devs.com', config('site.contact_form_to'));
    }

    #[Test]
    public function the_service_is_dropped_when_the_topic_is_not_an_offer(): void
    {
        Mail::fake();

        $this->post('/iletisim', $this->valid(['topic' => 'destek']))->assertRedirect();

        $this->assertNull(DB::table('contact_messages')->value('service'));
    }

    #[Test]
    public function a_bot_filling_the_honeypot_sees_success_but_nothing_is_stored_or_mailed(): void
    {
        Mail::fake();

        $this->post('/iletisim', [...$this->valid(), FormShield::HONEYPOT => 'https://spam.example'])
            ->assertRedirect(route('site.contact').'#form')
            ->assertSessionHas('contact_sent', true);

        $this->assertSame(0, DB::table('contact_messages')->count());
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_form_sent_too_fast_is_rejected_with_a_resend_hint(): void
    {
        Mail::fake();

        $this->from('/iletisim')->post('/iletisim', [...$this->valid(), FormShield::STAMP => FormShield::stamp(ContactForm::FORM)])
            ->assertRedirect('/iletisim')
            ->assertSessionHasErrors(FormShield::STAMP);

        $this->assertSame(0, DB::table('contact_messages')->count());
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_stamp_from_another_form_does_not_pass(): void
    {
        Mail::fake();

        $this->post('/iletisim', [...$this->valid(), FormShield::STAMP => FormShield::stamp('register', now()->subSeconds(5)->getTimestampMs())])
            ->assertSessionHasErrors(FormShield::STAMP);

        $this->assertSame(0, DB::table('contact_messages')->count());
    }

    #[Test]
    public function invalid_input_is_rejected(): void
    {
        Mail::fake();

        $this->post('/iletisim', $this->valid(['email' => 'adres-degil', 'topic' => 'kampanya', 'message' => 'kısa', 'phone' => '<script>']))
            ->assertSessionHasErrors(['email', 'topic', 'message', 'phone']);

        $this->assertSame(0, DB::table('contact_messages')->count());
        Mail::assertNothingSent();
    }

    #[Test]
    public function the_form_is_rate_limited_per_ip(): void
    {
        Mail::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/iletisim', $this->valid())->assertRedirect();
        }

        $this->post('/iletisim', $this->valid())->assertStatus(429);
        $this->assertSame(5, DB::table('contact_messages')->count());
    }

    #[Test]
    public function a_failed_mail_keeps_the_message_and_marks_it_unsent(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP kapalı'));

        $this->post('/iletisim', $this->valid())->assertRedirect()->assertSessionHas('contact_sent', true);

        $row = DB::table('contact_messages')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->mailed_at);
    }

    #[Test]
    public function the_page_preselects_topic_and_service_from_the_address(): void
    {
        $html = $this->get('/iletisim?konu=gorusme')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<option value="gorusme"\s+selected#', $html);
        $this->assertStringContainsString('name="website"', $html);
        $this->assertStringContainsString('name="ft"', $html);

        $html = $this->get('/iletisim?konu=teklif&hizmet=egitim')->getContent();
        $this->assertMatchesRegularExpression('#<option value="teklif"\s+selected#', $html);
        $this->assertMatchesRegularExpression('#<option value="egitim"\s+selected#', $html);

        // Tanınmayan değer sessizce yok sayılır.
        $this->get('/iletisim?konu=<x>&hizmet=yok')->assertOk()->assertDontSee('<x>', false);
    }

    #[Test]
    public function the_success_notice_is_shown_after_sending(): void
    {
        $this->withSession(['contact_sent' => true])->get('/iletisim')->assertOk()->assertSee('Mesajın bize ulaştı.');
    }

    #[Test]
    public function only_super_admins_see_the_message_list(): void
    {
        config(['entegrasyon.super_admin_emails' => 'admin@34devs.test']);
        DB::table('contact_messages')->insert([
            'id' => (string) Str::uuid(),
            'name' => 'Mehmet', 'email' => 'm@example.com', 'phone' => null,
            'topic' => 'gorusme', 'service' => null, 'message' => 'Görüşmek istiyorum, salı uygun.',
            'mailed_at' => null, 'created_at' => now(),
        ]);

        $seller = User::factory()->create();
        (new CreateTenant)->run(name: 'Satıcı', owner: $seller);
        $this->actingAs($seller)->get('/admin/contact-messages')->assertForbidden();

        $admin = User::factory()->create(['email' => 'admin@34devs.test']);
        $page = $this->actingAs($admin)->get('/admin/contact-messages')->assertOk()->viewData('page');

        $this->assertSame('Admin/ContactMessages', $page['component']);
        $this->assertSame(1, $page['props']['messages']['total']);
        $row = $page['props']['messages']['data'][0];
        $this->assertSame('Görüşme', $row['topic']);
        $this->assertFalse($row['mailed']);
    }
}
