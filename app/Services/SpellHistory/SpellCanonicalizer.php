<?php

namespace App\Services\SpellHistory;

use RuntimeException;

final class SpellCanonicalizer
{
    public const FORMAT_VERSION = 3;

    /** @var array<string, string> */
    private const DIRECT_ALIASES = [
        'aeduration' => 'ae_duration',
        'aerange' => 'ae_range',
        'bookicon' => 'book_icon',
        'castingtime' => 'cast_time',
        'cancelonsit' => 'cancel_on_sit',
        'castmsg1' => 'you_cast',
        'castmsg2' => 'other_casts',
        'castmsg3' => 'cast_on_you',
        'castmsg4' => 'cast_on_other',
        'castmsg5' => 'spell_fades',
        'dotstackingexempt' => 'dot_stacking_exempt',
        'enduranceupkeep' => 'endurance_upkeep',
        'fizzleadj' => 'fizzle_adjust',
        'fizzletime' => 'fizzle_time',
        'lighttype' => 'light_type',
        'manacost' => 'mana',
        'recasttime' => 'recast_time',
        'recoverytime' => 'recovery_time',
        'resistadj' => 'resist_adjust',
        'spellanim' => 'spell_animation',
        'spellicon' => 'spell_icon',
        'spelltype' => 'spell_type',
        'targetanim' => 'target_animation',
        'targettype' => 'target_type',
        'timeofday' => 'time_of_day',
        'traveltype' => 'travel_type',
        'uninterruptable' => 'uninterruptible',
        'secondary_category' => 'secondary_category_1',
        'secondary_category2' => 'secondary_category_2',
    ];

    /** @var array<string, int> */
    private const CLASS_FIELDS = [
        'warlevel' => 1,
        'clrlevel' => 2,
        'pallevel' => 3,
        'rnglevel' => 4,
        'shdlevel' => 5,
        'drulevel' => 6,
        'mnklevel' => 7,
        'brdlevel' => 8,
        'roglevel' => 9,
        'shmlevel' => 10,
        'neclevel' => 11,
        'wizlevel' => 12,
        'maglevel' => 13,
        'enclevel' => 14,
        'bstlevel' => 15,
        'berlevel' => 16,
    ];

    /** @var array<int, string> */
    private const CLASS_NAMES = [
        1 => 'Warrior',
        2 => 'Cleric',
        3 => 'Paladin',
        4 => 'Ranger',
        5 => 'Shadow Knight',
        6 => 'Druid',
        7 => 'Monk',
        8 => 'Bard',
        9 => 'Rogue',
        10 => 'Shaman',
        11 => 'Necromancer',
        12 => 'Wizard',
        13 => 'Magician',
        14 => 'Enchanter',
        15 => 'Beastlord',
        16 => 'Berserker',
    ];

    /** @var array<string, true> */
    private const IGNORED_FIELDS = [
        'classes' => true,
        'durationtext' => true,
        'foci' => true,
        'focusitems' => true,
        'autocasttext' => true,
        'category' => true,
        'reagents' => true,
        'source' => true,
        'updated' => true,
    ];

    /**
     * Lucy fields whose values are semantic text, including fields which become
     * numeric-looking in later exporters and therefore must never be coerced.
     * All other retained spelldata columns follow Lucy's numeric field contract.
     *
     * @var array<string, true>
     */
    private const TEXT_FIELDS = [
        'cast_on_other' => true,
        'cast_on_you' => true,
        'deities' => true,
        'expansion' => true,
        'extra' => true,
        'location' => true,
        'name' => true,
        'other_casts' => true,
        'resist' => true,
        'skill' => true,
        'spell_fades' => true,
        'spell_type' => true,
        'targname' => true,
        'target_type' => true,
        'teleport_zone' => true,
        'time_of_day' => true,
        'you_cast' => true,
    ];

    /**
     * Audited Lucy raw scalar headers whose nonblank values are numeric.
     * Structural slot/unknown/class families are handled by patterns.
     *
     * @var array<string, true>
     */
    private const RAW_NUMERIC_FIELDS = [
        'activated' => true,
        'aeduration' => true,
        'aerange' => true,
        'affect_inanimate_object' => true,
        'ai_pt_bonus' => true,
        'ai_valid_targets' => true,
        'allow_spellscribe' => true,
        'anim_variation' => true,
        'attack_open' => true,
        'autocast' => true,
        'base_effects_focus_offset' => true,
        'base_effects_focus_slope' => true,
        'bonushate' => true,
        'bookicon' => true,
        'bypass_regen_check' => true,
        'can_cast_in_combat' => true,
        'can_mgb' => true,
        'cancelonsit' => true,
        'cast_not_standing' => true,
        'castinganim' => true,
        'castingtime' => true,
        'castrestriction' => true,
        'cone_end_angle' => true,
        'cone_start_angle' => true,
        'defense_open' => true,
        'deletable' => true,
        'descnum' => true,
        'distance_mod_close_dist' => true,
        'distance_mod_close_mult' => true,
        'distance_mod_far_dist' => true,
        'distance_mod_far_mult' => true,
        'dotstackingexempt' => true,
        'duration' => true,
        'duration_particle_effect' => true,
        'durationformula' => true,
        'durationfreeze' => true,
        'endurance_cost' => true,
        'enduranceupkeep' => true,
        'environment' => true,
        'error_open' => true,
        'feedbackable' => true,
        'fizzleadj' => true,
        'fizzletime' => true,
        'focus1' => true,
        'focus2' => true,
        'focus3' => true,
        'focus4' => true,
        'gemicon' => true,
        'hateamount' => true,
        'id' => true,
        'is_beta_only' => true,
        'is_skill' => true,
        'lighttype' => true,
        'manacost' => true,
        'max_hits_type' => true,
        'max_resist' => true,
        'maxduration' => true,
        'maxtargets' => true,
        'min_range' => true,
        'min_resist' => true,
        'minduration' => true,
        'minlevel' => true,
        'no_buff_block' => true,
        'no_detrimental_spell_aggro' => true,
        'no_heal_damage_item_mod' => true,
        'no_npc_los' => true,
        'no_overwrite' => true,
        'no_partial_save' => true,
        'no_remove' => true,
        'no_resist' => true,
        'nodispell' => true,
        'not_focusable' => true,
        'not_shown_to_player' => true,
        'npc_category' => true,
        'npc_no_cast' => true,
        'npc_usefulness' => true,
        'numhits' => true,
        'only_during_fast_regen' => true,
        'outofcombat' => true,
        'override_crit_chance' => true,
        'pcnpc_only_flag' => true,
        'persistdeath' => true,
        'primary_category' => true,
        'pushback' => true,
        'pushup' => true,
        'pvp_duration' => true,
        'pvp_duration_cap' => true,
        'pvpresistbase' => true,
        'pvpresistcalc' => true,
        'pvpresistcap' => true,
        'range' => true,
        'recasttime' => true,
        'reflectable' => true,
        'resist_cap' => true,
        'resist_per_level' => true,
        'resistadj' => true,
        'secondary_category' => true,
        'secondary_category2' => true,
        'shortbuff' => true,
        'show_dot_message' => true,
        'show_wear_off_message' => true,
        'skill_open' => true,
        'small_targets_only' => true,
        'sneak_attack' => true,
        'songcap' => true,
        'spaindex' => true,
        'spell_class' => true,
        'spell_group_rank' => true,
        'spell_recourse_type' => true,
        'spell_subclass' => true,
        'spell_subgroup' => true,
        'spellanim' => true,
        'spellgroup' => true,
        'spellicon' => true,
        'stacks_with_self' => true,
        'targetanim' => true,
        'targetrestriction' => true,
        'timer' => true,
        'traveltype' => true,
        'uninterruptable' => true,
        'uses_persistant_particles' => true,
        'vendor' => true,
        'viral_range' => true,
        'viral_targets' => true,
        'viral_timer' => true,
    ];

    /** @var array<string, string> */
    private array $unclassifiedHeaders = [];

    /** @var array<string, string> */
    private const SCHEMA_SCOPED_ALIASES = [
        'spellgroup' => 'spell_group',
        'uses_persistant_particles' => 'uses_persistent_particles',
        'zkrlevel' => 'classes.16',
        'unknown112' => 'cancel_on_sit',
        'unknown130' => 'npc_no_cast',
        'unknown131' => 'ai_pt_bonus',
        'unknown139' => 'no_partial_save',
        'unknown140' => 'small_targets_only',
        'unknown141' => 'uses_persistent_particles',
        'unknown144' => 'primary_category',
        'unknown145' => 'secondary_category_1',
        'unknown146' => 'secondary_category_2',
        'unknown147' => 'no_npc_los',
        'unknown148' => 'feedbackable',
        'unknown149' => 'reflectable',
        'unknown151' => 'resist_per_level',
        'unknown152' => 'resist_cap',
        'unknown153' => 'affect_inanimate_object',
        'unknown154' => 'endurance_cost',
        'unknown156' => 'is_skill',
        'unknown176' => 'max_hits_type',
        'unknown182' => 'pvp_duration',
        'unknown183' => 'pvp_duration_cap',
        'unknown184' => 'pcnpc_only_flag',
        'unknown185' => 'cast_not_standing',
        'unknown190' => 'min_resist',
        'unknown191' => 'max_resist',
        'unknown194' => 'duration_particle_effect',
        'unknown195' => 'cone_start_angle',
        'unknown196' => 'cone_end_angle',
        'unknown197' => 'sneak_attack',
        'unknown198' => 'not_focusable',
        'unknown199' => 'no_detrimental_spell_aggro',
        'unknown200' => 'show_wear_off_message',
        'unknown204' => 'stacks_with_self',
        'unknown205' => 'not_shown_to_player',
        'unknown206' => 'no_buff_block',
        'unknown207' => 'anim_variation',
        'unknown209' => 'spell_group_rank',
        'unknown210' => 'no_resist',
        'unknown211' => 'allow_spellscribe',
        'unknown213' => 'bypass_regen_check',
        'unknown214' => 'can_cast_in_combat',
        'unknown216' => 'show_dot_message',
        'unknown218' => 'override_crit_chance',
        'unknown220' => 'no_heal_damage_item_mod',
        'unknown222' => 'spell_class',
        'unknown223' => 'spell_subclass',
        'unknown224' => 'ai_valid_targets',
        'unknown226' => 'base_effects_focus_slope',
        'unknown227' => 'base_effects_focus_offset',
        'unknown228' => 'distance_mod_close_dist',
        'unknown229' => 'distance_mod_close_mult',
        'unknown230' => 'distance_mod_far_dist',
        'unknown231' => 'distance_mod_far_mult',
        'unknown232' => 'min_range',
        'unknown233' => 'no_remove',
        'unknown234' => 'spell_recourse_type',
        'unknown235' => 'only_during_fast_regen',
        'unknown236' => 'is_beta_only',
        'unknown237' => 'spell_subgroup',
    ];

    /**
     * @param  list<string|null>  $headers
     * @return list<string|null>
     */
    public function canonicalizeHeaders(array $headers): array
    {
        $normalizedHeaders = array_map(
            fn (?string $header): ?string => $header === null ? null : $this->normalizeHeader($header),
            $headers,
        );
        $modernCanonicalFields = [];
        foreach ($normalizedHeaders as $field) {
            if ($field === null || isset(self::SCHEMA_SCOPED_ALIASES[$field])) {
                continue;
            }
            $canonical = $this->canonicalFieldFromNormalized($field);
            if ($canonical !== null) {
                $modernCanonicalFields[$canonical] = true;
            }
        }

        return array_map(function (?string $field) use ($modernCanonicalFields): ?string {
            if ($field === null) {
                return null;
            }
            $legacyTarget = self::SCHEMA_SCOPED_ALIASES[$field] ?? null;
            if ($legacyTarget !== null) {
                return isset($modernCanonicalFields[$legacyTarget]) ? null : $legacyTarget;
            }

            return $this->canonicalFieldFromNormalized($field);
        }, $normalizedHeaders);
    }

    public function canonicalField(string $header): ?string
    {
        $field = $this->normalizeHeader($header);

        return self::SCHEMA_SCOPED_ALIASES[$field] ?? $this->canonicalFieldFromNormalized($field);
    }

    public function resetCompilationState(): void
    {
        $this->unclassifiedHeaders = [];
    }

    /**
     * Compile all field classification and sentinel decisions once per schema,
     * outside the multi-billion-cell row loop.
     *
     * @param  list<string|null>  $headers
     * @return list<SpellColumnSpec>
     */
    public function compileColumnSpecs(array $headers): array
    {
        $columnMap = $this->canonicalizeHeaders($headers);
        $specs = [];
        foreach ($columnMap as $index => $field) {
            if ($field === null) {
                continue;
            }
            $header = $headers[$index] ?? null;
            $specs[] = $this->columnSpec(
                $field,
                $header === null ? null : $this->normalizeHeader($header),
                $index,
            );
        }
        usort($specs, static function (SpellColumnSpec $left, SpellColumnSpec $right): int {
            $fieldOrder = strnatcasecmp((string) $left->key, (string) $right->key);

            return $fieldOrder !== 0 ? $fieldOrder : $left->sourceIndex <=> $right->sourceIndex;
        });

        return $specs;
    }

    /** @return array<string, string> raw header => canonical key */
    public function unclassifiedHeaders(): array
    {
        ksort($this->unclassifiedHeaders);

        return $this->unclassifiedHeaders;
    }

    private function canonicalFieldFromNormalized(string $field): ?string
    {
        if ($field === '' || isset(self::IGNORED_FIELDS[$field]) || preg_match('/^desc\d+$/', $field) === 1) {
            return null;
        }

        if (isset(self::CLASS_FIELDS[$field])) {
            return 'classes.'.self::CLASS_FIELDS[$field];
        }

        if (preg_match('/^attrib(\d+)$/', $field, $matches) === 1) {
            return "effects.{$matches[1]}.attribute";
        }
        if (preg_match('/^base2_(\d+)$/', $field, $matches) === 1) {
            return "effects.{$matches[1]}.limit";
        }
        if (preg_match('/^base(\d+)$/', $field, $matches) === 1) {
            return "effects.{$matches[1]}.base";
        }
        if (preg_match('/^calc(\d+)$/', $field, $matches) === 1) {
            return "effects.{$matches[1]}.formula";
        }
        if (preg_match('/^max(\d+)$/', $field, $matches) === 1) {
            return "effects.{$matches[1]}.max";
        }
        if (preg_match('/^reagentid(\d+)$/', $field, $matches) === 1) {
            return "reagents.{$matches[1]}.item_id";
        }
        if (preg_match('/^reagentcount(\d+)$/', $field, $matches) === 1) {
            return "reagents.{$matches[1]}.count";
        }

        return self::DIRECT_ALIASES[$field] ?? $field;
    }

    /** @return array<string, int|string> */
    public function adapterMetadata(): array
    {
        return [
            'version' => self::FORMAT_VERSION,
            'confidence' => 'high',
            'legacy_alias_count' => count(self::SCHEMA_SCOPED_ALIASES),
            'legacy_scope' => 'alias_only_when_canonical_header_is_absent',
            'unclassified_field_policy' => 'preserve_as_text_and_warn',
            'raw_change_policy' => 'canonical_equivalent_source_formatting_is_not_a_revision',
        ];
    }

    /**
     * @param  list<string|null>  $values
     * @param  list<string|null>  $columnMap
     * @return array<string, int|float|string|null>
     */
    public function canonicalizeRow(array $values, array $columnMap, string $sourceDescription): array
    {
        return $this->canonicalizeRowWithRaw($values, $columnMap, $sourceDescription)['values'];
    }

    /**
     * Preserve source spell values for audit while exposing typed values for
     * semantic comparisons. A missing column is different from a present null.
     *
     * @param  list<string|null>  $values
     * @param  list<string|null>  $columnMap
     * @return array{values: array<string, int|float|string|null>, raw_values: array<string, string|null>}
     */
    public function canonicalizeRowWithRaw(array $values, array $columnMap, string $sourceDescription): array
    {
        $specs = [];
        foreach ($columnMap as $index => $field) {
            if ($field !== null) {
                $specs[] = $this->columnSpec($field, null, $index);
            }
        }
        usort($specs, static function (SpellColumnSpec $left, SpellColumnSpec $right): int {
            $fieldOrder = strnatcasecmp((string) $left->key, (string) $right->key);

            return $fieldOrder !== 0 ? $fieldOrder : $left->sourceIndex <=> $right->sourceIndex;
        });

        return $this->canonicalizeRowUsingSpecs($values, $specs, $sourceDescription);
    }

    /**
     * @param  list<string|null>  $values
     * @param  list<SpellColumnSpec>  $specs
     * @return array{values: array<string, int|float|string|null>, raw_values: array<string, string|null>}
     */
    public function canonicalizeRowUsingSpecs(array $values, array $specs, string $sourceDescription): array
    {
        $row = [];
        $rawRow = [];
        $nullCompanions = [];

        foreach ($specs as $spec) {
            $field = $spec->key;
            if ($field === null) {
                continue;
            }

            $rawValue = $this->normalizeRawValue($values[$spec->sourceIndex] ?? null);
            $value = $this->normalizeValue($spec, $rawValue, $sourceDescription);
            if ($value === null && $spec->nullCompanions !== []) {
                array_push($nullCompanions, ...$spec->nullCompanions);
            }
            if (array_key_exists($field, $row) && $row[$field] !== $value) {
                if ($row[$field] === null) {
                    $row[$field] = $value;
                    $rawRow[$field] = $rawValue;

                    continue;
                }
                if ($value === null) {
                    continue;
                }

                throw new RuntimeException("Conflicting columns map to {$field} in {$sourceDescription}.");
            }

            $row[$field] = $value;
            $rawRow[$field] = $rawValue;
        }

        if (! array_key_exists('id', $row) || ! is_int($row['id']) || $row['id'] < 0) {
            throw new RuntimeException("Spell rows require a non-negative numeric id in {$sourceDescription}.");
        }

        foreach ($nullCompanions as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = null;
            }
        }

        return ['values' => $row, 'raw_values' => $rawRow];
    }

    /**
     * @param  array<string, int|float|string|null>  $before
     * @param  array<string, int|float|string|null>  $after
     * @return list<array{key: string, section: string, label: string, changes: list<array{field: string, label: string, old: mixed, new: mixed}>}>
     */
    public function diffGroups(array $before, array $after, array $beforeRaw = [], array $afterRaw = []): array
    {
        $groups = [];
        $sharedFields = array_intersect_key($before, $after);
        $fields = array_keys($sharedFields);
        sort($fields, SORT_NATURAL);

        foreach ($fields as $field) {
            if ($field === 'id' || $before[$field] === $after[$field]) {
                continue;
            }

            $meta = $this->groupForField($field);
            if (! isset($groups[$meta['key']])) {
                $groups[$meta['key']] = [
                    'key' => $meta['key'],
                    'section' => $meta['section'],
                    'label' => $meta['label'],
                    'order' => $meta['order'],
                    'changes' => [],
                ];
            }

            $change = [
                'field' => $field,
                'label' => $this->fieldLabel($field),
                'old' => $before[$field],
                'new' => $after[$field],
            ];
            if (array_key_exists($field, $beforeRaw)) {
                $change['old_raw'] = $beforeRaw[$field];
            }
            if (array_key_exists($field, $afterRaw)) {
                $change['new_raw'] = $afterRaw[$field];
            }
            $groups[$meta['key']]['changes'][] = $change;
        }

        uasort($groups, static fn (array $left, array $right): int => [$left['order'], $left['key']] <=> [$right['order'], $right['key']]);

        return array_values(array_map(static function (array $group): array {
            unset($group['order']);

            return $group;
        }, $groups));
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', trim($header)) ?? '';
        $header = strtolower($header);

        return trim(preg_replace('/[^a-z0-9]+/', '_', $header) ?? '', '_');
    }

    private function normalizeRawValue(?string $rawValue): ?string
    {
        if ($rawValue === null) {
            return null;
        }

        return str_contains($rawValue, "\r")
            ? str_replace(["\r\n", "\r"], "\n", $rawValue)
            : $rawValue;
    }

    private function columnSpec(?string $field, ?string $rawHeader = null, int $sourceIndex = 0): SpellColumnSpec
    {
        if ($field === null) {
            return new SpellColumnSpec(key: null, kind: SpellColumnSpec::KIND_IGNORED, sourceIndex: $sourceIndex);
        }
        if (isset(self::TEXT_FIELDS[$field])) {
            return new SpellColumnSpec(key: $field, kind: SpellColumnSpec::KIND_TEXT, sourceIndex: $sourceIndex);
        }
        if (preg_match('/^classes\.\d+$/D', $field) === 1) {
            return new SpellColumnSpec(
                key: $field,
                kind: SpellColumnSpec::KIND_NUMBER,
                sentinel: SpellColumnSpec::SENTINEL_CLASS_LEVEL,
                sourceIndex: $sourceIndex,
            );
        }
        if (preg_match('/^effects\.(\d+)\.attribute$/D', $field, $matches) === 1) {
            $slot = (int) $matches[1];

            return new SpellColumnSpec(
                key: $field,
                kind: SpellColumnSpec::KIND_NUMBER,
                sentinel: SpellColumnSpec::SENTINEL_EFFECT_ATTRIBUTE,
                slot: $slot,
                sourceIndex: $sourceIndex,
                nullCompanions: [
                    "effects.{$slot}.base",
                    "effects.{$slot}.formula",
                    "effects.{$slot}.limit",
                    "effects.{$slot}.max",
                ],
            );
        }
        if (preg_match('/^reagents\.(\d+)\.item_id$/D', $field, $matches) === 1) {
            $slot = (int) $matches[1];

            return new SpellColumnSpec(
                key: $field,
                kind: SpellColumnSpec::KIND_NUMBER,
                sentinel: SpellColumnSpec::SENTINEL_REAGENT_ITEM,
                slot: $slot,
                sourceIndex: $sourceIndex,
                nullCompanions: ["reagents.{$slot}.count"],
            );
        }
        if ($field === 'autocast' || preg_match('/^focus[1-4]$/D', $field) === 1) {
            return new SpellColumnSpec(
                key: $field,
                kind: SpellColumnSpec::KIND_NUMBER,
                sentinel: SpellColumnSpec::SENTINEL_SPELL_REFERENCE,
                sourceIndex: $sourceIndex,
            );
        }

        if ($rawHeader !== null && ! $this->rawHeaderIsNumeric($rawHeader)) {
            $this->unclassifiedHeaders[$rawHeader] = $field;

            return new SpellColumnSpec(key: $field, kind: SpellColumnSpec::KIND_TEXT, sourceIndex: $sourceIndex);
        }

        return new SpellColumnSpec(key: $field, kind: SpellColumnSpec::KIND_NUMBER, sourceIndex: $sourceIndex);
    }

    private function rawHeaderIsNumeric(string $header): bool
    {
        return isset(self::RAW_NUMERIC_FIELDS[$header])
            || isset(self::CLASS_FIELDS[$header])
            || $header === 'zkrlevel'
            || preg_match('/^(?:attrib|base|calc|max)\d+$/D', $header) === 1
            || preg_match('/^base2_\d+$/D', $header) === 1
            || preg_match('/^reagent(?:id|count)\d+$/D', $header) === 1
            || preg_match('/^unknown\d+$/D', $header) === 1;
    }

    private function normalizeValue(
        SpellColumnSpec $spec,
        ?string $value,
        string $sourceDescription,
    ): int|string|null {
        if ($value === null) {
            return null;
        }

        if ($value === '') {
            return null;
        }

        if ($spec->kind === SpellColumnSpec::KIND_TEXT) {
            return $value;
        }

        $normalized = $this->normalizeNumber($value, $spec->key, $sourceDescription);

        return match ($spec->sentinel) {
            SpellColumnSpec::SENTINEL_CLASS_LEVEL => in_array($normalized, [127, 255], true) ? null : $normalized,
            SpellColumnSpec::SENTINEL_EFFECT_ATTRIBUTE => $normalized === 254 ? null : $normalized,
            SpellColumnSpec::SENTINEL_REAGENT_ITEM => $normalized === -1 ? null : $normalized,
            SpellColumnSpec::SENTINEL_SPELL_REFERENCE => in_array($normalized, [-1, 0], true) ? null : $normalized,
            default => $normalized,
        };
    }

    private function normalizeNumber(string $value, ?string $field, string $sourceDescription): int|string
    {
        $candidate = trim($value);
        $unsigned = $candidate;
        if ($unsigned !== '' && ($unsigned[0] === '+' || $unsigned[0] === '-')) {
            $unsigned = substr($unsigned, 1);
        }

        if ($unsigned !== '' && ctype_digit($unsigned)) {
            $digits = ltrim($unsigned, '0');
            $digits = $digits === '' ? '0' : $digits;
            $negative = str_starts_with($candidate, '-') && $digits !== '0';
            $normalized = ($negative ? '-' : '').$digits;
            $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
            if (strlen($digits) < strlen($limit)
                || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) <= 0)) {
                return (int) $normalized;
            }

            return $normalized;
        }

        if (preg_match('/^([+-]?)(\d*)\.(\d*)$/D', $candidate, $matches) === 1
            && ($matches[2] !== '' || $matches[3] !== '')) {
            $whole = ltrim($matches[2], '0');
            $whole = $whole === '' ? '0' : $whole;
            $fraction = rtrim($matches[3], '0');
            if ($fraction === '') {
                return $this->normalizeNumber(
                    ($matches[1] === '-' ? '-' : '').$whole,
                    $field,
                    $sourceDescription,
                );
            }

            $negative = $matches[1] === '-' && ($whole !== '0' || $fraction !== '');

            // Decimal strings avoid binary-float rounding and make hashes stable
            // across PHP versions and CPU platforms.
            return ($negative ? '-' : '').$whole.'.'.$fraction;
        }

        $fieldLabel = $field ?? 'unknown';
        throw new RuntimeException(
            "Non-numeric value in numeric spell field {$fieldLabel} at {$sourceDescription}.",
        );
    }

    /** @return array{key: string, section: string, label: string, order: int} */
    private function groupForField(string $field): array
    {
        if (preg_match('/^effects\.(\d+)\./', $field, $matches) === 1) {
            return ['key' => "effect.{$matches[1]}", 'section' => 'effects', 'label' => "Effect slot {$matches[1]}", 'order' => 60];
        }
        if (preg_match('/^reagents\.(\d+)\./', $field, $matches) === 1) {
            return ['key' => "reagent.{$matches[1]}", 'section' => 'reagents', 'label' => "Reagent slot {$matches[1]}", 'order' => 70];
        }
        if (str_starts_with($field, 'classes.')) {
            return ['key' => 'classes', 'section' => 'classes', 'label' => 'Class levels', 'order' => 20];
        }
        if (in_array($field, ['name', 'book_icon', 'spell_icon', 'spell_animation'], true)) {
            return ['key' => 'identity', 'section' => 'identity', 'label' => 'Identity', 'order' => 10];
        }
        if (in_array($field, ['you_cast', 'other_casts', 'cast_on_you', 'cast_on_other', 'spell_fades'], true)) {
            return ['key' => 'messages', 'section' => 'messages', 'label' => 'Messages', 'order' => 50];
        }
        if (preg_match('/(?:cast|recast|recovery|fizzle|duration|timer|mana|endurance)/', $field) === 1) {
            return ['key' => 'casting', 'section' => 'casting', 'label' => 'Casting and duration', 'order' => 30];
        }
        if (preg_match('/(?:target|range|resist|location|environment|pcnpc)/', $field) === 1) {
            return ['key' => 'targeting', 'section' => 'targeting', 'label' => 'Targeting and resist', 'order' => 40];
        }
        if (preg_match('/(?:stack|overwrite|block)/', $field) === 1) {
            return ['key' => 'stacking', 'section' => 'stacking', 'label' => 'Stacking', 'order' => 80];
        }
        if (preg_match('/^(?:allow_|no_|not_|only_|npc_|is_|can_)/', $field) === 1) {
            return ['key' => 'restrictions', 'section' => 'restrictions', 'label' => 'Restrictions', 'order' => 90];
        }

        return ['key' => 'advanced', 'section' => 'advanced', 'label' => 'Advanced', 'order' => 100];
    }

    private function fieldLabel(string $field): string
    {
        if (preg_match('/^classes\.(\d+)$/', $field, $matches) === 1) {
            return self::CLASS_NAMES[(int) $matches[1]] ?? "Class {$matches[1]}";
        }
        if (preg_match('/^effects\.\d+\.(attribute|base|limit|formula|max)$/', $field, $matches) === 1) {
            return match ($matches[1]) {
                'attribute' => 'Effect',
                'base' => 'Base value',
                'limit' => 'Limit value',
                'formula' => 'Formula',
                'max' => 'Maximum value',
            };
        }
        if (preg_match('/^reagents\.\d+\.(item_id|count)$/', $field, $matches) === 1) {
            return $matches[1] === 'item_id' ? 'Item ID' : 'Quantity';
        }

        $labels = [
            'cast_on_other' => 'When cast on others',
            'cast_on_you' => 'When cast on you',
            'other_casts' => 'When others cast',
            'spell_fades' => 'When fading',
            'you_cast' => 'When you cast',
        ];
        if (isset($labels[$field])) {
            return $labels[$field];
        }

        if (preg_match('/^unknown_?(\d+)$/', $field, $matches) === 1) {
            return "Unknown {$matches[1]}";
        }

        return ucwords(str_replace('_', ' ', $field));
    }
}
