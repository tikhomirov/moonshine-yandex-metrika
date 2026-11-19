<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

if (is_string($path) && str_contains($path, 'metrika-500')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"errors":[]}';

    return true;
}

if (is_string($path) && str_contains($path, 'metrika-ok')) {
    header('Content-Type: application/json');
    echo '{"data":[],"totals":[1]}';

    return true;
}

return false;
