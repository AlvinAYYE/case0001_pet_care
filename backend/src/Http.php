<?php
declare(strict_types=1);

function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid JSON payload.',
        ], 400);
        exit;
    }

    return $decoded;
}

function pathSegments(string $path): array
{
    $trimmed = trim($path, '/');
    if ($trimmed === '') {
        return [];
    }

    return array_values(array_filter(explode('/', $trimmed), static fn ($segment): bool => $segment !== ''));
}

function intParam(mixed $value, string $fieldName): int
{
    if (!is_numeric($value) || (int) $value <= 0) {
        jsonResponse([
            'success' => false,
            'message' => sprintf('%s must be a positive integer.', $fieldName),
        ], 400);
        exit;
    }

    return (int) $value;
}
