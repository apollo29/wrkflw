<?php

declare(strict_types=1);

/*
 * HTTP-Einstieg der Engine (Slim 4), z. B. `php -S 127.0.0.1:8080 -t api`.
 *
 * WF_API_BOOTSTRAP zeigt auf eine PHP-Datei der Host-App, die ein fertig verdrahtetes
 * WorkflowEngine\Http\ApiServices zurückgibt (Engine, Ablage, Aktionen, Datenmodelle,
 * optional Datenquellen). Die Engine kennt die Host-App nicht (ADR 1).
 *
 * Zugriff (ADR 11):
 *  - WF_API_KEY gesetzt   → `Authorization: Bearer <key>`, Actor «api» mit Admin-Recht
 *  - WF_API_OPEN=1        → ohne Anmeldung (nur Entwicklung und Demo)
 *  - sonst                → die Bootstrap-Datei gibt [ApiServices, AccessResolverInterface] zurück
 */

use WorkflowEngine\Contracts\AccessResolverInterface;
use WorkflowEngine\Http\ApiFactory;
use WorkflowEngine\Http\ApiKeyAccessResolver;
use WorkflowEngine\Http\ApiServices;
use WorkflowEngine\Http\OpenAccessResolver;

foreach ([dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 4) . '/autoload.php'] as $file) {
    if (is_file($file)) {
        require $file;
        break;
    }
}

$fail = static function (string $message): never {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['code' => 'configuration', 'message' => $message]], JSON_UNESCAPED_UNICODE);
    error_log('wrkflw: ' . $message);
    exit;
};

$bootstrap = getenv('WF_API_BOOTSTRAP');
if (!is_string($bootstrap) || !is_file($bootstrap)) {
    $fail('WF_API_BOOTSTRAP muss auf eine PHP-Datei zeigen, die ApiServices zurückgibt.');
}

$result = require $bootstrap;
[$services, $access] = is_array($result) ? $result + [null, null] : [$result, null];
if (!$services instanceof ApiServices) {
    $fail('Die Bootstrap-Datei hat kein ApiServices zurückgegeben.');
}

if (!$access instanceof AccessResolverInterface) {
    $key = getenv('WF_API_KEY');
    $access = match (true) {
        is_string($key) && $key !== '' => new ApiKeyAccessResolver($key),
        getenv('WF_API_OPEN') === '1' => new OpenAccessResolver(),
        default => $fail('Kein Zugriff eingerichtet: WF_API_KEY setzen, WF_API_OPEN=1 (nur Entwicklung) oder einen AccessResolver liefern.'),
    };
}

ApiFactory::create($services, $access)->run();
