<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * PORT: Welche Pruefungen kann die Host-App auf eine hochgeladene Datei
 * anwenden?
 *
 * Ein interaktiver Schritt kann ein Feld vom Typ `file` haben; dessen
 * `handler` sagt der Host-App, was mit der Datei geschehen soll. Die Engine
 * kennt die moeglichen Werte NICHT — sie nimmt selbst keine Dateien entgegen
 * und reicht den Namen nur durch.
 *
 * Ohne diesen Port muesste der Autor den Namen aus dem Kopf eintippen. Genau
 * das war der erste Entwurf, und die erste Frage dazu lautete: «was muss ich
 * da eintragen?» — eine Frage, die die Anwendung selbst beantworten kann.
 */
interface UploadHandlerCatalogInterface
{
    /**
     * @return list<array{key:string,label:string,description:string}>
     */
    public function handlers(): array;
}
