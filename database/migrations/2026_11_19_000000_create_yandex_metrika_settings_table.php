<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tikhomirov\MoonshineYandexMetrika\Support\PackageConfig;

return new class extends Migration
{
    public function up(): void
    {
        $table = PackageConfig::string('moonshine-yandex-metrika.settings_table', 'yandex_metrika_settings');

        Schema::create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('client_id')->nullable();
            // client_secret и token хранятся через encrypted cast (APP_KEY).
            $table->text('client_secret')->nullable();
            $table->text('token')->nullable();
            $table->string('counter_id')->nullable();
            $table->string('host_id')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamp('token_obtained_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::string('moonshine-yandex-metrika.settings_table', 'yandex_metrika_settings'));
    }
};
