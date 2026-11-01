<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteCollectorProxyInterface;
use WorkflowEngine\Contracts\AccessResolverInterface;

/**
 * Baut die Slim-Anwendung der Engine: Routen, Body-Parsing, Zugriff über den Port und
 * ein einheitliches Fehlerformat (ADR 11).
 *
 * Die Host-App muss einen {@see AccessResolverInterface} angeben; für Entwicklung und Demo
 * gibt es {@see OpenAccessResolver}, ausdrücklich.
 */
final class ApiFactory
{
    /** @var list<array{0: string, 1: string, 2: string}> Methode, Pfad, Aktion */
    public const array INSTANCE_ROUTES = [
        ['POST', '/workflows/{def}/instances', 'start'],
        ['GET', '/instances', 'list'],
        ['GET', '/instances/{id}', 'show'],
        ['GET', '/instances/{id}/current-step', 'currentStep'],
        ['POST', '/instances/{id}/events', 'postEvent'],
        ['POST', '/instances/{id}/back', 'back'],
        ['POST', '/instances/{id}/cancel', 'cancel'],
        ['GET', '/instances/{id}/history', 'history'],
    ];

    /** @var list<array{0: string, 1: string, 2: string}> */
    public const array DEFINITION_ROUTES = [
        ['GET', '/workflows', 'list'],
        ['POST', '/workflows/validate', 'validate'],
        ['GET', '/workflows/{def}', 'get'],
        ['POST', '/workflows/{def}', 'save'],
        ['GET', '/workflows/{def}/versions', 'versions'],
        ['GET', '/workflows/{def}/versions/{version}', 'getVersion'],
        ['DELETE', '/workflows/{def}/versions/{version}', 'deleteVersion'],
    ];

    /** @var list<array{0: string, 1: string, 2: string}> */
    public const array CATALOG_ROUTES = [
        ['GET', '/actions', 'actions'],
        ['GET', '/data-models', 'dataModels'],
        ['GET', '/upload-handlers', 'uploadHandlers'],
    ];

    /** @var list<array{0: string, 1: string, 2: string}> */
    public const array TEMPLATE_ROUTES = [
        ['GET', '/templates', 'list'],
        ['GET', '/templates/{id}', 'get'],
        ['POST', '/templates/{id}', 'save'],
        ['DELETE', '/templates/{id}', 'delete'],
        ['GET', '/templates/{id}/usage', 'usage'],
    ];

    /** @var list<array{0: string, 1: string, 2: string}> Pfade unter `/data-sources`, nur Admin */
    public const array DATA_SOURCE_ROUTES = [
        ['GET', '/connections', 'listConnections'],
        ['POST', '/connections', 'createConnection'],
        ['GET', '/connections/{id}', 'getConnection'],
        ['PUT', '/connections/{id}', 'updateConnection'],
        ['DELETE', '/connections/{id}', 'deleteConnection'],
        ['PUT', '/connections/{id}/secret', 'replaceSecret'],
        ['POST', '/connections/{id}/test', 'testConnection'],
        ['GET', '/connections/{id}/usage', 'connectionUsage'],
        ['GET', '/models', 'listModels'],
        ['POST', '/models', 'createModel'],
        ['POST', '/models/sample', 'sample'],
        ['GET', '/models/{name}', 'getModel'],
        ['PUT', '/models/{name}', 'updateModel'],
        ['DELETE', '/models/{name}', 'deleteModel'],
        ['GET', '/models/{name}/usage', 'modelUsage'],
        ['GET', '/triggers', 'listTriggers'],
        ['POST', '/triggers', 'createTrigger'],
        ['GET', '/triggers/{id}', 'getTrigger'],
        ['PUT', '/triggers/{id}', 'updateTrigger'],
        ['DELETE', '/triggers/{id}', 'deleteTrigger'],
        ['POST', '/triggers/{id}/check-now', 'checkTrigger'],
        ['GET', '/audit', 'audit'],
    ];

    public const string DATA_SOURCE_PREFIX = '/data-sources';

    /**
     * @return App<\Psr\Container\ContainerInterface|null>
     */
    public static function create(ApiServices $services, AccessResolverInterface $access): App
    {
        $app = AppFactory::create();
        self::map($app, self::INSTANCE_ROUTES, new InstanceController($services));
        self::map($app, self::DEFINITION_ROUTES, new DefinitionController($services));
        self::map($app, self::CATALOG_ROUTES, new CatalogController($services));
        self::map($app, self::TEMPLATE_ROUTES, new TemplateController($services));

        $responses = $app->getResponseFactory();
        if ($services->dataSources !== null) {
            $controller = new DataSourceController($services->dataSources);
            $app->group(self::DATA_SOURCE_PREFIX, static function (RouteCollectorProxyInterface $group) use ($controller): void {
                self::map($group, self::DATA_SOURCE_ROUTES, $controller);
            })->add(new RequireAdminMiddleware($responses));
        }

        $app->addBodyParsingMiddleware();
        $app->add(new AccessMiddleware($access, $responses));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false)->setDefaultErrorHandler(
            static fn (ServerRequestInterface $request, \Throwable $e): ResponseInterface => ErrorMapper::respond($e, $responses->createResponse()),
        );

        return $app;
    }

    /**
     * Alle Routen als «METHODE /pfad», z. B. für den Abgleich mit `docs/openapi.yaml`.
     *
     * @return list<string>
     */
    public static function routeList(bool $withDataSources = true): array
    {
        $routes = [];
        foreach ([self::INSTANCE_ROUTES, self::DEFINITION_ROUTES, self::CATALOG_ROUTES, self::TEMPLATE_ROUTES] as $table) {
            foreach ($table as [$method, $path]) {
                $routes[] = "{$method} {$path}";
            }
        }
        if ($withDataSources) {
            foreach (self::DATA_SOURCE_ROUTES as [$method, $path]) {
                $routes[] = $method . ' ' . self::DATA_SOURCE_PREFIX . $path;
            }
        }

        return $routes;
    }

    /**
     * @param RouteCollectorProxyInterface<\Psr\Container\ContainerInterface|null> $collector
     * @param list<array{0: string, 1: string, 2: string}>                         $routes
     */
    private static function map(RouteCollectorProxyInterface $collector, array $routes, object $controller): void
    {
        foreach ($routes as [$method, $pattern, $action]) {
            $handler = [$controller, $action];
            assert(is_callable($handler));
            $collector->map([$method], $pattern, $handler);
        }
    }
}
