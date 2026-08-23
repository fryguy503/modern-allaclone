<?php

namespace Tests\Unit\Services\SpellHistory;

use App\Services\SpellHistory\SpellCanonicalizer;
use PHPUnit\Framework\TestCase;

class SpellCanonicalizerTest extends TestCase
{
    public function test_it_canonicalizes_aliases_sentinels_and_derived_fields(): void
    {
        $canonicalizer = new SpellCanonicalizer;
        $headers = [
            'id', 'name', 'warlevel', 'attrib1', 'base1', 'base2_1', 'calc1',
            'reagentid1', 'reagentcount1', 'castingtime', 'castmsg5',
            'unknown3', 'desc1', 'updated', 'classes',
        ];

        $row = $canonicalizer->canonicalizeRow(
            ['3467', ' Virtue ', '127', '254', '2405', '3', '100', '-1', '1', '2500', " Your virtue fades.\r\n", '7', 'Derived', '2025-01-01', 'CLR/1'],
            $canonicalizer->canonicalizeHeaders($headers),
            'test row',
        );

        $this->assertSame(3467, $row['id']);
        $this->assertSame(' Virtue ', $row['name']);
        $this->assertNull($row['classes.1']);
        $this->assertNull($row['effects.1.attribute']);
        $this->assertNull($row['effects.1.base']);
        $this->assertNull($row['effects.1.limit']);
        $this->assertNull($row['effects.1.formula']);
        $this->assertNull($row['reagents.1.item_id']);
        $this->assertNull($row['reagents.1.count']);
        $this->assertSame(2500, $row['cast_time']);
        $this->assertSame(" Your virtue fades.\n", $row['spell_fades']);
        $this->assertSame(7, $row['unknown3']);
        $this->assertArrayNotHasKey('desc1', $row);
        $this->assertArrayNotHasKey('updated', $row);
        $this->assertArrayNotHasKey('classes', $row);
    }

    public function test_it_groups_effect_and_class_changes_for_player_display(): void
    {
        $canonicalizer = new SpellCanonicalizer;
        $headers = ['id', 'name', 'warlevel', 'attrib1', 'base1', 'base2_1', 'calc1'];
        $map = $canonicalizer->canonicalizeHeaders($headers);
        $before = $canonicalizer->canonicalizeRow(
            ['3467', 'Virtue', '127', '254', '0', '0', '100'],
            $map,
            'before',
        );
        $after = $canonicalizer->canonicalizeRow(
            ['3467', 'Virtue', '5', '69', '2405', '3', '100'],
            $map,
            'after',
        );

        $groups = $canonicalizer->diffGroups($before, $after);

        $this->assertSame(['classes', 'effect.1'], array_column($groups, 'key'));
        $this->assertSame('Warrior', $groups[0]['changes'][0]['label']);
        $this->assertSame('Effect slot 1', $groups[1]['label']);
        $this->assertSame(
            ['Effect', 'Base value', 'Formula', 'Limit value'],
            array_column($groups[1]['changes'], 'label'),
        );
    }

    public function test_legacy_adapters_are_scoped_to_schemas_without_modern_columns(): void
    {
        $canonicalizer = new SpellCanonicalizer;

        $legacyMap = $canonicalizer->canonicalizeHeaders([
            'id', 'zkrlevel', 'spellgroup', 'unknown112', 'unknown217',
        ]);
        $this->assertSame(
            ['id', 'classes.16', 'spell_group', 'cancel_on_sit', 'unknown217'],
            $legacyMap,
        );

        $mixedMap = $canonicalizer->canonicalizeHeaders([
            'id', 'zkrlevel', 'berlevel', 'spellgroup', 'spell_group', 'unknown112', 'cancelonsit',
        ]);
        $this->assertSame(
            ['id', null, 'classes.16', null, 'spell_group', null, 'cancel_on_sit'],
            $mixedMap,
        );
        $this->assertSame(
            'alias_only_when_canonical_header_is_absent',
            $canonicalizer->adapterMetadata()['legacy_scope'],
        );
    }

    public function test_raw_values_are_retained_beside_typed_semantic_values(): void
    {
        $canonicalizer = new SpellCanonicalizer;
        $map = $canonicalizer->canonicalizeHeaders(['id', 'mana', 'reagentid1', 'focus1']);
        $payload = $canonicalizer->canonicalizeRowWithRaw(
            [' 3467 ', ' 010 ', '-1', '0'],
            $map,
            'raw row',
        );

        $this->assertSame(3467, $payload['values']['id']);
        $this->assertSame(10, $payload['values']['mana']);
        $this->assertNull($payload['values']['reagents.1.item_id']);
        $this->assertNull($payload['values']['focus1']);
        $this->assertSame(' 010 ', $payload['raw_values']['mana']);
        $this->assertSame('-1', $payload['raw_values']['reagents.1.item_id']);
    }

    public function test_unknown_future_headers_fail_safe_to_text_and_are_reported(): void
    {
        $canonicalizer = new SpellCanonicalizer;
        $specs = $canonicalizer->compileColumnSpecs(['id', 'future_field', 'expansion']);
        $payload = $canonicalizer->canonicalizeRowUsingSpecs(
            ['10', '001', 'original'],
            $specs,
            'future schema',
        );

        $this->assertSame(10, $payload['values']['id']);
        $this->assertSame('001', $payload['values']['future_field']);
        $this->assertSame('original', $payload['values']['expansion']);
        $this->assertSame(['future_field' => 'future_field'], $canonicalizer->unclassifiedHeaders());
    }

    public function test_decimal_numbers_are_canonical_strings_without_float_rounding(): void
    {
        $canonicalizer = new SpellCanonicalizer;
        $map = $canonicalizer->canonicalizeHeaders(['id', 'range', 'aerange', 'fizzletime']);
        $row = $canonicalizer->canonicalizeRow(
            ['0007', '+010.5000', '-0.2500', '2.000'],
            $map,
            'decimal row',
        );

        $this->assertSame(7, $row['id']);
        $this->assertSame('10.5', $row['range']);
        $this->assertSame('-0.25', $row['ae_range']);
        $this->assertSame(2, $row['fizzle_time']);
    }

    public function test_malformed_known_numeric_values_fail_the_build(): void
    {
        $canonicalizer = new SpellCanonicalizer;
        $map = $canonicalizer->canonicalizeHeaders(['id', 'mana']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Non-numeric value in numeric spell field mana at malformed row.');

        $canonicalizer->canonicalizeRow(['7', 'unknown'], $map, 'malformed row');
    }
}
