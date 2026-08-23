export const STORAGE_KEY = 'modern-allaclone.tradeskill-plans.v1';
export const STORAGE_SCHEMA_VERSION = 1;
export const SHARE_SCHEMA_VERSION = 1;

const NORMALIZED_GRAPH = Symbol('normalizedTradeskillGraph');

const DEFAULT_LIMITS = Object.freeze({
    maxQuantity: 10_000,
    maxDepth: 20,
    maxNodes: 500,
    maxCalculatedQuantity: 1_000_000_000,
    maxChoices: 250,
    maxInventoryEntries: 500,
    maxCompletedSteps: 1_000,
    maxSavedPlans: 50,
    maxStorageBytes: 262_144,
    maxShareLength: 4_096,
});

function isRecord(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function own(object, key) {
    return Object.prototype.hasOwnProperty.call(object, key);
}

function integer(value, fallback = 0) {
    const parsed = typeof value === 'number' ? value : Number.parseInt(value, 10);
    return Number.isSafeInteger(parsed) ? parsed : fallback;
}

function boundedInteger(value, minimum, maximum, fallback = minimum) {
    const parsed = integer(value, fallback);
    return Math.min(maximum, Math.max(minimum, parsed));
}

function shortString(value, maximum = 200, fallback = '') {
    if (typeof value !== 'string' && typeof value !== 'number') return fallback;
    return String(value).trim().slice(0, maximum);
}

function itemKey(value) {
    return shortString(value, 100);
}

function limitValue(source, camelName, snakeName, fallback, minimum, maximum) {
    const value = source?.[camelName] ?? source?.[snakeName];
    return boundedInteger(value, minimum, maximum, fallback);
}

export function normalizeLimits(source = {}) {
    const raw = isRecord(source?.limits) ? source.limits : (isRecord(source) ? source : {});
    const maxCalculatedQuantity = boundedInteger(
        raw.maxCalculatedQuantity
        ?? raw.max_calculated_quantity
        ?? raw.maxTotalQuantity
        ?? raw.max_total_quantity,
        1,
        Number.MAX_SAFE_INTEGER,
        DEFAULT_LIMITS.maxCalculatedQuantity,
    );
    const maxInventoryPerItem = boundedInteger(
        raw.maxInventoryPerItem ?? raw.max_inventory_per_item,
        0,
        maxCalculatedQuantity,
        Math.min(1_000_000, maxCalculatedQuantity),
    );

    return {
        maxQuantity: limitValue(raw, 'maxQuantity', 'max_quantity', DEFAULT_LIMITS.maxQuantity, 1, 1_000_000),
        maxDepth: limitValue(raw, 'maxDepth', 'max_depth', DEFAULT_LIMITS.maxDepth, 1, 100),
        maxNodes: limitValue(raw, 'maxNodes', 'max_nodes', DEFAULT_LIMITS.maxNodes, 1, 10_000),
        maxCalculatedQuantity,
        maxTotalQuantity: maxCalculatedQuantity,
        maxInventoryPerItem,
        maxChoices: limitValue(raw, 'maxChoices', 'max_choices', DEFAULT_LIMITS.maxChoices, 0, 5_000),
        maxInventoryEntries: limitValue(
            raw,
            'maxInventoryEntries',
            'max_inventory_entries',
            DEFAULT_LIMITS.maxInventoryEntries,
            0,
            10_000,
        ),
        maxCompletedSteps: limitValue(
            raw,
            'maxCompletedSteps',
            'max_completed_steps',
            DEFAULT_LIMITS.maxCompletedSteps,
            0,
            10_000,
        ),
        maxSavedPlans: limitValue(raw, 'maxSavedPlans', 'max_saved_plans', DEFAULT_LIMITS.maxSavedPlans, 1, 500),
        maxStorageBytes: limitValue(
            raw,
            'maxStorageBytes',
            'max_storage_bytes',
            DEFAULT_LIMITS.maxStorageBytes,
            1_024,
            5_000_000,
        ),
        maxShareLength: limitValue(
            raw,
            'maxShareLength',
            'max_share_length',
            DEFAULT_LIMITS.maxShareLength,
            128,
            32_000,
        ),
    };
}

function normalizeUrl(value) {
    const url = shortString(value, 2_048);
    if (!url) return '';
    if (url.includes('\\')) return '';
    if (url.startsWith('/') && !url.startsWith('//')) return url;

    try {
        const parsed = new URL(url);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.toString() : '';
    } catch {
        return '';
    }
}

function normalizeSource(source) {
    if (!isRecord(source)) return null;

    const kind = shortString(source.kind ?? source.type ?? source.source, 40, 'unknown').toLowerCase();
    const label = shortString(source.label ?? source.name ?? source.title, 160, sourceLabel(kind));
    const details = [
        source.detail,
        source.details,
        source.zoneName ?? source.zone_name,
        source.npcName ?? source.npc_name,
    ]
        .filter((value) => value !== undefined && value !== null && String(value).trim() !== '')
        .map((value) => shortString(value, 160))
        .filter((value, index, values) => values.indexOf(value) === index)
        .join(' · ')
        .slice(0, 320);

    return {
        kind,
        label,
        details,
        url: normalizeUrl(source.url),
    };
}

function normalizeProduct(product) {
    if (!isRecord(product)) return null;
    const key = itemKey(product.itemKey ?? product.item_key ?? product.id ?? product.itemId ?? product.item_id);
    const count = boundedInteger(product.count ?? product.yield ?? product.successCount ?? product.successcount, 1, 1_000_000, 1);
    return key ? { itemKey: key, count } : null;
}

function normalizeComponent(component) {
    if (!isRecord(component)) return null;
    const key = itemKey(component.itemKey ?? component.item_key ?? component.id ?? component.itemId ?? component.item_id);
    if (!key) return null;

    const count = boundedInteger(component.count ?? component.componentCount ?? component.componentcount, 1, 1_000_000, 1);
    const returned = boundedInteger(
        component.returnedOnSuccess ?? component.returned_on_success ?? component.returned ?? 0,
        0,
        count,
        0,
    );

    return { itemKey: key, count, returnedOnSuccess: returned };
}

function normalizeContainer(container, recipeId, index) {
    if (!isRecord(container)) return null;
    const worldObject = Boolean(container.worldObject ?? container.world_object);
    const key = itemKey(
        container.itemKey
        ?? container.item_key
        ?? container.id
        ?? container.itemId
        ?? container.item_id
        ?? (worldObject ? `world:${recipeId}:${index}` : ''),
    );

    if (!key) return null;

    return {
        itemKey: key,
        name: shortString(container.name, 200, worldObject ? 'World tradeskill station' : 'Container'),
        count: boundedInteger(container.count, 1, 1_000_000, 1),
        consumed: Boolean(container.consumed),
        worldObject,
    };
}

function normalizeRecipe(recipe, fallbackId) {
    if (!isRecord(recipe)) return null;
    const id = integer(recipe.id ?? fallbackId);
    if (id <= 0) return null;

    return {
        id,
        name: shortString(recipe.name, 240, `Recipe ${id}`),
        tradeskillName: shortString(recipe.tradeskillName ?? recipe.tradeskill_name, 120),
        trivial: Math.max(0, integer(recipe.trivial)),
        nofail: Boolean(recipe.nofail),
        quest: Boolean(recipe.quest),
        replaceContainer: Boolean(recipe.replaceContainer ?? recipe.replace_container),
        url: normalizeUrl(recipe.url),
        products: (Array.isArray(recipe.products) ? recipe.products : []).map(normalizeProduct).filter(Boolean),
        components: (Array.isArray(recipe.components) ? recipe.components : []).map(normalizeComponent).filter(Boolean),
        containers: (Array.isArray(recipe.containers) ? recipe.containers : [])
            .map((container, index) => normalizeContainer(container, id, index))
            .filter(Boolean),
    };
}

function normalizeAlternative(alternative) {
    if (!isRecord(alternative)) return null;
    const recipeId = integer(alternative.recipeId ?? alternative.recipe_id ?? alternative.id);
    if (recipeId <= 0) return null;

    return {
        recipeId,
        yield: boundedInteger(alternative.yield ?? alternative.count ?? alternative.successCount, 1, 1_000_000, 1),
        name: shortString(alternative.name, 240),
        trivial: Math.max(0, integer(alternative.trivial)),
        tradeskillName: shortString(alternative.tradeskillName ?? alternative.tradeskill_name, 120),
        url: normalizeUrl(alternative.url),
        nofail: Boolean(alternative.nofail),
        quest: Boolean(alternative.quest),
        automatic: own(alternative, 'automatic') ? Boolean(alternative.automatic) : null,
    };
}

export function normalizeGraph(rawGraph) {
    if (rawGraph?.[NORMALIZED_GRAPH]) return rawGraph;

    const source = isRecord(rawGraph) ? rawGraph : {};
    const recipes = Object.create(null);
    for (const [key, value] of Object.entries(isRecord(source.recipes) ? source.recipes : {})) {
        const recipe = normalizeRecipe(value, key);
        if (recipe) recipes[String(recipe.id)] = recipe;
    }

    const items = Object.create(null);
    for (const [key, value] of Object.entries(isRecord(source.items) ? source.items : {})) {
        if (!isRecord(value)) continue;
        const normalizedKey = itemKey(value.itemKey ?? value.item_key ?? key);
        if (!normalizedKey) continue;
        items[normalizedKey] = {
            itemKey: normalizedKey,
            id: integer(value.id ?? value.itemId ?? value.item_id),
            name: shortString(value.name ?? value.itemName ?? value.item_name, 240, 'Unknown item'),
            icon: boundedInteger(value.icon, 0, 1_000_000, 0),
            url: normalizeUrl(value.url),
            sources: (Array.isArray(value.sources) ? value.sources : []).map(normalizeSource).filter(Boolean).slice(0, 25),
            undiscovered: value.discovered === false || Boolean(value.undiscovered),
        };
    }

    for (const recipe of Object.values(recipes)) {
        for (const container of recipe.containers) {
            if (!items[container.itemKey]) {
                items[container.itemKey] = {
                    itemKey: container.itemKey,
                    id: 0,
                    name: container.name,
                    icon: 0,
                    url: '',
                    sources: container.worldObject
                        ? [{ kind: 'world-object', label: 'World object', details: '', url: '' }]
                        : [],
                    undiscovered: false,
                    worldObject: container.worldObject,
                };
            } else if (container.worldObject) {
                items[container.itemKey].worldObject = true;
            }
        }
    }

    const alternatives = Object.create(null);
    for (const [key, value] of Object.entries(isRecord(source.alternatives) ? source.alternatives : {})) {
        const normalizedKey = itemKey(key);
        if (!normalizedKey) continue;
        alternatives[normalizedKey] = (Array.isArray(value) ? value : [])
            .map(normalizeAlternative)
            .filter((alternative) => alternative && recipes[String(alternative.recipeId)])
            .slice(0, 100);
    }

    const rootRecipeId = integer(source.rootRecipeId ?? source.root_recipe_id);
    const rootRecipe = recipes[String(rootRecipeId)] ?? null;
    const rootProducts = (Array.isArray(source.rootProducts) ? source.rootProducts : [])
        .map(normalizeProduct)
        .filter(Boolean);
    const normalizedRootProducts = rootProducts.length ? rootProducts : (rootRecipe?.products ?? []);

    const graph = {
        schemaVersion: integer(source.schemaVersion ?? source.schema_version, 1),
        rootRecipeId,
        rootName: shortString(source.rootName ?? source.root_name, 240, rootRecipe?.name ?? 'Tradeskill plan'),
        rootProducts: normalizedRootProducts,
        recipes,
        items,
        alternatives,
        warnings: (Array.isArray(source.warnings) ? source.warnings : [])
            .map((warning) => {
                if (typeof warning === 'string') {
                    return { code: 'server', message: shortString(warning, 500) };
                }
                if (!isRecord(warning)) return null;
                return {
                    code: shortString(warning.code, 60, 'server').toLowerCase(),
                    message: shortString(warning.message, 500),
                };
            })
            .filter((warning) => warning?.message)
            .slice(0, 50),
        limits: normalizeLimits(source),
    };

    Object.defineProperty(graph, NORMALIZED_GRAPH, { value: true });
    return graph;
}

function normalizeChoiceMap(value, graph, maximum) {
    if (!isRecord(value)) return {};
    const choices = Object.create(null);

    for (const [rawKey, rawRecipeId] of Object.entries(value).slice(0, maximum)) {
        const key = itemKey(rawKey);
        const recipeId = integer(rawRecipeId, -1);
        if (!key || recipeId < 0 || (!own(graph.items, key) && !own(graph.alternatives, key))) continue;
        if (recipeId === 0) {
            choices[key] = 0;
            continue;
        }
        if ((graph.alternatives[key] ?? []).some((alternative) => alternative.recipeId === recipeId)) {
            choices[key] = recipeId;
        }
    }

    return choices;
}

function normalizeInventory(value, graph, limits) {
    if (!isRecord(value)) return {};
    const inventory = Object.create(null);

    for (const [rawKey, rawCount] of Object.entries(value).slice(0, limits.maxInventoryEntries)) {
        const key = itemKey(rawKey);
        if (!key || (!own(graph.items, key) && !own(graph.alternatives, key))) continue;
        const count = boundedInteger(rawCount, 0, limits.maxInventoryPerItem, 0);
        if (count > 0) inventory[key] = count;
    }

    return inventory;
}

function productYield(recipe, key, fallback = 0) {
    const product = recipe?.products?.find((candidate) => candidate.itemKey === key);
    return product?.count ?? fallback;
}

function chosenProducer(graph, choices, key, forcedAcquire) {
    if (forcedAcquire.has(key) || choices[key] === 0) return null;
    const alternatives = graph.alternatives[key] ?? [];
    const explicit = choices[key];
    const hasAutomaticMetadata = alternatives.some((alternative) => alternative.automatic !== null);
    const choice = explicit
        ? alternatives.find((alternative) => alternative.recipeId === explicit)
        : alternatives.find((alternative) => alternative.automatic === true && !alternative.quest)
            ?? (!hasAutomaticMetadata ? alternatives.find((alternative) => !alternative.quest) : null);
    if (!choice) return null;

    const recipe = graph.recipes[String(choice.recipeId)];
    if (!recipe || productYield(recipe, key, choice.yield) <= 0) return null;
    return { recipe, alternative: choice };
}

function recipeRequirementKeys(recipe) {
    return [
        ...recipe.components.map((component) => component.itemKey),
        ...recipe.containers.map((container) => container.itemKey),
    ].filter((key, index, keys) => keys.indexOf(key) === index);
}

function buildSelectedGraph(graph, choices, warn) {
    const forcedAcquire = new Set();
    const forcedReasons = new Map();
    let finalGraph = {
        edges: new Map(),
        producerByItem: Object.create(null),
        reachable: new Set(),
        depths: new Map(),
    };

    for (let pass = 0; pass <= graph.limits.maxNodes; pass += 1) {
        const edges = new Map();
        const edgeItems = new Map();
        const producerByItem = Object.create(null);
        const reachable = new Set();
        const depths = new Map();
        let changed = false;

        const force = (key, code, recipeId) => {
            if (forcedAcquire.has(key)) return;
            forcedAcquire.add(key);
            forcedReasons.set(key, { code, recipeId });
            changed = true;
        };

        const connect = (parentId, childId, key) => {
            if (!edges.has(parentId)) edges.set(parentId, new Set());
            edges.get(parentId).add(childId);
            const edgeKey = `${parentId}\u0000${childId}`;
            if (!edgeItems.has(edgeKey)) edgeItems.set(edgeKey, []);
            edgeItems.get(edgeKey).push(key);
        };

        const rootId = String(graph.rootRecipeId);
        if (graph.recipes[rootId]) {
            reachable.add(rootId);
            depths.set(rootId, 0);
        }
        const queue = graph.recipes[rootId] ? [rootId] : [];

        for (let cursor = 0; cursor < queue.length; cursor += 1) {
            const id = queue[cursor];
            const recipe = graph.recipes[id];
            if (!recipe) continue;
            const depth = depths.get(id) ?? 0;

            for (const key of recipeRequirementKeys(recipe)) {
                const selected = chosenProducer(graph, choices, key, forcedAcquire);
                if (!selected) continue;
                const childId = String(selected.recipe.id);

                if (depth >= graph.limits.maxDepth && !reachable.has(childId)) {
                    force(key, 'depth-limit', selected.recipe.id);
                    continue;
                }
                if (!reachable.has(childId) && reachable.size >= graph.limits.maxNodes) {
                    force(key, 'node-limit', selected.recipe.id);
                    continue;
                }

                producerByItem[key] = selected.recipe.id;
                connect(id, childId, key);
                if (!reachable.has(childId)) {
                    reachable.add(childId);
                    depths.set(childId, depth + 1);
                    queue.push(childId);
                }
            }
        }

        const cycleState = new Map();
        const findCycleItem = (recipeId) => {
            cycleState.set(recipeId, 1);
            for (const childId of edges.get(recipeId) ?? []) {
                if (cycleState.get(childId) === 1) {
                    return edgeItems.get(`${recipeId}\u0000${childId}`)?.[0] ?? null;
                }
                if (!cycleState.has(childId)) {
                    const item = findCycleItem(childId);
                    if (item) return item;
                }
            }
            cycleState.set(recipeId, 2);
            return null;
        };

        let cycleItem = null;
        for (const recipeId of reachable) {
            if (cycleState.has(recipeId)) continue;
            cycleItem = findCycleItem(recipeId);
            if (cycleItem) break;
        }
        if (cycleItem) {
            const recipeId = producerByItem[cycleItem];
            force(cycleItem, 'cycle', recipeId);
        }

        finalGraph = { edges, producerByItem, reachable, depths };
        if (!changed) break;
    }

    for (const [key, reason] of forcedReasons) {
        const label = graph.items[key]?.name ?? 'An ingredient';
        if (reason.code === 'cycle') {
            warn('cycle', `${label} was switched to acquire because its selected recipe creates a dependency cycle.`, { itemKey: key });
        } else if (reason.code === 'depth-limit') {
            warn('depth-limit', `${label} was switched to acquire at the recursion-depth limit.`, { itemKey: key });
        } else {
            warn('node-limit', `${label} was switched to acquire at the planner node limit.`, { itemKey: key });
        }
    }

    return { ...finalGraph, forcedAcquire };
}

function topologicalOrder(rootRecipeId, reachable, edges) {
    const indegree = new Map([...reachable].map((id) => [id, 0]));
    for (const children of edges.values()) {
        for (const child of children) indegree.set(child, (indegree.get(child) ?? 0) + 1);
    }

    const rootId = String(rootRecipeId);
    const queue = [...reachable]
        .filter((id) => (indegree.get(id) ?? 0) === 0)
        .sort((left, right) => left === rootId ? -1 : right === rootId ? 1 : left.localeCompare(right, undefined, { numeric: true }));
    const order = [];

    while (queue.length) {
        const id = queue.shift();
        order.push(id);
        for (const child of edges.get(id) ?? []) {
            indegree.set(child, (indegree.get(child) ?? 1) - 1);
            if (indegree.get(child) === 0) queue.push(child);
        }
    }

    return order;
}

function sourceKind(item) {
    const kinds = new Set((item?.sources ?? []).map((source) => source.kind));
    if (kinds.has('merchant') || kinds.has('vendor') || kinds.has('bought')) return 'vendor';
    if (kinds.has('forage') || kinds.has('foraged')) return 'forage';
    if (kinds.has('fishing') || kinds.has('fished')) return 'fishing';
    if (kinds.has('drop') || kinds.has('dropped') || kinds.has('npc')) return 'drop';
    if (kinds.has('ground') || kinds.has('ground-spawn') || kinds.has('ground_spawn')) return 'ground';
    if (kinds.has('world-object')) return 'world-object';
    return 'unknown';
}

export function sourceLabel(kind) {
    return {
        vendor: 'Vendor',
        merchant: 'Vendor',
        bought: 'Vendor',
        forage: 'Forage',
        foraged: 'Forage',
        fishing: 'Fishing',
        fished: 'Fishing',
        drop: 'Creature drop',
        dropped: 'Creature drop',
        npc: 'Creature drop',
        ground: 'Ground spawn',
        'ground-spawn': 'Ground spawn',
        ground_spawn: 'Ground spawn',
        'world-object': 'World object',
        craft: 'Subcombine',
        inventory: 'Inventory',
        unknown: 'Unknown source',
    }[kind] ?? 'Other source';
}

function acquireVerb(kind) {
    if (kind === 'vendor') return 'Buy';
    if (['forage', 'fishing', 'drop', 'ground'].includes(kind)) return 'Collect';
    return 'Acquire';
}

function emptyPlan(graph, state, warnings = []) {
    const targetItemKey = itemKey(state?.targetItemKey ?? state?.target ?? graph.rootProducts[0]?.itemKey);
    return {
        valid: false,
        quantity: 1,
        targetItemKey,
        recipeRuns: {},
        itemDemand: {},
        bom: [],
        tree: null,
        checklist: [],
        forcedAcquire: [],
        warnings,
        totalCombines: 0,
        rawItemCount: 0,
        overflowed: warnings.some((warning) => warning.code === 'overflow'),
        choices: {},
        inventory: {},
    };
}

export function calculatePlan(rawGraph, rawState = {}) {
    const graph = normalizeGraph(rawGraph);
    const warnings = [];
    const warningKeys = new Set();
    const warn = (code, message, context = {}) => {
        const key = `${code}:${context.itemKey ?? ''}:${context.recipeId ?? ''}:${message}`;
        if (warningKeys.has(key) || warnings.length >= 100) return;
        warningKeys.add(key);
        warnings.push({ code, message, ...context });
    };

    for (const warning of graph.warnings) warn(warning.code, warning.message);

    const rootRecipe = graph.recipes[String(graph.rootRecipeId)];
    if (!rootRecipe || graph.rootProducts.length === 0) {
        warn('invalid-graph', 'The recipe graph is incomplete, so a plan cannot be calculated.');
        return emptyPlan(graph, rawState, warnings);
    }

    const rootProductKeys = new Set(graph.rootProducts.map((product) => product.itemKey));
    const requestedTarget = itemKey(rawState.targetItemKey ?? rawState.target);
    const targetItemKey = rootProductKeys.has(requestedTarget) ? requestedTarget : graph.rootProducts[0].itemKey;
    const quantity = boundedInteger(rawState.quantity ?? rawState.q, 1, graph.limits.maxQuantity, 1);
    const choices = normalizeChoiceMap(rawState.choices, graph, graph.limits.maxChoices);
    const inventory = normalizeInventory(rawState.inventory, graph, graph.limits);
    for (const [key, recipeId] of Object.entries(choices)) {
        const alternative = (graph.alternatives[key] ?? []).find((candidate) => candidate.recipeId === recipeId);
        if (alternative?.quest) {
            warn('quest-recipe', `${alternative.name || 'A quest recipe'} was selected manually and may require quest access.`, {
                itemKey: key,
                recipeId,
            });
        }
    }
    let overflowed = false;

    const clampCalculated = (value, code, context = {}) => {
        if (Number.isSafeInteger(value) && value <= graph.limits.maxCalculatedQuantity) return Math.max(0, value);
        overflowed = true;
        warn('overflow', `${code} exceeded the configured planning limit and was capped.`, context);
        return graph.limits.maxCalculatedQuantity;
    };
    const add = (left, right, code, context) => {
        if (left > graph.limits.maxCalculatedQuantity || right > graph.limits.maxCalculatedQuantity - left) {
            return clampCalculated(graph.limits.maxCalculatedQuantity + 1, code, context);
        }
        return left + right;
    };
    const multiply = (left, right, code, context) => {
        if (left === 0 || right === 0) return 0;
        if (left > Math.floor(graph.limits.maxCalculatedQuantity / right)) {
            return clampCalculated(graph.limits.maxCalculatedQuantity + 1, code, context);
        }
        return left * right;
    };
    const ceilDivide = (need, perCombine, context) => {
        if (need <= 0) return 0;
        if (perCombine <= 0) {
            warn('missing-yield', 'A selected recipe has no usable yield and was treated as acquire.', context);
            return 0;
        }
        return clampCalculated(Math.ceil(need / perCombine), 'Combine count', context);
    };

    const selected = buildSelectedGraph(graph, choices, warn);
    const order = topologicalOrder(graph.rootRecipeId, selected.reachable, selected.edges);
    if (order.length !== selected.reachable.size) {
        warn('cycle', 'A dependency cycle remained after recipe selection; affected ingredients were treated as acquire.');
    }

    const demands = new Map();
    const recipeRequirements = Object.create(null);
    const recipeRuns = Object.create(null);
    const producedItemsByRecipe = new Map();

    for (const [key, recipeId] of Object.entries(selected.producerByItem)) {
        const id = String(recipeId);
        if (!producedItemsByRecipe.has(id)) producedItemsByRecipe.set(id, new Set());
        producedItemsByRecipe.get(id).add(key);
    }

    const addDemand = (requirement, recipeId) => {
        const current = demands.get(requirement.itemKey) ?? {
            consumptive: 0,
            reusable: 0,
            roles: new Set(),
            parentRecipeIds: new Set(),
            worldObject: false,
        };
        current.consumptive = add(current.consumptive, requirement.consumptive, 'Ingredient quantity', {
            itemKey: requirement.itemKey,
        });
        current.reusable = Math.max(current.reusable, requirement.reusable);
        current.roles.add(requirement.role);
        current.parentRecipeIds.add(recipeId);
        current.worldObject ||= requirement.worldObject;
        demands.set(requirement.itemKey, current);
    };

    const requirementsFor = (recipe, runs) => {
        if (runs <= 0) return [];
        const requirements = [];

        for (const component of recipe.components) {
            const returned = Math.min(component.count, component.returnedOnSuccess);
            const consumedEachRun = component.count - returned;
            const consumptive = multiply(runs, consumedEachRun, 'Returned-component quantity', {
                itemKey: component.itemKey,
                recipeId: recipe.id,
            });
            const reusable = returned;
            requirements.push({
                itemKey: component.itemKey,
                role: 'component',
                count: component.count,
                returnedOnSuccess: returned,
                consumptive,
                reusable,
                need: add(consumptive, reusable, 'Component quantity', { itemKey: component.itemKey }),
                worldObject: false,
            });
        }

        for (const container of recipe.containers) {
            const consumptive = container.consumed
                ? multiply(runs, container.count, 'Container quantity', { itemKey: container.itemKey, recipeId: recipe.id })
                : 0;
            const reusable = container.consumed ? 0 : container.count;
            requirements.push({
                itemKey: container.itemKey,
                role: 'container',
                count: container.count,
                returnedOnSuccess: container.consumed ? 0 : container.count,
                consumptive,
                reusable,
                need: add(consumptive, reusable, 'Container quantity', { itemKey: container.itemKey }),
                consumed: container.consumed,
                worldObject: container.worldObject,
            });
        }

        return requirements;
    };

    const demandFor = (key) => {
        const demand = demands.get(key) ?? {
            consumptive: 0,
            reusable: 0,
            roles: new Set(),
            parentRecipeIds: new Set(),
            worldObject: false,
        };
        const need = add(demand.consumptive, demand.reusable, 'Aggregated ingredient quantity', { itemKey: key });
        const owned = Math.min(need, inventory[key] ?? 0);
        return { ...demand, need, owned, remaining: Math.max(0, need - owned) };
    };

    const rootYield = graph.rootProducts.find((product) => product.itemKey === targetItemKey)?.count
        ?? productYield(rootRecipe, targetItemKey, 1);
    recipeRuns[String(graph.rootRecipeId)] = ceilDivide(quantity, rootYield, {
        itemKey: targetItemKey,
        recipeId: graph.rootRecipeId,
    });

    for (const recipeKey of order) {
        const recipe = graph.recipes[recipeKey];
        if (!recipe) continue;

        if (recipeKey !== String(graph.rootRecipeId)) {
            let combines = 0;
            for (const key of producedItemsByRecipe.get(recipeKey) ?? []) {
                const demand = demandFor(key);
                const selectedAlternative = (graph.alternatives[key] ?? [])
                    .find((alternative) => alternative.recipeId === recipe.id);
                const yieldCount = productYield(recipe, key, selectedAlternative?.yield ?? 0);
                combines = Math.max(combines, ceilDivide(demand.remaining, yieldCount, {
                    itemKey: key,
                    recipeId: recipe.id,
                }));
            }
            recipeRuns[recipeKey] = combines;
        }

        const requirements = requirementsFor(recipe, recipeRuns[recipeKey] ?? 0);
        recipeRequirements[recipeKey] = requirements;
        for (const requirement of requirements) addDemand(requirement, recipe.id);
    }

    const itemDemand = Object.create(null);
    const bom = [];
    for (const [key] of demands) {
        const demand = demandFor(key);
        const item = graph.items[key] ?? {
            itemKey: key,
            id: 0,
            name: 'Unknown item',
            icon: 0,
            url: '',
            sources: [],
            undiscovered: true,
        };
        const recipeId = selected.producerByItem[key] ?? null;
        const recipe = recipeId ? graph.recipes[String(recipeId)] : null;
        const selectedAlternative = recipe
            ? (graph.alternatives[key] ?? []).find((alternative) => alternative.recipeId === recipe.id)
            : null;
        const yieldCount = recipe ? productYield(recipe, key, selectedAlternative?.yield ?? 0) : 0;
        const produced = recipe ? multiply(recipeRuns[String(recipe.id)] ?? 0, yieldCount, 'Crafted output quantity', {
            itemKey: key,
            recipeId: recipe.id,
        }) : 0;
        const kind = sourceKind(item);
        const acquisition = demand.worldObject
            ? 'world-object'
            : demand.remaining === 0
                ? 'inventory'
                : recipeId
                    ? 'craft'
                    : kind;
        const row = {
            itemKey: key,
            itemId: item.id,
            name: item.name,
            icon: item.icon,
            url: item.url,
            need: demand.need,
            consumed: demand.consumptive,
            reusable: demand.reusable,
            owned: demand.owned,
            remaining: demand.remaining,
            recipeId,
            recipeName: recipe?.name ?? '',
            recipeUrl: recipe?.url ?? '',
            recipeRuns: recipe ? (recipeRuns[String(recipe.id)] ?? 0) : 0,
            produced,
            surplus: Math.max(0, produced - demand.remaining),
            acquisition,
            sourceKind: kind,
            sources: item.sources,
            roles: [...demand.roles],
            worldObject: demand.worldObject,
            forcedAcquire: selected.forcedAcquire.has(key),
        };
        itemDemand[key] = row;
        bom.push(row);
    }

    const acquisitionRank = { vendor: 0, forage: 1, fishing: 2, drop: 3, ground: 4, unknown: 5, inventory: 6, craft: 7, 'world-object': 8 };
    bom.sort((left, right) => {
        const rank = (acquisitionRank[left.acquisition] ?? 9) - (acquisitionRank[right.acquisition] ?? 9);
        return rank || left.name.localeCompare(right.name, undefined, { numeric: true, sensitivity: 'base' });
    });

    let treeNodeCount = 0;
    let treeTruncated = false;
    const expandedRecipes = new Set();
    const buildTree = (recipeId, depth, path) => {
        const recipe = graph.recipes[String(recipeId)];
        if (!recipe) return null;
        if (treeNodeCount >= graph.limits.maxNodes) {
            treeTruncated = true;
            return null;
        }
        treeNodeCount += 1;
        const recipeKey = String(recipeId);
        const minimumDepth = selected.depths.get(recipeKey) ?? depth;
        const alreadyExpanded = expandedRecipes.has(recipeKey) || depth > minimumDepth;
        if (!alreadyExpanded) expandedRecipes.add(recipeKey);
        const node = {
            key: `recipe:${recipeId}:${path}`,
            type: 'recipe',
            recipeId: recipe.id,
            name: recipe.name,
            url: recipe.url,
            quantity: recipeRuns[String(recipe.id)] ?? 0,
            tradeskillName: recipe.tradeskillName,
            trivial: recipe.trivial,
            shared: alreadyExpanded,
            children: [],
        };
        if (alreadyExpanded) return node;

        for (const [index, requirement] of (recipeRequirements[String(recipe.id)] ?? []).entries()) {
            if (treeNodeCount >= graph.limits.maxNodes) {
                treeTruncated = true;
                break;
            }
            const material = itemDemand[requirement.itemKey];
            const item = graph.items[requirement.itemKey];
            const childRecipeId = selected.producerByItem[requirement.itemKey];
            const child = {
                key: `item:${requirement.itemKey}:${path}:${index}`,
                type: 'item',
                itemKey: requirement.itemKey,
                name: item?.name ?? 'Unknown item',
                url: item?.url ?? '',
                quantity: requirement.need,
                totalNeed: material?.need ?? requirement.need,
                owned: material?.owned ?? 0,
                remaining: material?.remaining ?? requirement.need,
                acquisition: material?.acquisition ?? 'unknown',
                sourceKind: material?.sourceKind ?? 'unknown',
                returnedOnSuccess: requirement.returnedOnSuccess,
                reusable: requirement.reusable,
                role: requirement.role,
                worldObject: requirement.worldObject,
                forcedAcquire: selected.forcedAcquire.has(requirement.itemKey),
                children: [],
            };
            treeNodeCount += 1;
            if (childRecipeId && (recipeRuns[String(childRecipeId)] ?? 0) > 0) {
                const recipeChild = buildTree(childRecipeId, depth + 1, `${path}.${index}`);
                if (recipeChild) child.children.push(recipeChild);
            }
            node.children.push(child);
        }

        return node;
    };

    const tree = buildTree(graph.rootRecipeId, 0, 'root');
    if (treeTruncated) {
        warn('tree-limit', 'The dependency-tree display was truncated at the planner node limit. The bill of materials remains aggregated.');
    }
    const checklist = [];
    for (const material of bom) {
        if (material.remaining <= 0 || material.recipeId || material.worldObject) continue;
        const kind = material.sourceKind;
        checklist.push({
            id: `acquire:${material.itemKey}`,
            type: 'acquire',
            itemKey: material.itemKey,
            label: `${acquireVerb(kind)} ${material.name}`,
            description: material.sources[0]?.details || sourceLabel(kind),
            quantity: material.remaining,
            sourceKind: kind,
            url: material.url,
        });
    }

    for (const recipeKey of [...order].reverse()) {
        const recipe = graph.recipes[recipeKey];
        const combines = recipeRuns[recipeKey] ?? 0;
        if (!recipe || combines <= 0) continue;
        const containers = recipe.containers.map((container) => container.name).filter(Boolean);
        checklist.push({
            id: `recipe:${recipe.id}`,
            type: recipe.id === graph.rootRecipeId ? 'finish' : 'craft',
            recipeId: recipe.id,
            label: recipe.id === graph.rootRecipeId ? `Make ${graph.rootName}` : `Make ${recipe.name}`,
            description: [
                recipe.tradeskillName,
                containers.length ? containers.join(', ') : '',
            ].filter(Boolean).join(' · '),
            quantity: combines,
            url: recipe.url,
        });
    }

    let totalCombines = 0;
    for (const combines of Object.values(recipeRuns)) {
        totalCombines = add(totalCombines, combines, 'Total combine count');
    }
    let rawItemCount = 0;
    for (const material of bom) {
        if (!material.recipeId && !material.worldObject) {
            rawItemCount = add(rawItemCount, material.remaining, 'Raw material count');
        }
    }

    return {
        valid: true,
        quantity,
        targetItemKey,
        recipeRuns,
        itemDemand,
        bom,
        tree,
        checklist,
        forcedAcquire: [...selected.forcedAcquire],
        warnings,
        totalCombines,
        rawItemCount,
        overflowed,
        choices,
        inventory,
    };
}

function bytesToBase64Url(bytes) {
    let base64;
    if (typeof btoa === 'function') {
        let binary = '';
        for (const byte of bytes) binary += String.fromCharCode(byte);
        base64 = btoa(binary);
    } else if (typeof globalThis.Buffer !== 'undefined') {
        base64 = globalThis.Buffer.from(bytes).toString('base64');
    } else {
        throw new Error('Base64 encoding is unavailable.');
    }
    return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/u, '');
}

function base64UrlToBytes(value) {
    if (!/^[A-Za-z0-9_-]+$/u.test(value)) throw new Error('Invalid base64url payload.');
    const padded = value.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - value.length % 4) % 4);
    if (typeof atob === 'function') {
        const binary = atob(padded);
        return Uint8Array.from(binary, (character) => character.charCodeAt(0));
    }
    if (typeof globalThis.Buffer !== 'undefined') return Uint8Array.from(globalThis.Buffer.from(padded, 'base64'));
    throw new Error('Base64 decoding is unavailable.');
}

function shareContext(graphOrLimits) {
    const graph = isRecord(graphOrLimits) && ('recipes' in graphOrLimits || 'rootProducts' in graphOrLimits)
        ? normalizeGraph(graphOrLimits)
        : null;
    return { graph, limits: normalizeLimits(graphOrLimits) };
}

export function encodeShareState(state, graphOrLimits = {}) {
    const { graph, limits } = shareContext(graphOrLimits);
    const quantity = boundedInteger(state?.q ?? state?.quantity, 1, limits.maxQuantity, 1);
    const validTargets = new Set(graph?.rootProducts.map((product) => product.itemKey) ?? []);
    const requestedTarget = itemKey(state?.target ?? state?.targetItemKey);
    const target = graph
        ? (validTargets.has(requestedTarget) ? requestedTarget : graph.rootProducts[0]?.itemKey ?? '')
        : requestedTarget;
    const choices = graph
        ? normalizeChoiceMap(state?.choices, graph, limits.maxChoices)
        : normalizeUnscopedChoices(state?.choices, limits.maxChoices);
    const orderedChoices = Object.fromEntries(Object.entries(choices).sort(([left], [right]) => left.localeCompare(right)));
    const payload = JSON.stringify({ v: SHARE_SCHEMA_VERSION, q: quantity, target, choices: orderedChoices });
    const encoded = bytesToBase64Url(new TextEncoder().encode(payload));
    if (encoded.length > limits.maxShareLength) throw new RangeError('The shared plan exceeds the configured URL limit.');
    return encoded;
}

function normalizeUnscopedChoices(value, maximum) {
    if (!isRecord(value)) return {};
    const choices = Object.create(null);
    for (const [rawKey, rawRecipeId] of Object.entries(value).slice(0, maximum)) {
        const key = itemKey(rawKey);
        const recipeId = integer(rawRecipeId, -1);
        if (key && recipeId >= 0) choices[key] = recipeId;
    }
    return choices;
}

export function decodeShareState(value, graphOrLimits = {}) {
    const { graph, limits } = shareContext(graphOrLimits);
    let encoded = shortString(value, limits.maxShareLength + 20);
    if (encoded.startsWith('#')) encoded = new URLSearchParams(encoded.slice(1)).get('plan') ?? '';
    if (encoded.startsWith('plan=')) encoded = encoded.slice(5);
    if (!encoded || encoded.length > limits.maxShareLength) return null;

    try {
        const decoded = JSON.parse(new TextDecoder().decode(base64UrlToBytes(encoded)));
        if (!isRecord(decoded) || decoded.v !== SHARE_SCHEMA_VERSION) return null;
        const quantity = integer(decoded.q, 0);
        if (quantity < 1 || quantity > limits.maxQuantity) return null;
        const target = itemKey(decoded.target);
        if (!target) return null;
        if (graph && !graph.rootProducts.some((product) => product.itemKey === target)) return null;
        if (!isRecord(decoded.choices) || Object.keys(decoded.choices).length > limits.maxChoices) return null;
        const choices = graph
            ? normalizeChoiceMap(decoded.choices, graph, limits.maxChoices)
            : normalizeUnscopedChoices(decoded.choices, limits.maxChoices);
        return { v: SHARE_SCHEMA_VERSION, q: quantity, target, choices };
    } catch {
        return null;
    }
}

function normalizeStringMap(value, maximumEntries, maximumValue, allowedKeys = null) {
    if (!isRecord(value)) return {};
    const normalized = Object.create(null);
    for (const [rawKey, rawValue] of Object.entries(value).slice(0, maximumEntries)) {
        const key = itemKey(rawKey);
        if (!key || (allowedKeys && !allowedKeys.has(key))) continue;
        const number = boundedInteger(rawValue, 0, maximumValue, 0);
        if (number > 0) normalized[key] = number;
    }
    return normalized;
}

function normalizeSavedPlan(plan, limits) {
    if (!isRecord(plan)) return null;
    const id = shortString(plan.id, 80);
    const recipeId = integer(plan.recipeId ?? plan.recipe_id);
    const recipeName = shortString(plan.recipeName ?? plan.recipe_name, 240);
    const targetItemKey = itemKey(plan.targetItemKey ?? plan.target_item_key ?? plan.target);
    if (!/^[A-Za-z0-9_-]{1,80}$/u.test(id) || recipeId <= 0 || !recipeName || !targetItemKey) return null;

    const choices = normalizeUnscopedChoices(plan.choices, limits.maxChoices);
    const inventory = normalizeStringMap(
        plan.inventory,
        limits.maxInventoryEntries,
        limits.maxInventoryPerItem,
    );
    const completed = Array.isArray(plan.completed)
        ? plan.completed
            .map((value) => shortString(value, 140))
            .filter((value, index, values) => value && values.indexOf(value) === index)
            .slice(0, limits.maxCompletedSteps)
        : [];
    const createdAt = Number.isFinite(Date.parse(plan.createdAt)) ? new Date(plan.createdAt).toISOString() : '';
    const updatedAt = Number.isFinite(Date.parse(plan.updatedAt)) ? new Date(plan.updatedAt).toISOString() : createdAt;

    return {
        id,
        recipeId,
        recipeName,
        targetItemKey,
        quantity: boundedInteger(plan.quantity ?? plan.q, 1, limits.maxQuantity, 1),
        choices,
        inventory,
        completed,
        stepCount: boundedInteger(plan.stepCount ?? plan.step_count, 0, limits.maxCompletedSteps, 0),
        createdAt,
        updatedAt,
    };
}

export function normalizeSavedPlans(value, rawLimits = {}) {
    const limits = normalizeLimits(rawLimits);
    let source = value;
    if (typeof source === 'string') {
        try {
            source = JSON.parse(source);
        } catch {
            return [];
        }
    }
    const plans = Array.isArray(source) ? source : (isRecord(source) && source.version === STORAGE_SCHEMA_VERSION ? source.plans : []);
    if (!Array.isArray(plans)) return [];

    const seen = new Set();
    return plans
        .map((plan) => normalizeSavedPlan(plan, limits))
        .filter((plan) => {
            if (!plan || seen.has(plan.id)) return false;
            seen.add(plan.id);
            return true;
        })
        .sort((left, right) => Date.parse(right.updatedAt || 0) - Date.parse(left.updatedAt || 0))
        .slice(0, limits.maxSavedPlans);
}

export function loadSavedPlans(storage, rawLimits = {}) {
    if (!storage || typeof storage.getItem !== 'function') return { plans: [], error: 'unavailable' };
    const limits = normalizeLimits(rawLimits);
    let raw;
    try {
        raw = storage.getItem(STORAGE_KEY);
    } catch {
        return { plans: [], error: 'unavailable' };
    }
    if (raw === null) return { plans: [], error: null };
    if (new TextEncoder().encode(raw).byteLength > limits.maxStorageBytes) {
        return { plans: [], error: 'corrupt' };
    }

    try {
        const parsed = JSON.parse(raw);
        if (!isRecord(parsed) || parsed.version !== STORAGE_SCHEMA_VERSION || !Array.isArray(parsed.plans)) {
            return { plans: [], error: 'corrupt' };
        }
        return { plans: normalizeSavedPlans(parsed, limits), error: null };
    } catch {
        return { plans: [], error: 'corrupt' };
    }
}

export function writeSavedPlans(storage, plans, rawLimits = {}) {
    if (!storage || typeof storage.setItem !== 'function') return { ok: false, error: 'unavailable' };
    const limits = normalizeLimits(rawLimits);
    const normalized = normalizeSavedPlans(plans, limits);
    const serialized = JSON.stringify({ version: STORAGE_SCHEMA_VERSION, plans: normalized });
    const bytes = new TextEncoder().encode(serialized).byteLength;
    if (bytes > limits.maxStorageBytes) return { ok: false, error: 'too-large' };

    try {
        storage.setItem(STORAGE_KEY, serialized);
        return { ok: true, error: null, plans: normalized };
    } catch {
        return { ok: false, error: 'quota' };
    }
}

export function flattenPlanTree(tree) {
    if (!tree) return [];
    const rows = [];

    const visit = (node, depth, ancestors) => {
        const children = Array.isArray(node.children) ? node.children : [];
        const meta = node.type === 'recipe'
            ? [node.tradeskillName, node.trivial ? `trivial ${node.trivial}` : '', node.shared ? 'shared subcombine' : '']
                .filter(Boolean)
                .join(' · ')
            : [
                node.role === 'container' ? 'container' : sourceLabel(node.acquisition),
                node.reusable ? 'reusable' : '',
                node.forcedAcquire ? 'cycle fallback' : '',
            ].filter(Boolean).join(' · ');

        rows.push({
            ...node,
            depth,
            ancestors,
            hasChildren: children.length > 0,
            meta,
        });
        const nextAncestors = [...ancestors, node.key];
        for (const child of children) visit(child, depth + 1, nextAncestors);
    };

    visit(tree, 0, []);
    return rows;
}

function browserStorage() {
    if (typeof window === 'undefined') return null;
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

function createLocalId() {
    if (typeof globalThis.crypto?.randomUUID === 'function') return globalThis.crypto.randomUUID();
    const random = Math.floor(Math.random() * Number.MAX_SAFE_INTEGER).toString(36);
    return `${Date.now().toString(36)}-${random}`;
}

function timestamp() {
    return new Date().toISOString();
}

function safeHashValue(hash, key, maximum = 100) {
    if (typeof hash !== 'string' || !hash.startsWith('#')) return '';
    const value = new URLSearchParams(hash.slice(1)).get(key) ?? '';
    return value.length <= maximum ? value : '';
}

async function copyText(value) {
    if (typeof navigator !== 'undefined' && navigator.clipboard && globalThis.isSecureContext) {
        await navigator.clipboard.writeText(value);
        return true;
    }
    if (typeof document === 'undefined') return false;

    const input = document.createElement('textarea');
    input.value = value;
    input.setAttribute('readonly', '');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    const copied = typeof document.execCommand === 'function' && document.execCommand('copy');
    input.remove();
    return copied;
}

function formatSourceDetails(material) {
    if (material.acquisition === 'craft') return material.recipeName || 'Subcombine';
    if (material.acquisition === 'inventory') return 'Already covered by inventory';
    if (material.acquisition === 'world-object') return 'Required tradeskill station';
    return material.sources?.[0]?.details || sourceLabel(material.sourceKind);
}

function savedPlanRecord(component, existing = null) {
    const now = timestamp();
    return {
        id: existing?.id ?? createLocalId(),
        recipeId: component.graph.rootRecipeId,
        recipeName: component.graph.rootName,
        targetItemKey: component.targetItemKey,
        quantity: component.quantity,
        choices: { ...component.choices },
        inventory: { ...component.inventory },
        completed: [...component.completed],
        stepCount: component.plan.checklist.length,
        createdAt: existing?.createdAt || now,
        updatedAt: now,
    };
}

export function tradeskillPlanner(rawGraph, rawOptions = {}) {
    const graph = normalizeGraph(rawGraph);
    const options = isRecord(rawOptions) ? rawOptions : {};

    return {
        graph,
        options,
        quantity: 1,
        targetItemKey: graph.rootProducts[0]?.itemKey ?? '',
        choices: {},
        inventory: {},
        completed: [],
        plan: emptyPlan(graph, {}),
        treeRows: [],
        collapsed: {},
        bomFilter: 'all',
        savedId: null,
        saved: false,
        status: '',
        statusType: 'info',
        storageError: null,
        autoSaveTimer: null,
        statusTimer: null,

        init() {
            let restored = false;
            if (typeof window !== 'undefined') {
                const savedId = safeHashValue(window.location.hash, 'saved', 80);
                if (/^[A-Za-z0-9_-]{1,80}$/u.test(savedId)) {
                    restored = this.restoreSavedPlan(savedId);
                    if (!restored && !this.storageError) {
                        this.setStatus('That browser-saved plan was not found for this recipe.', 'error');
                    }
                }

                if (!restored && !savedId) {
                    const shared = decodeShareState(window.location.hash, this.graph);
                    if (shared) {
                        this.quantity = shared.q;
                        this.targetItemKey = shared.target;
                        this.choices = shared.choices;
                        this.setStatus('Shared plan loaded. Inventory and checklist progress remain local.', 'success');
                        restored = true;
                    } else if (window.location.hash.startsWith('#plan=')) {
                        this.setStatus('This shared plan link is invalid or exceeds server limits.', 'error');
                    }
                }

                window.addEventListener('storage', (event) => {
                    if (event.key !== STORAGE_KEY || !this.savedId) return;
                    const result = loadSavedPlans(browserStorage(), this.graph.limits);
                    if (!result.plans.some((candidate) => candidate.id === this.savedId)) {
                        this.saved = false;
                        this.savedId = null;
                        this.setStatus('This plan was removed in another browser tab.', 'info');
                    }
                });
            }

            this.recalculate(false);
        },

        restoreSavedPlan(id) {
            const result = loadSavedPlans(browserStorage(), this.graph.limits);
            this.storageError = result.error;
            if (result.error) {
                this.setStatus(
                    result.error === 'corrupt'
                        ? 'Saved plan data in this browser is unreadable. It was not overwritten.'
                        : 'Browser storage is unavailable; the planner still works without saving.',
                    'error',
                );
                return false;
            }

            const savedPlan = result.plans.find((candidate) => candidate.id === id);
            if (!savedPlan || savedPlan.recipeId !== this.graph.rootRecipeId) return false;

            this.quantity = savedPlan.quantity;
            this.targetItemKey = this.graph.rootProducts.some((product) => product.itemKey === savedPlan.targetItemKey)
                ? savedPlan.targetItemKey
                : this.graph.rootProducts[0]?.itemKey ?? '';
            this.choices = normalizeChoiceMap(savedPlan.choices, this.graph, this.graph.limits.maxChoices);
            this.inventory = normalizeInventory(savedPlan.inventory, this.graph, this.graph.limits);
            this.completed = savedPlan.completed;
            this.savedId = savedPlan.id;
            this.saved = true;
            this.setStatus('Saved plan restored from this browser.', 'success');
            return true;
        },

        recalculate(scheduleSave = true) {
            this.plan = calculatePlan(this.graph, {
                quantity: this.quantity,
                targetItemKey: this.targetItemKey,
                choices: this.choices,
                inventory: this.inventory,
            });
            this.quantity = this.plan.quantity;
            this.targetItemKey = this.plan.targetItemKey;
            this.choices = this.plan.choices;
            this.inventory = this.plan.inventory;
            this.treeRows = flattenPlanTree(this.plan.tree);
            const currentSteps = new Set(this.plan.checklist.map((step) => step.id));
            this.completed = this.completed.filter((stepId) => currentSteps.has(stepId));
            if (scheduleSave) this.scheduleAutoSave();
        },

        changeQuantity(value) {
            this.quantity = boundedInteger(value, 1, this.graph.limits.maxQuantity, 1);
            this.recalculate();
        },

        adjustQuantity(amount) {
            this.changeQuantity(this.quantity + amount);
        },

        changeTarget(value) {
            const key = itemKey(value);
            if (!this.graph.rootProducts.some((product) => product.itemKey === key)) return;
            this.targetItemKey = key;
            this.recalculate();
        },

        choiceValue(key) {
            return own(this.choices, key) ? String(this.choices[key]) : 'auto';
        },

        choiceOptions(key) {
            const alternatives = this.graph.alternatives[key] ?? [];
            const automatic = alternatives.find((alternative) => alternative.automatic === true && !alternative.quest)
                ?? (alternatives.every((alternative) => alternative.automatic === null)
                    ? alternatives.find((alternative) => !alternative.quest)
                    : null);
            return [
                {
                    value: 'auto',
                    label: automatic
                        ? `Automatic · ${automatic.name || `Recipe ${automatic.recipeId}`}`
                        : 'Automatic · acquire this item',
                },
                { value: '0', label: 'Acquire instead of crafting' },
                ...alternatives.map((alternative) => ({
                    value: String(alternative.recipeId),
                    label: [
                        alternative.name || this.graph.recipes[String(alternative.recipeId)]?.name || `Recipe ${alternative.recipeId}`,
                        `yields ${alternative.yield}`,
                        alternative.trivial ? `trivial ${alternative.trivial}` : '',
                        alternative.quest ? 'quest recipe · manual' : '',
                    ].filter(Boolean).join(' · '),
                })),
            ];
        },

        hasAlternatives(key) {
            return (this.graph.alternatives[key] ?? []).length > 0;
        },

        changeChoice(key, value) {
            const next = { ...this.choices };
            if (value === 'auto') {
                delete next[key];
            } else {
                const recipeId = integer(value, -1);
                if (recipeId === 0 || (this.graph.alternatives[key] ?? []).some((alternative) => alternative.recipeId === recipeId)) {
                    next[key] = recipeId;
                }
            }
            this.choices = next;
            this.recalculate();
        },

        inventoryValue(key) {
            return this.inventory[key] ?? 0;
        },

        changeInventory(key, value) {
            const count = boundedInteger(value, 0, this.graph.limits.maxInventoryPerItem, 0);
            const next = { ...this.inventory };
            if (count > 0) next[key] = count;
            else delete next[key];
            this.inventory = next;
            this.recalculate();
        },

        clearInventory() {
            this.inventory = {};
            this.recalculate();
            this.setStatus('Inventory quantities cleared.', 'info');
        },

        toggleBranch(key) {
            this.collapsed = { ...this.collapsed, [key]: !this.collapsed[key] };
        },

        visibleTreeRows() {
            return this.treeRows.filter((row) => !row.ancestors.some((ancestor) => this.collapsed[ancestor]));
        },

        filteredBom() {
            if (this.bomFilter === 'all') return this.plan.bom;
            if (this.bomFilter === 'acquire') {
                return this.plan.bom.filter((material) => !['craft', 'inventory', 'world-object'].includes(material.acquisition));
            }
            return this.plan.bom.filter((material) => material.acquisition === this.bomFilter || material.sourceKind === this.bomFilter);
        },

        filterCount(filter) {
            const previous = this.bomFilter;
            this.bomFilter = filter;
            const count = this.filteredBom().length;
            this.bomFilter = previous;
            return count;
        },

        materialSource(material) {
            return formatSourceDetails(material);
        },

        materialSourceLabel(material) {
            if (material.acquisition === 'craft') return 'Subcombine';
            return sourceLabel(material.acquisition === 'inventory' ? 'inventory' : material.sourceKind);
        },

        isCompleted(stepId) {
            return this.completed.includes(stepId);
        },

        toggleCompleted(stepId, checked) {
            const complete = new Set(this.completed);
            if (checked) complete.add(stepId);
            else complete.delete(stepId);
            this.completed = [...complete].slice(0, this.graph.limits.maxCompletedSteps);
            this.scheduleAutoSave();
        },

        completedCount() {
            const valid = new Set(this.plan.checklist.map((step) => step.id));
            return this.completed.filter((stepId) => valid.has(stepId)).length;
        },

        progressPercent() {
            return this.plan.checklist.length ? Math.round((this.completedCount() / this.plan.checklist.length) * 100) : 0;
        },

        savePlan() {
            const storage = browserStorage();
            const result = loadSavedPlans(storage, this.graph.limits);
            this.storageError = result.error;
            if (result.error) {
                this.setStatus(
                    result.error === 'corrupt'
                        ? 'Saved plan data is unreadable. Open Saved plans to reset it before saving.'
                        : 'Browser storage is unavailable. Copy a plan link instead.',
                    'error',
                );
                return;
            }

            const existingIndex = result.plans.findIndex((candidate) => candidate.id === this.savedId);
            if (existingIndex < 0 && result.plans.length >= this.graph.limits.maxSavedPlans) {
                this.setStatus(`This browser already has the maximum of ${this.graph.limits.maxSavedPlans} saved plans.`, 'error');
                return;
            }

            const existing = existingIndex >= 0 ? result.plans[existingIndex] : null;
            const record = savedPlanRecord(this, existing);
            const plans = existingIndex >= 0
                ? result.plans.map((candidate, index) => index === existingIndex ? record : candidate)
                : [record, ...result.plans];
            const write = writeSavedPlans(storage, plans, this.graph.limits);
            if (!write.ok) {
                this.setStatus(
                    write.error === 'too-large'
                        ? 'This plan is too large for the configured browser-storage limit.'
                        : 'The browser could not save this plan. Copy a plan link instead.',
                    'error',
                );
                return;
            }

            this.savedId = record.id;
            this.saved = true;
            this.storageError = null;
            this.setStatus(existing ? 'Saved plan updated.' : 'Plan saved in this browser.', 'success');
            if (typeof window !== 'undefined') {
                const url = new URL(window.location.href);
                url.hash = `saved=${encodeURIComponent(record.id)}`;
                window.history.replaceState(null, '', url);
            }
        },

        scheduleAutoSave() {
            if (!this.saved || !this.savedId) return;
            if (this.autoSaveTimer) clearTimeout(this.autoSaveTimer);
            this.autoSaveTimer = setTimeout(() => this.autoSave(), 250);
        },

        autoSave() {
            const storage = browserStorage();
            const result = loadSavedPlans(storage, this.graph.limits);
            if (result.error) {
                this.saved = false;
                this.storageError = result.error;
                return;
            }
            const index = result.plans.findIndex((candidate) => candidate.id === this.savedId);
            if (index < 0) {
                this.saved = false;
                this.savedId = null;
                return;
            }
            const record = savedPlanRecord(this, result.plans[index]);
            const write = writeSavedPlans(
                storage,
                result.plans.map((candidate, planIndex) => planIndex === index ? record : candidate),
                this.graph.limits,
            );
            if (!write.ok) {
                this.saved = false;
                this.storageError = write.error;
                this.setStatus('Automatic saving stopped because browser storage is unavailable.', 'error');
            }
        },

        async copyPlanLink() {
            if (typeof window === 'undefined') return;
            try {
                const payload = encodeShareState({
                    q: this.quantity,
                    target: this.targetItemKey,
                    choices: this.choices,
                }, this.graph);
                const url = new URL(window.location.href);
                url.hash = `plan=${payload}`;
                const copied = await copyText(url.toString());
                this.setStatus(
                    copied
                        ? 'Plan link copied. Inventory and checklist progress were not included.'
                        : 'Copying is unavailable in this browser.',
                    copied ? 'success' : 'error',
                );
            } catch (error) {
                this.setStatus(error instanceof RangeError ? error.message : 'The plan link could not be created.', 'error');
            }
        },

        setStatus(message, type = 'info') {
            this.status = message;
            this.statusType = type;
            if (this.statusTimer) clearTimeout(this.statusTimer);
            if (type !== 'error') {
                this.statusTimer = setTimeout(() => {
                    this.status = '';
                }, 6_000);
            }
        },
    };
}

export const createTradeskillPlanner = tradeskillPlanner;

export function savedTradeskillPlans(rawOptions = {}) {
    const options = isRecord(rawOptions) ? rawOptions : {};
    const limits = normalizeLimits(options.limits ?? {});

    return {
        options,
        limits,
        plans: [],
        error: null,
        status: '',
        statusType: 'info',

        init() {
            this.refresh();
            if (typeof window !== 'undefined') {
                window.addEventListener('storage', (event) => {
                    if (event.key === STORAGE_KEY) this.refresh(false);
                });
            }
        },

        refresh(announce = true) {
            const result = loadSavedPlans(browserStorage(), this.limits);
            this.plans = result.plans;
            this.error = result.error;
            if (announce && result.error === 'corrupt') {
                this.setStatus('Saved plan data in this browser is unreadable. You can reset it below.', 'error');
            } else if (announce && result.error === 'unavailable') {
                this.setStatus('Browser storage is unavailable on this device.', 'error');
            }
        },

        planUrl(plan, hashKey = 'saved', hashValue = plan.id) {
            const template = shortString(this.options.planRouteTemplate, 2_048);
            if (!template || !Number.isSafeInteger(plan.recipeId) || plan.recipeId <= 0) return '#';
            const path = template.replace('__RECIPE__', encodeURIComponent(String(plan.recipeId)));
            try {
                const url = typeof window !== 'undefined' ? new URL(path, window.location.origin) : new URL(path, 'http://localhost');
                url.hash = `${hashKey}=${encodeURIComponent(hashValue)}`;
                return typeof window !== 'undefined' ? url.toString() : `${url.pathname}${url.search}${url.hash}`;
            } catch {
                return '#';
            }
        },

        async copyPlanLink(plan) {
            try {
                const payload = encodeShareState({
                    q: plan.quantity,
                    target: plan.targetItemKey,
                    choices: plan.choices,
                }, this.limits);
                const copied = await copyText(this.planUrl(plan, 'plan', payload));
                this.setStatus(
                    copied
                        ? 'Plan link copied without inventory or checklist progress.'
                        : 'Copying is unavailable in this browser.',
                    copied ? 'success' : 'error',
                );
            } catch (error) {
                this.setStatus(error instanceof RangeError ? error.message : 'The plan link could not be created.', 'error');
            }
        },

        removePlan(id) {
            const remaining = this.plans.filter((plan) => plan.id !== id);
            const result = writeSavedPlans(browserStorage(), remaining, this.limits);
            if (!result.ok) {
                this.setStatus('The saved plan could not be removed.', 'error');
                return;
            }
            this.plans = result.plans;
            this.setStatus('Saved plan removed from this browser.', 'success');
        },

        resetCorruptStorage() {
            const storage = browserStorage();
            if (!storage || typeof storage.removeItem !== 'function') {
                this.setStatus('Browser storage is unavailable.', 'error');
                return;
            }
            try {
                storage.removeItem(STORAGE_KEY);
                this.plans = [];
                this.error = null;
                this.setStatus('Unreadable saved-plan data was cleared.', 'success');
            } catch {
                this.setStatus('The unreadable saved-plan data could not be cleared.', 'error');
            }
        },

        completedCount(plan) {
            return plan.completed.length;
        },

        progressPercent(plan) {
            return plan.stepCount > 0 ? Math.min(100, Math.round((plan.completed.length / plan.stepCount) * 100)) : 0;
        },

        formatDate(value) {
            const date = new Date(value);
            if (!Number.isFinite(date.getTime())) return 'Unknown';
            try {
                return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
            } catch {
                return date.toLocaleString();
            }
        },

        setStatus(message, type = 'info') {
            this.status = message;
            this.statusType = type;
        },
    };
}

export const createSavedTradeskillPlans = savedTradeskillPlans;
