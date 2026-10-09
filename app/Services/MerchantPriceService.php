<?php

namespace App\Services;

use App\Models\Item;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MerchantPriceService
{
    private const DEFAULT_RULES = [
        'Merchant:UsePriceMod' => 'true',
        'Merchant:UseClassicPriceMod' => 'false',
        'Merchant:SellCostMod' => '1.05',
        'Merchant:PriceBonusPct' => '4',
    ];

    private ?array $ruleSets = null;

    private array $ruleValues = [];

    private array $zoneRuleSets = [];

    private bool $startupRuleSetLoaded = false;

    private ?int $startupRuleSet = null;

    /**
     * Estimate a coin purchase at the best charisma/faction rate. The Library
     * has no character context, so this is not a personalized merchant quote.
     */
    public function estimate(Item $item, ?string $zone = null, int $version = 0): int
    {
        $basePrice = (int) $item->price;
        $sellRate = (float) $item->sellrate;

        if ($basePrice <= 0 || $sellRate <= 0 || ! is_finite($sellRate)) {
            return 0;
        }

        $rules = $this->rulesFor($zone, $version);
        $classic = $this->enabled($rules['Merchant:UseClassicPriceMod']);
        $price = $this->multiply($basePrice, $sellRate);

        // EQEmu skips SellCostMod entirely with classic pricing enabled.
        if (! $classic) {
            $price = $this->multiply($price, (float) $rules['Merchant:SellCostMod']);
        }

        if ($this->enabled($rules['Merchant:UsePriceMod'])) {
            if ($classic) {
                // CalcClassicPriceMod caps the sell-back factor before taking
                // its reciprocal for purchases. Preserve both float32 steps.
                $modifier = $this->float32(1 / $this->float32(1 / 1.05));
            } else {
                $bonus = (int) $rules['Merchant:PriceBonusPct'];
                $modifier = $this->float32(1 + $this->float32(-$bonus / 100));
            }

            $price = $this->multiply($price, $modifier);
        }

        return min($price, 2_147_483_647);
    }

    private function multiply(int $price, float $modifier): int
    {
        // Item SellRate, rule reals, and price arithmetic are float32 in the
        // server, with integer copper truncation after each multiplication.
        $value = $this->float32($this->float32($price) * $this->float32($modifier));

        if (is_nan($value) || $value <= 0) {
            return 0;
        }

        return $value >= PHP_INT_MAX ? PHP_INT_MAX : (int) $value;
    }

    private function float32(float $value): float
    {
        return unpack('gvalue', pack('g', $value))['value'];
    }

    private function enabled(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function rulesFor(?string $zone, int $version): array
    {
        $this->loadRules();

        $defaultId = array_search('default', $this->ruleSets, true);
        $selectedId = $zone ? $this->zoneRuleSet($zone, $version) : null;

        // A named ruleset with ID zero is valid. Only an absent ruleset falls
        // back to the server's startup RuleSet variable (or named default).
        if ($selectedId === null || ! array_key_exists($selectedId, $this->ruleSets)) {
            $selectedId = $this->startupRuleSet();
        }

        $rules = self::DEFAULT_RULES;
        foreach (array_unique([$defaultId === false ? null : $defaultId, $selectedId]) as $id) {
            if ($id !== null) {
                $rules = array_replace($rules, $this->ruleValues[$id] ?? []);
            }
        }

        return $rules;
    }

    private function loadRules(): void
    {
        if ($this->ruleSets !== null) {
            return;
        }

        $this->ruleSets = [];

        try {
            $db = DB::connection('eqemu');
            $this->ruleSets = $db->table('rule_sets')->pluck('name', 'ruleset_id')->all();

            foreach ($db->table('rule_values')
                ->whereIn('rule_name', array_keys(self::DEFAULT_RULES))
                ->get(['ruleset_id', 'rule_name', 'rule_value']) as $rule) {
                $this->ruleValues[(int) $rule->ruleset_id][$rule->rule_name] = (string) $rule->rule_value;
            }
        } catch (QueryException) {
            // Content-only databases may omit server rules. Use EQEmu's
            // compiled defaults; the displayed cost remains an estimate.
        }
    }

    private function zoneRuleSet(string $zone, int $version): ?int
    {
        $key = $zone.':'.$version;
        if (! array_key_exists($key, $this->zoneRuleSets)) {
            $this->zoneRuleSets[$key] = null;

            try {
                $id = DB::connection('eqemu')->table('zone')
                    ->where('short_name', $zone)
                    ->whereIn('version', [0, $version])
                    ->orderByRaw('CASE WHEN version = ? THEN 0 ELSE 1 END', [$version])
                    ->value('ruleset');

                $this->zoneRuleSets[$key] = $id === null ? null : (int) $id;
            } catch (QueryException) {
                // Older/content-only schemas can lack the ruleset column.
            }
        }

        return $this->zoneRuleSets[$key];
    }

    private function startupRuleSet(): ?int
    {
        if (! $this->startupRuleSetLoaded) {
            $this->startupRuleSetLoaded = true;
            $name = 'default';

            try {
                $name = DB::connection('eqemu')->table('variables')
                    ->where('varname', 'RuleSet')->value('value') ?? 'default';
            } catch (QueryException) {
                // The named default also covers content-only schemas.
            }

            $id = array_search($name, $this->ruleSets, true);
            if ($id === false) {
                $id = array_search('default', $this->ruleSets, true);
            }
            $this->startupRuleSet = $id === false ? null : (int) $id;
        }

        return $this->startupRuleSet;
    }
}
