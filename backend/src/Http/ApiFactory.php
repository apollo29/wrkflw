<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

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
 * Dependencies werden hier injiziert (in einer echten App aus dem DI-Container).
 */
final class ApiFactory
{
    /**
     * @return App<\Psr\Container\ContainerInterface|null>
     */
    public static function create(
        WorkflowEngine $engine,
        WorkflowRepositoryInterface $repo,
        ?MiddlewareInterface $auth = null,
    ): App {
        $app = AppFactory::create();
        $controller = new WorkflowController($engine, $repo);

        $app->post('/workflows/{def}/instances', [$controller, 'start']);
        $app->get('/instances/{id}', [$controller, 'show']);
        $app->get('/instances/{id}/current-step', [$controller, 'currentStep']);
        $app->post('/instances/{id}/events', [$controller, 'postEvent']);
        $app->get('/instances/{id}/history', [$controller, 'history']);

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

        return $app;
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
