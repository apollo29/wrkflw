<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Slim\App;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use WorkflowEngine\Contracts\WorkflowRepositoryInterface;
use WorkflowEngine\Engine\WorkflowEngine;

/**
 * Baut die Slim-Anwendung der Workflow-Engine auf: Routen, Body-Parsing,
 * einheitliches JSON-Fehlerformat und optionale Auth-Middleware.
 *
 * Zwei Wege:
 *  - create():               direkte Injektion von Engine + Repository (z. B. für Tests)
 *  - createFromContainer():  Wiring über einen PSR-11-Container (z. B. php-di)
 */
final class ApiFactory
{
    /** @var list<array{0:string,1:string,2:string}> Methode, Pfad, Controller-Aktion */
    private const ROUTES = [
        ['POST', '/workflows/{def}/instances', 'start'],
        ['GET', '/instances/{id}', 'show'],
        ['GET', '/instances/{id}/current-step', 'currentStep'],
        ['POST', '/instances/{id}/events', 'postEvent'],
        ['GET', '/instances/{id}/history', 'history'],
    ];

    /**
     * @return App<\Psr\Container\ContainerInterface|null>
     */
    public static function create(
        WorkflowEngine $engine,
        WorkflowRepositoryInterface $repo,
        ?MiddlewareInterface $auth = null,
    ): App {
        $app = AppFactory::create();
        self::addRoutes($app, new WorkflowController($engine, $repo));
        self::finalize($app, $auth);

        return $app;
    }

    /**
     * Wiring über einen PSR-11-Container. Der Container muss WorkflowController
     * auflösen können (z. B. via Autowiring in examples/bootstrap.php).
     *
     * @return App<\Psr\Container\ContainerInterface|null>
     */
    public static function createFromContainer(
        ContainerInterface $container,
        ?MiddlewareInterface $auth = null,
    ): App {
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        self::addRoutes($app, WorkflowController::class);
        self::finalize($app, $auth);

        return $app;
    }

    /**
     * @param App<\Psr\Container\ContainerInterface|null> $app
     */
    private static function addRoutes(App $app, WorkflowController|string $controller): void
    {
        foreach (self::ROUTES as [$method, $pattern, $action]) {
            $handler = is_string($controller) ? "{$controller}:{$action}" : [$controller, $action];
            $app->map([$method], $pattern, $handler);
        }
    }

    /**
     * @param App<\Psr\Container\ContainerInterface|null> $app
     */
    private static function finalize(App $app, ?MiddlewareInterface $auth): void
    {
        $app->addBodyParsingMiddleware();

        if ($auth !== null) {
            $app->add($auth);
        }

        $app->addRoutingMiddleware();

        $responseFactory = $app->getResponseFactory();
        $errorMiddleware = $app->addErrorMiddleware(false, false, false);
        $errorMiddleware->setDefaultErrorHandler(
            static function (ServerRequestInterface $request, \Throwable $exception) use ($responseFactory): ResponseInterface {
                [$status, $code] = self::mapException($exception);

                $response = $responseFactory->createResponse($status);
                $response->getBody()->write(json_encode([
                    'error' => [
                        'code' => $code,
                        'message' => $status >= 500 ? 'Interner Serverfehler.' : $exception->getMessage(),
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return $response->withHeader('Content-Type', 'application/json');
            }
        );
    }

    /**
     * @return array{0:int,1:string}
     */
    private static function mapException(\Throwable $exception): array
    {
        return match (true) {
            $exception instanceof HttpNotFoundException => [404, 'not_found'],
            $exception instanceof HttpMethodNotAllowedException => [405, 'method_not_allowed'],
            $exception instanceof HttpException => [$exception->getCode(), 'http_error'],
            default => [500, 'internal_error'],
        };
    }
}
