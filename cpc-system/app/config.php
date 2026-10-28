<?php
declare(strict_types=1);

function app_config(string $key, ?string $default = null): string
{
    static $loaded = false;
    if (!$loaded) {
        $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim(trim($value), "\"'");
                if (preg_match('/^CPC_[A-Z0-9_]+$/', $name) && getenv($name) === false) {
                    putenv($name . '=' . $value);
                }
            }
        }
        $loaded = true;
    }
    $value = getenv('CPC_' . strtoupper($key));
    return $value === false ? ($default ?? '') : $value;
}

function app_base_url(): string
{
    $baseUrl = trim(app_config('base_url', '/cpc-system'));
    return '/' . trim($baseUrl, '/');
}

function app_absolute_url(string $path): string
{
    $configured = rtrim(app_config('app_url', 'http://localhost' . app_base_url()), '/');
    if (!filter_var($configured, FILTER_VALIDATE_URL) || !in_array(parse_url($configured, PHP_URL_SCHEME), ['http', 'https'], true)) {
        throw new RuntimeException('CPC_APP_URL must be an absolute HTTP or HTTPS URL.');
    }
    return $configured . '/' . ltrim($path, '/');
}
