<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Services;

final readonly class ConnectionProbeResult
{
    private function __construct(
        public string $status,
        public string $message,
    ) {}

    public static function ok(): self
    {
        return new self('ok', '');
    }

    public static function notConfigured(string $message): self
    {
        return new self('not_configured', $message);
    }

    public static function failed(string $message): self
    {
        return new self('failed', $message);
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }
}
