<?php
declare(strict_types=1);

function app_config(string $name): string
{
    $value = getenv($name);
    if ($value === false) {
        throw new RuntimeException("Required configuration value is missing: {$name}");
    }

    return $value;
}

function app_environment(): string
{
    $environment = app_config('APP_ENV');
    if (!in_array($environment, ['development', 'testing', 'production'], true)) {
        throw new RuntimeException('APP_ENV must be development, testing, or production.');
    }

    return $environment;
}
