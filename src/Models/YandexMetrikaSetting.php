<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Единственная строка с настройками подключения к Яндексу.
 *
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string|null $token
 * @property string|null $counter_id
 * @property string|null $host_id
 * @property array<int, string>|null $scopes
 * @property Carbon|null $token_obtained_at
 */
class YandexMetrikaSetting extends Model
{
    protected $fillable = [
        'client_id',
        'client_secret',
        'token',
        'counter_id',
        'host_id',
        'scopes',
        'token_obtained_at',
    ];

    protected $hidden = [
        'client_secret',
        'token',
    ];

    public function getTable(): string
    {
        return (string) config('moonshine-yandex-metrika.settings_table', 'yandex_metrika_settings');
    }

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'token' => 'encrypted',
            'scopes' => 'array',
            'token_obtained_at' => 'datetime',
        ];
    }
}
