<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WorkflowEngine\Contracts\UploadHandlerCatalogInterface;

/**
 * Liefert den Katalog der Datei-Pruefungen (fuer `file`-Felder im Editor).
 */
final class UploadHandlerController
{
    public function __construct(private readonly UploadHandlerCatalogInterface $catalog)
    {
    }

    /**
     * GET /upload-handlers
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(
            json_encode(['handlers' => $this->catalog->handlers()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        return $response->withHeader('Content-Type', 'application/json');
    }
}
