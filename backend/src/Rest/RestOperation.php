<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

/** Methode und Pfad eines Vorgangs, etwa `GET /antraege/{id}`. */
final readonly class RestOperation
{
    public function __construct(
        public string $method,
        public string $path,
    ) {
    }

    /** @param array<string, string> $query */
    public function url(string $baseUrl, ?string $id, array $query = []): string
    {
        $path = str_replace('{id}', rawurlencode($id ?? ''), $this->path);
        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

        return $query === [] ? $url : $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
