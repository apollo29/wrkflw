<?php

declare(strict_types=1);

namespace WorkflowEngine\Security;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use WorkflowEngine\Rest\RestOptions;
use WorkflowEngine\Rest\RestTransportException;

/**
 * HTTP-Client für REST-Datenquellen mit festen Grenzen (F-136, F-144):
 *
 * - jede Adresse (auch nach einer Weiterleitung) läuft durch den {@see UrlGuard},
 * - die Anfrage geht an die geprüfte IP (`resolve`), nicht an eine zweite DNS-Antwort,
 * - Weiterleitungen werden einzeln geprüft, höchstens `maxRedirects`,
 * - Zeitlimit für die ganze Anfrage, Abbruch bei zu grosser Antwort.
 *
 * Fehlermeldungen nennen Methode und Host, nie Kopfzeilen oder Inhalte.
 */
final readonly class SafeHttpClient
{
    private HttpClientInterface $client;

    public function __construct(
        ?HttpClientInterface $client = null,
        private UrlGuard $guard = new UrlGuard(),
        private RestOptions $options = new RestOptions(),
    ) {
        $this->client = $client ?? HttpClient::create();
    }

    /**
     * @param array{headers?: array<string, string>, json?: mixed, query?: array<string, string>} $request
     *
     * @throws BlockedUrlException
     * @throws RestTransportException
     */
    public function request(string $method, string $url, array $request = []): HttpResult
    {
        $start = hrtime(true);

        for ($redirects = 0; ; $redirects++) {
            $checked = $this->guard->check($url);
            try {
                $response = $this->client->request($method, $url, $request + [
                    'resolve' => [$checked->host => $checked->ip],
                    'max_redirects' => 0,
                    'timeout' => $this->options->timeoutSeconds,
                    'max_duration' => $this->options->timeoutSeconds,
                ]);
                $status = $response->getStatusCode();
                $headers = $response->getHeaders(false);

                if ($status >= 300 && $status < 400 && isset($headers['location'][0])) {
                    $response->cancel();
                    if ($redirects >= $this->options->maxRedirects) {
                        throw new RestTransportException("Zu viele Weiterleitungen bei {$method} {$checked->host}.");
                    }
                    $url = self::resolveLocation($url, $headers['location'][0]);
                    if ($status === 303 || (in_array($status, [301, 302], true) && $method === 'POST')) {
                        $method = 'GET';
                        unset($request['json']);
                    }
                    continue;
                }

                $length = (int) ($headers['content-length'][0] ?? 0);
                if ($length > $this->options->maxResponseBytes) {
                    $response->cancel();
                    throw $this->tooLarge($checked->host);
                }

                $body = '';
                foreach ($this->client->stream($response) as $chunk) {
                    $body .= $chunk->getContent();
                    if (strlen($body) > $this->options->maxResponseBytes) {
                        $response->cancel();
                        throw $this->tooLarge($checked->host);
                    }
                }

                return new HttpResult($status, $headers, $body, (int) ((hrtime(true) - $start) / 1_000_000));
            } catch (ExceptionInterface $e) {
                throw new RestTransportException("Anfrage {$method} an {$checked->host} fehlgeschlagen: " . self::safeMessage($e->getMessage()), 0, $e);
            }
        }
    }

    private function tooLarge(string $host): RestTransportException
    {
        return new RestTransportException("Antwort von {$host} ist zu gross (höchstens {$this->options->maxResponseBytes} Byte).");
    }

    private static function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = $parts['path'] ?? '/';

        return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
    }

    /** Entfernt eine allenfalls in der Meldung enthaltene URL samt Query. */
    private static function safeMessage(string $message): string
    {
        return (string) preg_replace('#https?://\S+#', '<url>', $message);
    }
}
