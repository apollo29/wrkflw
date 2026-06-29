<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * PORT: Die Host-App implementiert dieses Interface, damit die Engine auf ihre
 * Datenstruktur zugreifen kann, ohne sie zu kennen (Dependency Inversion).
 *
 * Darueber realisieren datengetriebene Trigger ihr Polling und Bedingungen
 * koennen auf Host-Daten zugreifen.
 */
interface DataProviderInterface
{
    /**
     * Eine einzelne Entitaet als assoziatives Array liefern (oder null).
     * Beispiel: get('order', '123') => ['id' => 123, 'status' => 'paid', ...]
     *
     * @return array<string,mixed>|null
     */
    public function get(string $entity, string|int $id): ?array;

    /**
     * Entitaeten finden, die einem einfachen Kriterien-Set entsprechen.
     * Wird vom Cron-Runner fuer datengetriebene Start-Trigger genutzt.
     *
     * @param array<string,mixed> $criteria z. B. ['status' => 'pending']
     *
     * @return list<array<string,mixed>>
     */
    public function find(string $entity, array $criteria): array;
}
