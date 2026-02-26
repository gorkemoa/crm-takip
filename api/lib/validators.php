<?php
declare(strict_types=1);

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function text_slice(string $value, int $start, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, $start, $length);
    }

    return substr($value, $start, $length);
}

function request_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        parse_str($raw, $formDecoded);
        if (is_array($formDecoded) && $formDecoded) {
            return $formDecoded;
        }

        error_response('INVALID_JSON', 'Geçersiz JSON gövdesi.', 422);
    }

    if (!empty($_POST) && is_array($_POST)) {
        return $_POST;
    }

    return [];
}

function require_fields(array $input, array $rules): void
{
    $errors = [];

    foreach ($rules as $field => $rule) {
        $value = $input[$field] ?? null;

        if (($rule['required'] ?? false) && ($value === null || $value === '')) {
            $errors[$field] = 'Bu alan zorunludur.';
            continue;
        }

        if ($value === null || $value === '') {
            continue;
        }

        if (isset($rule['min']) && text_length((string) $value) < (int) $rule['min']) {
            $errors[$field] = "En az {$rule['min']} karakter olmalı.";
        }

        if (isset($rule['max']) && text_length((string) $value) > (int) $rule['max']) {
            $errors[$field] = "En fazla {$rule['max']} karakter olmalı.";
        }

        if (($rule['email'] ?? false)) {
            $emailValue = (string) $value;
            $validStandard = filter_var($emailValue, FILTER_VALIDATE_EMAIL) !== false;
            $validLocalDomain = preg_match('/^[^\\s@]+@[^\\s@]+$/', $emailValue) === 1;
            if (!$validStandard && !$validLocalDomain) {
                $errors[$field] = 'Geçerli bir e-posta girin.';
            }
        }

        if (isset($rule['in']) && !in_array($value, (array) $rule['in'], true)) {
            $errors[$field] = 'Geçersiz değer.';
        }
    }

    if ($errors) {
        error_response('VALIDATION_ERROR', 'Form doğrulama hatası.', 422, $errors);
    }
}

function clean_text(?string $value, int $max = 2000): ?string
{
    if ($value === null) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    if (text_length($trimmed) > $max) {
        return text_slice($trimmed, 0, $max);
    }

    return $trimmed;
}

function bool_param(mixed $value, bool $default = false): bool
{
    if ($value === null) {
        return $default;
    }

    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower((string) $value);
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}
