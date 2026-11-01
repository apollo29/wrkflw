<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WorkflowEngine\Contracts\UploadHandler;

/** Was der Editor zur Auswahl anbietet: Aktionen, Datenmodelle, Datei-Prüfungen. */
final readonly class CatalogController
{
    public function __construct(private ApiServices $s)
    {
    }

    public function actions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return JsonIo::json($response, ['actions' => $this->s->actions->catalog()]);
    }

    /** `?writable=1`: nur, was ein Ablauf ändern oder anlegen darf (F-121). */
    public function dataModels(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $writable = in_array(JsonIo::query($request, 'writable'), ['1', 'true'], true);

        return JsonIo::json($response, ['models' => $writable ? $this->s->catalog->writable() : $this->s->catalog->readable()]);
    }

    public function uploadHandlers(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return JsonIo::json($response, [
            'handlers' => array_map(
                static fn (UploadHandler $h): array => ['key' => $h->key, 'label' => $h->label, 'description' => $h->description],
                $this->s->uploadHandlers?->handlers() ?? [],
            ),
        ]);
    }
}
