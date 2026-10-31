<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Model\Field;
use WorkflowEngine\Model\FieldType;
use WorkflowEngine\Model\InvalidFieldValueException;

final class FieldTest extends TestCase
{
    /**
     * @return iterable<string, array{FieldType, mixed, mixed}>
     */
    public static function validValues(): iterable
    {
        yield 'Text' => [FieldType::Text, 'offen', 'offen'];
        yield 'Text aus Zahl' => [FieldType::Text, 42, '42'];
        yield 'Text leer bleibt leer' => [FieldType::Text, '', ''];
        yield 'Zahl' => [FieldType::Number, 1250, 1250];
        yield 'Zahl aus Text' => [FieldType::Number, '1250', 1250];
        yield 'Dezimal aus Text' => [FieldType::Number, '12.5', 12.5];
        yield 'Zahl leer' => [FieldType::Number, '', null];
        yield 'Ja/Nein' => [FieldType::Bool, true, true];
        yield 'Ja/Nein aus 0' => [FieldType::Bool, '0', false];
        yield 'Ja/Nein aus ja' => [FieldType::Bool, 'ja', true];
        yield 'Datum ISO' => [FieldType::Date, '2026-10-31', '2026-10-31'];
        yield 'Datum aus Zeitpunkt' => [FieldType::Date, '2026-10-31T14:32:05+01:00', '2026-10-31'];
        yield 'Datum Schweiz' => [FieldType::Date, '31.10.2026', '2026-10-31'];
        yield 'Datum aus Objekt' => [FieldType::Date, new \DateTimeImmutable('2026-10-31 23:00'), '2026-10-31'];
        yield 'Zeitpunkt' => [FieldType::DateTime, '2026-10-31T14:32:05+01:00', '2026-10-31T14:32:05+01:00'];
        yield 'Zeitpunkt aus Datum' => [FieldType::DateTime, '2026-10-31', '2026-10-31T00:00:00+01:00'];
        yield 'leer ist null' => [FieldType::DateTime, null, null];
    }

    #[DataProvider('validValues')]
    public function testNormalizesValidValues(FieldType $type, mixed $input, mixed $expected): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Zurich');
        try {
            self::assertSame($expected, (new Field('f', 'Feld', $type))->normalize($input));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    /**
     * @return iterable<string, array{FieldType, mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'Zahl aus Wort' => [FieldType::Number, 'viel'];
        yield 'Zahl aus Ja' => [FieldType::Number, true];
        yield 'Ja/Nein aus vielleicht' => [FieldType::Bool, 'vielleicht'];
        yield 'Datum ungültig' => [FieldType::Date, '2026-02-30'];
        yield 'Datum relativ' => [FieldType::Date, 'morgen'];
        yield 'Zeitpunkt Wort' => [FieldType::DateTime, 'tomorrow'];
        yield 'Text aus Liste' => [FieldType::Text, ['a']];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValuesNamingTheField(FieldType $type, mixed $input): void
    {
        try {
            (new Field('geprueft_am', 'Geprüft am', $type))->normalize($input);
            self::fail('Keine Exception.');
        } catch (InvalidFieldValueException $e) {
            self::assertSame('geprueft_am', $e->field);
            self::assertStringContainsString('Geprüft am', $e->getMessage());
        }
    }

    public function testFactoriesAndStorageName(): void
    {
        $field = Field::text('mail', 'E-Mail', actual: 'kontakt_mail');

        self::assertFalse($field->writable, 'Felder sind schreibgeschützt, bis sie freigegeben werden');
        self::assertTrue($field->asWritable()->writable);
        self::assertSame('kontakt_mail', $field->storageName());
        self::assertSame('betrag', Field::number('betrag', 'Betrag')->storageName());
        self::assertSame(FieldType::Date, Field::date('d', 'D')->type);
    }
}
