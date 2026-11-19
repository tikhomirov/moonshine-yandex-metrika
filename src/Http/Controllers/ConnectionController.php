<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use MoonShine\Support\Enums\ToastType;
use RuntimeException;
use Tikhomirov\MoonshineYandexMetrika\Pages\YandexConnectionPage;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbe;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexOAuthFlow;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

/**
 * Страница «Подключение Яндекса»: сохранение настроек и OAuth authorization code flow.
 */
final class ConnectionController extends Controller
{
    public function save(Request $request, YandexSettings $settings): RedirectResponse
    {
        if (! $settings->tableExists()) {
            return $this->back(__('moonshine-yandex-metrika::metrics.connection_no_table'), ToastType::ERROR);
        }

        $data = $request->validate([
            'client_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{8,64}$/'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'token' => ['nullable', 'string', 'max:2048'],
            'counter_id' => ['nullable', 'regex:/^\d{1,20}$/'],
            'host_id' => ['nullable', 'string', 'max:255'],
        ]);

        // Пустые секреты не трогаем: поле пароля приходит пустым, если его не меняли.
        foreach (YandexSettings::SECRET_KEYS as $secret) {
            if (($data[$secret] ?? null) === null || $data[$secret] === '') {
                unset($data[$secret]);
            }
        }

        $settings->save($data);

        return $this->back(__('moonshine-yandex-metrika::metrics.connection_saved'), ToastType::SUCCESS);
    }

    public function clear(YandexSettings $settings): RedirectResponse
    {
        $settings->clear();

        return $this->back(__('moonshine-yandex-metrika::metrics.connection_cleared'), ToastType::SUCCESS);
    }

    /**
     * «Проверить подключение»: свежий запрос к Метрике без кеша проверки.
     */
    public function check(): RedirectResponse
    {
        $result = app(ConnectionProbe::class)->fresh();

        return $result->isOk()
            ? $this->back(__('moonshine-yandex-metrika::metrics.connection_check_ok'), ToastType::SUCCESS)
            : $this->back($result->message, ToastType::ERROR);
    }

    public function redirect(Request $request, YandexOAuthFlow $flow, YandexSettings $settings): RedirectResponse
    {
        if (! $settings->tableExists()) {
            return $this->back(__('moonshine-yandex-metrika::metrics.connection_no_table'), ToastType::ERROR);
        }

        if (! $flow->canStart()) {
            return $this->back(__('moonshine-yandex-metrika::metrics.oauth_need_client'), ToastType::ERROR);
        }

        return redirect()->away($flow->authorizationUrl($request->session()));
    }

    public function callback(Request $request, YandexOAuthFlow $flow): RedirectResponse
    {
        $error = (string) $request->query('error', '');

        if ($error !== '') {
            $request->session()->forget('moonshine-yandex-metrika.oauth');
            $description = (string) $request->query('error_description', $error);

            return $this->back(
                __('moonshine-yandex-metrika::metrics.oauth_denied', ['error' => $description]),
                ToastType::ERROR,
            );
        }

        try {
            $scopes = $flow->complete(
                $request->session(),
                (string) $request->query('state', ''),
                (string) $request->query('code', ''),
            );
        } catch (RuntimeException $e) {
            return $this->back($e->getMessage(), ToastType::ERROR);
        }

        return $this->back(
            __('moonshine-yandex-metrika::metrics.oauth_connected', ['scopes' => implode(', ', $scopes)]),
            ToastType::SUCCESS,
        );
    }

    private function back(string $message, ToastType $type): RedirectResponse
    {
        toast($message, $type);

        return redirect()->to(app(YandexConnectionPage::class)->getUrl());
    }
}
