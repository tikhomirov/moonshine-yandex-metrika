<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Pages;

use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Laravel\Pages\Page;
use MoonShine\UI\Components\Alert;
use MoonShine\UI\Components\FlexibleRender;
use MoonShine\UI\Components\FormBuilder;
use MoonShine\UI\Components\Layout\Box;
use MoonShine\UI\Fields\Password;
use MoonShine\UI\Fields\Select;
use MoonShine\UI\Fields\Text;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Services\YandexAccountService;
use Tikhomirov\MoonshineYandexMetrika\Support\WrapsPageComponentsInSpaceElements;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexOAuthApp;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexOAuthFlow;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

/**
 * «Подключение Яндекса» по шагам: 1) приложение, 2) токен, 3) счётчик и сайт.
 * Шаг неактивен, пока не выполнен предыдущий, но значения из env его не блокируют.
 */
class YandexConnectionPage extends Page
{
    use WrapsPageComponentsInSpaceElements;

    public const string CREATE_APP_URL = 'https://oauth.yandex.ru/client/new';

    protected string $pageIcon = 'key';

    public function getBreadcrumbs(): array
    {
        return [
            $this->dashboardUrl() => (string) config('moonshine-yandex-metrika.page_title', 'Яндекс.Метрика'),
            '#' => $this->getTitle(),
        ];
    }

    public function getTitle(): string
    {
        return (string) __('moonshine-yandex-metrika::metrics.connection_title');
    }

    protected function dashboardUrl(): string
    {
        return app(MetrikaDashboardPage::class)->getUrl();
    }

    /**
     * Приложение из настроек (БД/env), а если client_id не задан, то из токена (login.yandex.ru/info).
     */
    protected function oauthApp(YandexSettings $settings): YandexOAuthApp
    {
        if ($settings->clientId() !== '') {
            return new YandexOAuthApp($settings->clientId());
        }

        return $settings->token() !== ''
            ? app(YandexAccountService::class)->oauthApp()
            : new YandexOAuthApp(null);
    }

    /**
     * @return list<ComponentContract>
     */
    protected function components(): iterable
    {
        $settings = app(YandexSettings::class);

        return $this->wrapInSpaceElements([
            $this->backLink(),
            ...($settings->tableExists() ? [] : [
                Alert::make('exclamation-triangle', 'warning')
                    ->content(e(__('moonshine-yandex-metrika::metrics.connection_no_table'))),
            ]),
            $this->appStep($settings),
            $this->tokenStep($settings),
            $this->siteStep($settings),
            $this->bottomActions(),
        ]);
    }

    /**
     * Ссылка «← Назад к Яндекс.Метрике» и стили шагов.
     */
    protected function backLink(): FlexibleRender
    {
        return FlexibleRender::make(
            '<style>'
            .'.ym-notice-link{font-weight:600;text-decoration:underline;text-underline-offset:2px;white-space:nowrap;}'
            .'.ym-step__head{display:flex;align-items:center;gap:.75rem;margin-bottom:1rem;}'
            .'.ym-step__num{flex:none;width:2rem;height:2rem;border-radius:9999px;display:inline-flex;align-items:center;justify-content:center;font-weight:700;color:#fff;background:var(--ms-cm-primary,#7843e9);}'
            .'.ym-step--done .ym-step__num{background:#16a34a;}'
            .'.ym-step__title{font-size:1.125rem;font-weight:600;}'
            .'.ym-step--locked .ym-step__body{opacity:.5;}'
            .'.ym-step__body{display:flex;flex-direction:column;gap:1rem;}'
            .'.ym-step__body>fieldset{border:0;margin:0;padding:0;min-width:0;display:flex;flex-direction:column;gap:1rem;}'
            .'.ym-step__kv th{font-weight:500;opacity:.75;padding-right:1rem;white-space:nowrap;text-align:left;vertical-align:top;}'
            .'.ym-step__kv td{vertical-align:top;padding:.15rem 0;}'
            .'.ym-step__actions{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;}'
            .'.ym-step__manual summary{cursor:pointer;font-weight:500;}'
            .'.ym-step__manual[open] summary{margin-bottom:.75rem;}'
            .'</style>'
            .'<a href="'.e($this->dashboardUrl()).'" class="btn btn-secondary btn-sm ym-back-link">← '
            .e(__('moonshine-yandex-metrika::metrics.connection_back', [
                'title' => (string) config('moonshine-yandex-metrika.page_title', 'Яндекс.Метрика'),
            ]))
            .'</a>',
        );
    }

    /**
     * Шаг 1: приложение на oauth.yandex.ru, права, Redirect URI, ClientID и Client secret.
     */
    protected function appStep(YandexSettings $settings): Box
    {
        $flow = app(YandexOAuthFlow::class);
        $app = $this->oauthApp($settings);

        $links = '<a href="'.e(self::CREATE_APP_URL).'" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">'
            .e(__('moonshine-yandex-metrika::metrics.connection_create_app')).' ↗</a>';

        if ($app->isKnown()) {
            $links .= '<a href="'.e($app->appUrl()).'" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">'
                .e(__('moonshine-yandex-metrika::metrics.connection_open_app')).' ↗</a>';
        }

        $scopes = implode(' ', array_map(
            static fn (string $scope): string => '<code>'.e($scope).'</code>',
            (array) config('moonshine-yandex-metrika.oauth_scopes', []),
        ));

        $kv = $this->kv([
            [__('moonshine-yandex-metrika::metrics.connection_required_scopes'), $scopes, ''],
            [__('moonshine-yandex-metrika::metrics.connection_redirect_uri'), $this->copyable($flow->callbackUrl()), ''],
        ]);

        // Значения из env или из токена видны как placeholder, секрет — только маской.
        $clientIdHint = $settings->clientId() !== '' ? $settings->clientId() : (string) ($app->clientId ?? '');
        $secret = $settings->clientSecret();

        $form = FormBuilder::make(route('moonshine-yandex-metrika.connection.save'))
            ->name('yandex-connection-app')
            ->fields([
                Text::make(__('moonshine-yandex-metrika::metrics.connection_key_client_id'), 'client_id')
                    ->customAttributes(['placeholder' => $clientIdHint]),
                Password::make(__('moonshine-yandex-metrika::metrics.connection_key_client_secret'), 'client_secret')
                    ->customAttributes([
                        'placeholder' => $secret === '' ? '' : YandexSettings::mask($secret),
                        'autocomplete' => 'new-password',
                    ]),
            ])
            ->fill(['client_id' => $settings->record()?->client_id])
            ->submit(__('moonshine-yandex-metrika::metrics.connection_save'));

        return $this->step(1, 'connection_step_app', $flow->canStart(), false, [
            '<div class="ym-step__actions">'.$links.'</div>',
            $kv,
            (string) $form->render(),
        ]);
    }

    /**
     * Шаг 2: токен через OAuth (активен после шага 1), ручной ввод свёрнут.
     */
    protected function tokenStep(YandexSettings $settings): Box
    {
        $flow = app(YandexOAuthFlow::class);
        $token = $settings->token();
        $hasToken = $token !== '';
        $label = e(__('moonshine-yandex-metrika::metrics.'.($hasToken ? 'oauth_reconnect' : 'connection_get_token_yandex')));

        $button = $flow->canStart()
            ? '<a href="'.e(route('moonshine-yandex-metrika.oauth.redirect')).'" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-lg ym-oauth-button">'.$label.'</a>'
            : '<button type="button" class="btn btn-primary btn-lg ym-oauth-button" disabled>'.$label.'</button>';

        $parts = ['<div class="ym-step__actions">'.$button.'</div>'];

        if ($hasToken) {
            $profile = app(YandexAccountService::class)->profile();
            $scopes = $settings->scopes();
            $missing = $settings->missingScopes() ?? [];

            $scopesHtml = $scopes === null
                ? '<span class="opacity-60">'.e(__('moonshine-yandex-metrika::metrics.connection_scopes_unknown')).'</span>'
                : implode(' ', array_map(static fn (string $scope): string => '<code>'.e($scope).'</code>', $scopes));

            $obtainedAt = $settings->tokenObtainedAt();

            $parts[] = $this->kv([
                [__('moonshine-yandex-metrika::metrics.connection_account'), $this->valueOrDash((string) ($profile['email'] ?? $profile['login'] ?? '')), ''],
                [__('moonshine-yandex-metrika::metrics.connection_key_token'), '<code>'.e(YandexSettings::mask($token)).'</code>', $this->sourceBadge($settings->source('token'))],
                [__('moonshine-yandex-metrika::metrics.connection_scopes'), $scopesHtml, ''],
                [__('moonshine-yandex-metrika::metrics.connection_token_obtained_at'), $obtainedAt === null ? '—' : e(YandexSettings::formatDate($obtainedAt)), ''],
            ]);

            if ($missing !== []) {
                $app = $this->oauthApp($settings);
                $parts[] = (string) Alert::make('exclamation-triangle', 'warning')->content(
                    e(__('moonshine-yandex-metrika::metrics.connection_missing_scopes', ['scopes' => implode(', ', $missing)]))
                    .' <a href="'.e($app->editUrl()).'" target="_blank" rel="noopener noreferrer" class="ym-notice-link">'
                    .e(__('moonshine-yandex-metrika::metrics.oauth_open_app_add_scope', ['scope' => implode(', ', $missing)])).' ↗</a>',
                )->render();
            }
        }

        $manual = FormBuilder::make(route('moonshine-yandex-metrika.connection.save'))
            ->name('yandex-connection-token')
            ->fields([
                Password::make(__('moonshine-yandex-metrika::metrics.connection_key_token'), 'token')
                    ->customAttributes(['autocomplete' => 'new-password']),
            ])
            ->submit(__('moonshine-yandex-metrika::metrics.connection_save'));

        $parts[] = '<details class="ym-step__manual"><summary>'
            .e(__('moonshine-yandex-metrika::metrics.connection_manual_token'))
            .'</summary>'.$manual->render().'</details>';

        $locked = ! $flow->canStart() && ! $hasToken;

        return $this->step(2, 'connection_step_token', $hasToken, $locked, $parts);
    }

    /**
     * Шаг 3: счётчик Метрики и хост Вебмастера, автовыбор по APP_URL. Активен, когда есть токен.
     */
    protected function siteStep(YandexSettings $settings): Box
    {
        $metrika = app(MetrikaService::class);
        $webmaster = app(WebmasterService::class);
        $hasToken = $settings->token() !== '';
        $parts = [];

        if ($hasToken && $this->counters() === null) {
            $parts[] = $this->failNotice('connection_counters_failed', 'https://metrika.yandex.ru/list', 'connection_open_metrika');
        }

        if ($hasToken && $this->hosts() === null) {
            $parts[] = $this->failNotice('connection_hosts_failed', 'https://webmaster.yandex.ru/sites/', 'connection_open_webmaster');
        }

        $record = $settings->record();

        $form = FormBuilder::make(route('moonshine-yandex-metrika.connection.save'))
            ->name('yandex-connection-site')
            ->fields([
                $this->counterField($record?->counter_id),
                $this->hostField($record?->host_id),
            ])
            ->fill([
                'counter_id' => $record?->counter_id,
                'host_id' => $record?->host_id,
            ])
            ->submit(__('moonshine-yandex-metrika::metrics.connection_save'));

        // Статус: что используется сейчас и откуда (БД, env, авто).
        $status = [];

        foreach ([
            'connection_key_counter_id' => [$metrika->counterId(), $metrika->counterSource()],
            'connection_key_host_id' => [$webmaster->hostId(), $webmaster->hostSource()],
        ] as $key => [$value, $source]) {
            if ($value !== '') {
                $status[] = [__('moonshine-yandex-metrika::metrics.'.$key), '<code>'.e($value).'</code>', $this->sourceBadge($source)];
            }
        }

        if ($status !== []) {
            $parts[] = $this->kv($status);
        }

        $parts[] = (string) $form->render();

        $done = $metrika->counterId() !== '' && $webmaster->hostId() !== '';
        // Без токена шаг неактивен, но значения из env его не блокируют.
        $locked = ! $hasToken && $settings->configValue('counter_id') === '' && $settings->configValue('host_id') === '';

        return $this->step(3, 'connection_step_site', $done, $locked, $parts);
    }

    /**
     * «Проверить подключение» и «Сбросить».
     */
    protected function bottomActions(): FlexibleRender
    {
        $csrf = csrf_field();

        return FlexibleRender::make(
            '<div class="ym-step__actions ym-connection-bottom">'
            .'<form method="post" action="'.e(route('moonshine-yandex-metrika.connection.check')).'">'.$csrf
            .'<button type="submit" class="btn btn-primary">'.e(__('moonshine-yandex-metrika::metrics.connection_check')).'</button></form>'
            .'<form method="post" action="'.e(route('moonshine-yandex-metrika.connection.clear')).'">'.$csrf
            .'<button type="submit" class="btn btn-error">'.e(__('moonshine-yandex-metrika::metrics.connection_reset_button')).'</button></form>'
            .'</div>',
        );
    }

    /**
     * @param  list<string>  $parts  готовый HTML частей шага
     */
    protected function step(int $number, string $titleKey, bool $done, bool $locked, array $parts): Box
    {
        $status = $done
            ? '<span class="badge badge-success">'.e(__('moonshine-yandex-metrika::metrics.connection_step_done')).'</span>'
            : '<span class="badge badge-warning">'.e(__('moonshine-yandex-metrika::metrics.connection_step_todo')).'</span>';

        $classes = 'ym-step ym-step-'.$number.($done ? ' ym-step--done' : '').($locked ? ' ym-step--locked' : '');
        $body = implode('', $parts);

        return Box::make([
            FlexibleRender::make(
                '<div class="'.$classes.'" data-step="'.$number.'" data-status="'.($done ? 'done' : 'todo').'">'
                .'<div class="ym-step__head"><span class="ym-step__num">'.$number.'</span>'
                .'<span class="ym-step__title">'.e(__('moonshine-yandex-metrika::metrics.'.$titleKey)).'</span>'.$status.'</div>'
                .'<div class="ym-step__body">'.($locked ? '<fieldset disabled class="contents">'.$body.'</fieldset>' : $body).'</div>'
                .'</div>',
            ),
        ]);
    }

    /**
     * Счётчик Метрики: выпадающий список по токену, при ошибке API — текстовое поле.
     */
    protected function counterField(?string $stored): Text|Select
    {
        $label = __('moonshine-yandex-metrika::metrics.connection_key_counter_id');
        $counters = $this->counters();

        if ($counters === null) {
            return Text::make($label, 'counter_id')->customAttributes([
                'inputmode' => 'numeric',
                'placeholder' => app(MetrikaService::class)->counterId(),
            ]);
        }

        $options = [];

        foreach ($counters as $counter) {
            $options[$counter['id']] = self::counterLabel($counter);
        }

        $metrika = app(MetrikaService::class);

        return Select::make($label, 'counter_id')
            ->options($this->withAuto($options, $stored, $metrika->counterSource(), $metrika->counterId()))
            ->native();
    }

    /**
     * Сайт Вебмастера: выпадающий список по токену, при ошибке API — текстовое поле.
     */
    protected function hostField(?string $stored): Text|Select
    {
        $label = __('moonshine-yandex-metrika::metrics.connection_key_host_id');
        $hosts = $this->hosts();

        if ($hosts === null) {
            return Text::make($label, 'host_id')->customAttributes([
                'placeholder' => app(WebmasterService::class)->hostId() ?: 'https:example.com:443',
            ]);
        }

        $options = [];

        foreach ($hosts as $host) {
            $options[$host['host_id']] = $host['url'];
        }

        $webmaster = app(WebmasterService::class);

        return Select::make($label, 'host_id')
            ->options($this->withAuto($options, $stored, $webmaster->hostSource(), $webmaster->hostId()))
            ->native();
    }

    /**
     * @param  array{id: string, name: string, site: string}  $counter
     */
    public static function counterLabel(array $counter): string
    {
        return implode(' — ', array_filter([$counter['name'], $counter['site']], static fn (string $v): bool => $v !== ''))
            .' ('.$counter['id'].')';
    }

    /**
     * Первый пункт «Авто (по APP_URL): …», сохранённое значение не теряется, даже если его нет в списке.
     *
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    private function withAuto(array $options, ?string $stored, ?string $source, string $resolved): array
    {
        $auto = (string) __('moonshine-yandex-metrika::metrics.connection_host_auto');

        if ($source === YandexSettings::SOURCE_AUTO && isset($options[$resolved])) {
            $auto .= ': '.$options[$resolved];
        }

        if ($stored !== null && $stored !== '' && ! isset($options[$stored])) {
            $options[$stored] = $stored;
        }

        return ['' => $auto] + $options;
    }

    /**
     * @return list<array{id: string, name: string, site: string, sites: list<string>}>|null
     */
    protected function counters(): ?array
    {
        return app(YandexSettings::class)->token() !== ''
            ? app(MetrikaService::class)->counters()
            : null;
    }

    /**
     * @return list<array{host_id: string, url: string, verified: bool}>|null
     */
    protected function hosts(): ?array
    {
        return app(YandexSettings::class)->token() !== ''
            ? app(WebmasterService::class)->hosts()
            : null;
    }

    private function failNotice(string $messageKey, string $url, string $linkKey): string
    {
        return (string) Alert::make('information-circle', 'info')->content(
            e(__('moonshine-yandex-metrika::metrics.'.$messageKey))
            .' <a href="'.e($url).'" target="_blank" rel="noopener noreferrer" class="ym-notice-link">'
            .e(__('moonshine-yandex-metrika::metrics.'.$linkKey)).' ↗</a>',
        )->render();
    }

    /**
     * Кнопка копирования Redirect URI.
     */
    private function copyable(string $value): string
    {
        $copy = e(__('moonshine-yandex-metrika::metrics.connection_copy'));
        $copied = e(__('moonshine-yandex-metrika::metrics.connection_copied'));

        return '<span class="inline-flex flex-wrap items-center gap-2"><code class="ym-redirect-uri">'.e($value).'</code>'
            .'<button type="button" class="btn btn-secondary btn-xs ym-copy" data-copy="'.e($value).'" x-data="{ done: false }"'
            .' @click="navigator.clipboard.writeText($el.dataset.copy); done = true; setTimeout(() => done = false, 1500)"'
            .' x-text="done ? \''.$copied.'\' : \''.$copy.'\'">'.$copy.'</button></span>';
    }

    /**
     * @param  list<array{0: mixed, 1: string, 2: string}>  $rows  подпись, HTML значения, HTML бейджа
     */
    private function kv(array $rows): string
    {
        $html = '';

        foreach ($rows as [$label, $value, $badge]) {
            $html .= '<tr><th>'.e((string) $label).'</th><td>'.$value.($badge !== '' ? ' '.$badge : '').'</td></tr>';
        }

        return '<div class="overflow-x-auto"><table class="ym-step__kv"><tbody>'.$html.'</tbody></table></div>';
    }

    private function valueOrDash(string $value, ?string $url = null): string
    {
        if ($value === '') {
            return '<span class="opacity-60">'.e(__('moonshine-yandex-metrika::metrics.connection_not_set')).'</span>';
        }

        $code = '<code>'.e($value).'</code>';

        return $url === null
            ? $code
            : '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer" class="underline">'.$code.'</a>';
    }

    private function sourceBadge(?string $source): string
    {
        return match ($source) {
            YandexSettings::SOURCE_DB => '<span class="badge badge-success">'.e(__('moonshine-yandex-metrika::metrics.connection_source_db')).'</span>',
            YandexSettings::SOURCE_ENV => '<span class="badge badge-info">'.e(__('moonshine-yandex-metrika::metrics.connection_source_env')).'</span>',
            YandexSettings::SOURCE_AUTO => '<span class="badge badge-primary">'.e(__('moonshine-yandex-metrika::metrics.connection_source_auto')).'</span>',
            default => '',
        };
    }
}
