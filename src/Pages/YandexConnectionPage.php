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
use MoonShine\UI\Fields\Text;
use Tikhomirov\MoonshineYandexMetrika\Support\WrapsPageComponentsInSpaceElements;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexOAuthApp;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexOAuthFlow;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

/**
 * «Подключение Яндекса»: какое OAuth-приложение используется, откуда значения
 * (БД или env), выданные и недостающие права, форма настроек и получение токена.
 */
class YandexConnectionPage extends Page
{
    use WrapsPageComponentsInSpaceElements;

    protected string $pageIcon = 'key';

    public function getBreadcrumbs(): array
    {
        return [
            $this->dashboardUrl() => (string) config('moonshine-yandex-metrika.page_title', 'Яндекс.Метрика'),
            '#' => $this->getTitle(),
        ];
    }

    protected function dashboardUrl(): string
    {
        return app(MetrikaDashboardPage::class)->getUrl();
    }

    /**
     * Ссылка «← Назад к Яндекс.Метрике»: страница не в меню, вход с дашборда.
     */
    protected function backLink(): FlexibleRender
    {
        return FlexibleRender::make(
            '<a href="'.e($this->dashboardUrl()).'" class="btn btn-secondary btn-sm ym-back-link">← '
            .e(__('moonshine-yandex-metrika::metrics.connection_back', [
                'title' => (string) config('moonshine-yandex-metrika.page_title', 'Яндекс.Метрика'),
            ]))
            .'</a>',
        );
    }

    public function getTitle(): string
    {
        return (string) __('moonshine-yandex-metrika::metrics.connection_title');
    }

    /**
     * @return list<ComponentContract>
     */
    protected function components(): iterable
    {
        $settings = app(YandexSettings::class);
        $flow = app(YandexOAuthFlow::class);

        if (! $settings->tableExists()) {
            return $this->wrapInSpaceElements([
                $this->backLink(),
                Alert::make('exclamation-triangle', 'warning')
                    ->content(__('moonshine-yandex-metrika::metrics.connection_no_table')),
                Box::make(__('moonshine-yandex-metrika::metrics.connection_status'), [
                    $this->statusTable($settings),
                ]),
            ]);
        }

        return $this->wrapInSpaceElements([
            $this->backLink(),
            ...$this->scopeAlerts($settings),
            Box::make(__('moonshine-yandex-metrika::metrics.connection_status'), [
                $this->statusTable($settings),
                $this->oauthActions($settings, $flow),
            ]),
            Box::make(__('moonshine-yandex-metrika::metrics.connection_settings'), [
                $this->settingsForm($settings),
            ]),
            Box::make(__('moonshine-yandex-metrika::metrics.connection_reset'), [
                FlexibleRender::make(
                    '<div class="mb-3 text-sm opacity-70">'
                    .e(__('moonshine-yandex-metrika::metrics.connection_reset_hint'))
                    .'</div>',
                ),
                FormBuilder::make(route('moonshine-yandex-metrika.connection.clear'))
                    ->name('yandex-connection-clear')
                    ->submit(__('moonshine-yandex-metrika::metrics.connection_reset_button'), ['class' => 'btn-error']),
            ]),
        ]);
    }

    /**
     * @return list<ComponentContract>
     */
    protected function scopeAlerts(YandexSettings $settings): array
    {
        $missing = $settings->missingScopes();

        if ($missing === null || $missing === []) {
            return [];
        }

        $app = new YandexOAuthApp($settings->clientId());

        return [
            Alert::make('exclamation-triangle', 'warning')
                ->content(__('moonshine-yandex-metrika::metrics.connection_missing_scopes', [
                    'scopes' => implode(', ', $missing),
                ])),
            FlexibleRender::make(
                '<div class="ym-connection-actions">'
                .'<a href="'.e($app->editUrl()).'" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">'
                .e(__('moonshine-yandex-metrika::metrics.oauth_open_app_add_scope', ['scope' => implode(', ', $missing)]))
                .'</a></div>',
            ),
        ];
    }

    protected function statusTable(YandexSettings $settings): FlexibleRender
    {
        $app = new YandexOAuthApp($settings->clientId());
        $rows = '';

        foreach (array_keys(YandexSettings::CONFIG_KEYS) as $key) {
            $value = $settings->get($key);

            if ($value === '') {
                $display = '<span class="opacity-60">'.e(__('moonshine-yandex-metrika::metrics.connection_not_set')).'</span>';
            } elseif (in_array($key, YandexSettings::SECRET_KEYS, true)) {
                $display = '<code>'.e(YandexSettings::mask($value)).'</code>';
            } elseif ($key === 'client_id' && $app->isKnown()) {
                $display = '<a href="'.e($app->appUrl()).'" target="_blank" rel="noopener noreferrer" class="underline"><code>'
                    .e($value).'</code></a>';
            } else {
                $display = '<code>'.e($value).'</code>';
            }

            $rows .= $this->row(
                (string) __('moonshine-yandex-metrika::metrics.connection_key_'.$key),
                $display,
                $this->sourceBadge($settings->source($key)),
            );
        }

        $scopes = $settings->scopes();
        $missing = $settings->missingScopes() ?? [];
        $scopesHtml = $scopes === null
            ? '<span class="opacity-60">'.e(__('moonshine-yandex-metrika::metrics.connection_scopes_unknown')).'</span>'
            : implode(' ', array_map(
                static fn (string $scope): string => '<code>'.e($scope).'</code>',
                $scopes,
            ));

        if ($missing !== []) {
            $scopesHtml .= '<div class="mt-1 text-sm">'
                .e(__('moonshine-yandex-metrika::metrics.connection_scopes_missing')).': '
                .implode(' ', array_map(static fn (string $scope): string => '<code>'.e($scope).'</code>', $missing))
                .'</div>';
        }

        $rows .= $this->row((string) __('moonshine-yandex-metrika::metrics.connection_scopes'), $scopesHtml, '');

        $obtainedAt = $settings->tokenObtainedAt();
        $rows .= $this->row(
            (string) __('moonshine-yandex-metrika::metrics.connection_token_obtained_at'),
            $obtainedAt === null ? '<span class="opacity-60">—</span>' : e(YandexSettings::formatDate($obtainedAt)),
            '',
        );

        $rows .= $this->row(
            (string) __('moonshine-yandex-metrika::metrics.connection_redirect_uri'),
            '<code>'.e(app(YandexOAuthFlow::class)->callbackUrl()).'</code>'
            .'<div class="mt-1 text-xs opacity-70">'.e(__('moonshine-yandex-metrika::metrics.connection_redirect_uri_hint')).'</div>',
            '',
        );

        return FlexibleRender::make(
            '<div class="overflow-x-auto"><table class="table ym-connection-status"><tbody>'.$rows.'</tbody></table></div>',
        );
    }

    protected function oauthActions(YandexSettings $settings, YandexOAuthFlow $flow): FlexibleRender
    {
        $app = new YandexOAuthApp($settings->clientId());
        $hasToken = $settings->token() !== '';
        $label = __('moonshine-yandex-metrika::metrics.'.($hasToken ? 'oauth_reconnect' : 'connection_get_token'));

        $buttons = $flow->canStart()
            ? '<a href="'.e(route('moonshine-yandex-metrika.oauth.redirect')).'" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-sm">'.e($label).'</a>'
            : '';

        $buttons .= '<a href="'.e($app->appUrl()).'" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">'
            .e(__($app->isKnown()
                ? 'moonshine-yandex-metrika::metrics.connection_open_app'
                : 'moonshine-yandex-metrika::metrics.oauth_open_apps'))
            .'</a>';

        $hint = $flow->canStart()
            ? ''
            : '<div class="mt-2 text-xs opacity-70">'.e(__('moonshine-yandex-metrika::metrics.oauth_need_client')).'</div>';

        return FlexibleRender::make(
            '<div class="mt-4"><div class="flex flex-wrap items-center gap-2">'.$buttons.'</div>'.$hint.'</div>',
        );
    }

    protected function settingsForm(YandexSettings $settings): FormBuilder
    {
        $record = $settings->record();
        $keep = (string) __('moonshine-yandex-metrika::metrics.connection_secret_keep');

        return FormBuilder::make(route('moonshine-yandex-metrika.connection.save'))
            ->name('yandex-connection-settings')
            ->fields([
                Text::make(__('moonshine-yandex-metrika::metrics.connection_key_client_id'), 'client_id')
                    ->hint(__('moonshine-yandex-metrika::metrics.connection_db_over_env')),
                Password::make(__('moonshine-yandex-metrika::metrics.connection_key_client_secret'), 'client_secret')
                    ->customAttributes(['placeholder' => $keep, 'autocomplete' => 'new-password']),
                Password::make(__('moonshine-yandex-metrika::metrics.connection_key_token'), 'token')
                    ->customAttributes(['placeholder' => $keep, 'autocomplete' => 'new-password'])
                    ->hint(__('moonshine-yandex-metrika::metrics.connection_token_hint')),
                Text::make(__('moonshine-yandex-metrika::metrics.connection_key_counter_id'), 'counter_id'),
                Text::make(__('moonshine-yandex-metrika::metrics.connection_key_host_id'), 'host_id')
                    ->customAttributes(['placeholder' => 'https:example.com:443']),
            ])
            ->fill([
                // Секреты в форму не попадают.
                'client_id' => $record?->client_id,
                'counter_id' => $record?->counter_id,
                'host_id' => $record?->host_id,
            ])
            ->submit(__('moonshine-yandex-metrika::metrics.connection_save'));
    }

    private function row(string $label, string $valueHtml, string $sourceHtml): string
    {
        return '<tr><th class="text-left align-top pr-4 whitespace-nowrap">'.e($label).'</th>'
            .'<td class="align-top">'.$valueHtml.'</td>'
            .'<td class="align-top whitespace-nowrap">'.$sourceHtml.'</td></tr>';
    }

    private function sourceBadge(?string $source): string
    {
        return match ($source) {
            YandexSettings::SOURCE_DB => '<span class="badge badge-success">'.e(__('moonshine-yandex-metrika::metrics.connection_source_db')).'</span>',
            YandexSettings::SOURCE_ENV => '<span class="badge badge-info">'.e(__('moonshine-yandex-metrika::metrics.connection_source_env')).'</span>',
            default => '<span class="badge">—</span>',
        };
    }
}
