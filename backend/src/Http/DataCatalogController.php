<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WorkflowEngine\Contracts\DataCatalogInterface;

/**
 * Liefert den Katalog abfragbarer Entitaeten/Felder (fuer den Datencheck-Schritt im Editor).
 */
final class DataCatalogController
{
    public function __construct(private readonly DataCatalogInterface $catalog)
    {
    }

    /**
     * GET /data-catalog
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(
            json_encode(['entities' => $this->catalog->entities()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        return $response->withHeader('Content-Type', 'application/json');
    }
}
