<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WorkflowEngine\Contracts\Template;
use WorkflowEngine\Contracts\TemplateType;
use WorkflowEngine\Contracts\TemplateUsage;
use WorkflowEngine\Exception\NotFoundException;

/** Vorlagen für E-Mails und Seiten (F-70 bis F-75). */
final readonly class TemplateController
{
    private const string ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/';

    public function __construct(private ApiServices $s)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $type = JsonIo::query($request, 'type');

        return JsonIo::json($response, [
            'templates' => array_map(
                static fn (Template $t): array => ['id' => $t->id, 'name' => $t->name, 'type' => $t->type->value],
                $this->s->templates->listTemplates($type === null ? null : self::type($type)),
            ),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = JsonIo::arg($args, 'id');
        $template = $this->s->templates->findTemplate($id) ?? throw new NotFoundException("Vorlage '{$id}' nicht gefunden.");

        return JsonIo::json($response, self::full($template));
    }

    /**
     * @param array<string, string> $args
     */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = JsonIo::arg($args, 'id');
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw RequestValidationException::field('id', 'Die ID besteht aus Buchstaben, Ziffern, _, . und - (höchstens 100 Zeichen).');
        }
        $body = JsonIo::body($request);
        $name = trim(JsonIo::string($body, 'name') ?? '');
        $template = new Template(
            $id,
            $name !== '' ? $name : $id,
            self::type(JsonIo::string($body, 'type') ?? TemplateType::Email->value),
            JsonIo::string($body, 'subject') ?? '',
            JsonIo::string($body, 'body') ?? '',
        );
        $this->s->templates->saveTemplate($template);

        return JsonIo::json($response, self::full($template), 201);
    }

    /**
     * Gesperrt, solange eine Definition die Vorlage verwendet.
     *
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = JsonIo::arg($args, 'id');
        if ($this->s->templates->findTemplate($id) === null) {
            throw new NotFoundException("Vorlage '{$id}' nicht gefunden.");
        }
        $usage = $this->s->definitions->templateUsage($id);
        if ($usage !== []) {
            $where = array_map(static fn (TemplateUsage $u): string => "{$u->definitionId} v{$u->version} ({$u->step})", $usage);
            throw new ConflictException('Die Vorlage wird noch verwendet in: ' . implode(', ', array_unique($where)) . '.', 'in_use');
        }
        $this->s->templates->deleteTemplate($id);

        return JsonIo::empty($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function usage(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = JsonIo::arg($args, 'id');

        return JsonIo::json($response, [
            'templateId' => $id,
            'usage' => array_map(
                static fn (TemplateUsage $u): array => ['definitionId' => $u->definitionId, 'version' => $u->version, 'step' => $u->step],
                $this->s->definitions->templateUsage($id),
            ),
        ]);
    }

    private static function type(string $raw): TemplateType
    {
        return TemplateType::tryFrom($raw) ?? throw RequestValidationException::field('type', "Unbekannte Art '{$raw}'; erlaubt sind email und page.");
    }

    /**
     * @return array{id: string, name: string, type: string, subject: string, body: string}
     */
    private static function full(Template $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'type' => $t->type->value, 'subject' => $t->subject, 'body' => $t->body];
    }
}
