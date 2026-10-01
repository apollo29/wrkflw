<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WorkflowEngine\Contracts\DataCatalogInterface;
use WorkflowEngine\Contracts\DataWriteCatalogInterface;

/**
 * Liefert den Katalog abfragbarer Entitaeten/Felder (fuer den Datencheck-Schritt im Editor)
 * und — getrennt davon — den der beschreibbaren (fuer den Schreib-Schritt).
 */
final class DataCatalogController
{
    public function __construct(
        private readonly DataCatalogInterface $catalog,
        /**
         * Optional: eine Host-App ohne Schreib-Port hat den Schreib-Schritt
         * nicht. Der Endpunkt antwortet dann mit einer leeren Liste statt mit
         * 404 — der Editor zeigt «keine beschreibbaren Tabellen», und nicht
         * jedes Oeffnen schreibt einen Fehler ins Log.
         */
        private readonly ?DataWriteCatalogInterface $writeCatalog = null,
    ) {
    }

    /**
     * GET /data-catalog
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, ['entities' => $this->catalog->entities()]);
    }

    /**
     * GET /data-catalog/writable
     */
    public function writable(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, [
            'entities' => $this->writeCatalog?->writableEntities() ?? [],
        ]);
    }

    /**
     * @param array<string,mixed> $daten
     */
    private function json(ResponseInterface $response, array $daten): ResponseInterface
    {
        $response->getBody()->write(
            json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        return $response->withHeader('Content-Type', 'application/json');
    }
}
