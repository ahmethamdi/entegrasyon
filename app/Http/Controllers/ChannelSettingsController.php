<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Contracts\DeclaresConnectionSettings;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ConnectionSettingField;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Bağlantı sonrası kanal ayarları (`DeclaresConnectionSettings`).
 *
 * ⚠️ DOĞRULAMA KANALIN CANLI SEÇENEKLERİNE KARŞI YAPILIR. Kaydederken
 * seçenekler yeniden okunur: ekran açıkken satıcı Etsy'de profili silmiş
 * ya da bozmuş olabilir; eski listeye güvenilseydi kayıt "başarılı" görünür,
 * ilk ilan Etsy'de 400 ile düşerdi.
 *
 * ⚠️ YALNIZCA TANIMLI ANAHTARLAR YAZILIR. İstekteki her alan `settings`'e
 * geçseydi satıcı `shop_id`'yi ya da öğrenilmiş hız sınırını ezebilirdi.
 */
final class ChannelSettingsController extends Controller
{
    public function __construct(
        private readonly AdapterRegistry $registry,
    ) {}

    public function edit(string $connection): InertiaResponse
    {
        [$model, $adapter] = $this->resolve($connection);

        return Inertia::render('Channels/Settings', [
            'connection' => [
                'id' => $model->id,
                'label' => $model->label,
                'channel' => $model->channelType?->name ?? $model->channel_type_code,
                'account' => $model->external_account_id,
            ],
            'fields' => array_map(
                static fn (ConnectionSettingField $field): array => $field->toArray(),
                $adapter->connectionSettingFields(),
            ),
        ]);
    }

    public function update(Request $request, string $connection): RedirectResponse
    {
        [$model, $adapter] = $this->resolve($connection);

        $settings = $model->settings ?? [];
        $errors = [];

        foreach ($adapter->connectionSettingFields() as $field) {
            $value = trim((string) $request->input($field->key, ''));

            if ($value === '') {
                if ($field->required) {
                    $errors[$field->key] = __('Bu ayar zorunlu.');

                    continue;
                }

                unset($settings[$field->key]);

                continue;
            }

            if ($field->optionsError !== null) {
                // Seçenekler okunamadıysa değer DOĞRULANAMAZ; körlemesine
                // yazmak kanalın reddedeceği bir değeri kaydetmek olurdu.
                $errors[$field->key] = $field->optionsError;

                continue;
            }

            if (! $field->accepts($value)) {
                $errors[$field->key] = __('Bu seçenek kullanılamaz.');

                continue;
            }

            $settings[$field->key] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $model->forceFill(['settings' => $settings])->save();

        return redirect()->route('channels.index')->with(
            'success',
            __(':label ayarları kaydedildi.', ['label' => $model->label]),
        );
    }

    /**
     * Kiracı scope'u altında bağlantı + ayar bildiren adapter.
     *
     * @return array{0: ChannelConnection, 1: DeclaresConnectionSettings}
     */
    private function resolve(string $connection): array
    {
        // Başka kiracının bağlantısı 404 (global scope).
        $model = ChannelConnection::query()
            ->with('channelType:code,name,adapter_class')
            ->findOrFail($connection);

        $adapter = $this->registry->for($model);

        abort_unless($adapter instanceof DeclaresConnectionSettings, 404);

        return [$model, $adapter];
    }
}
