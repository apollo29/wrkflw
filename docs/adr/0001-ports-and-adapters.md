# 1. Ports & Adapters (Hexagonal)

Status: akzeptiert

## Kontext
Die Engine ist eine eingebettete Library und darf nicht von einer konkreten Host-App
abhängen. Persistenz, E-Mail, Datenzugriff und Ausdrucksauswertung unterscheiden sich
je Einsatz.

## Entscheidung
Die Engine definiert **Ports** (Interfaces in `src/Contracts`): `WorkflowRepositoryInterface`,
`ExpressionEvaluatorInterface`, `ActionInterface`, `MailerInterface`, `DataProviderInterface`,
`TriggerInterface`. Die Host-App liefert **Adapter**. Code in `src/Engine`, `src/Definition`,
`src/Instance` kennt keine konkreten App-Klassen.

## Konsequenzen
- Engine-Unit-Tests nutzen In-Memory-Fakes statt echter Infrastruktur.
- Austauschbarkeit (z. B. anderer Evaluator, anderer Mailer) ohne Engine-Änderung.
- Etwas mehr Boilerplate (Interfaces + Wiring), siehe `examples/bootstrap.php`.
