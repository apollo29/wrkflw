<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Http\ApiFactory;

/** `docs/openapi.yaml` beschreibt genau die Routen der ApiFactory. */
final class OpenApiTest extends TestCase
{
    public function testDocumentedRoutesMatchTheFactory(): void
    {
        $yaml = file_get_contents(dirname(__DIR__, 4) . '/docs/openapi.yaml');
        self::assertIsString($yaml);
        self::assertStringContainsString('version: 3.0.0', $yaml);

        $documented = [];
        $path = null;
        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            if (preg_match('#^  (/[^:]*):\s*$#', $line, $m) === 1) {
                $path = $m[1];
            } elseif (preg_match('/^\S/', $line) === 1) {
                $path = null;
            } elseif ($path !== null && preg_match('/^    (get|post|put|delete|patch):/', $line, $m) === 1) {
                $documented[] = strtoupper($m[1]) . ' ' . $path;
            }
        }

        $routes = ApiFactory::routeList();
        sort($routes);
        sort($documented);
        self::assertSame($routes, $documented);
    }
}
