import assert from 'node:assert/strict';
import test from 'node:test';

import {
    calculatePlan,
    decodeShareState,
    encodeShareState,
    flattenPlanTree,
    loadSavedPlans,
    normalizeGraph,
    normalizeSavedPlans,
} from '../../resources/js/tradeskill-planner.js';

const LIMITS = Object.freeze({
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

function makeItem(itemKey, name = `Item ${itemKey}`) {
    return {
        itemKey: String(itemKey),
        name,
        url: `/items/${itemKey}`,
        sources: [],
    };
}

function makeRecipe(id, {
    name = `Recipe ${id}`,
    products = [],
    components = [],
    containers = [],
} = {}) {
    return { id, name, products, components, containers };
}

function makeGraph({
    rootRecipeId = 1,
    rootName = 'Final Combine',
    rootProducts = [{ itemKey: '100', count: 1 }],
    recipes,
    items,
    alternatives = {},
    limits = {},
}) {
    return {
        schemaVersion: 1,
        rootRecipeId,
        rootName,
        rootProducts,
        recipes,
        items,
        alternatives,
        limits: { ...LIMITS, ...limits },
    };
}

function findBom(plan, itemKey) {
    return plan.bom.find((entry) => entry.itemKey === String(itemKey));
}

function warningText(plan) {
    return plan.warnings.map((warning) => (
        typeof warning === 'string' ? warning : JSON.stringify(warning)
    )).join(' ');
}

test('rounds root combines up using the selected product yield', () => {
    const graph = makeGraph({
        rootProducts: [{ itemKey: '100', count: 2 }],
        recipes: {
            '1': makeRecipe(1, {
                products: [{ itemKey: '100', count: 2 }],
                components: [{ itemKey: '200', count: 3, returnedOnSuccess: 0 }],
            }),
        },
        items: {
            '100': makeItem(100, 'Finished Item'),
            '200': makeItem(200, 'Raw Material'),
        },
    });

    const plan = calculatePlan(graph, {
        quantity: 5,
        targetItemKey: '100',
        choices: {},
        inventory: {},
    });

    assert.equal(plan.quantity, 5);
    assert.equal(plan.totalCombines, 3);
    assert.equal(findBom(plan, 200).need, 9);
    assert.equal(findBom(plan, 200).remaining, 9);
});

test('aggregates diamond-shaped shared demand before applying subcombine yield rounding', () => {
    const graph = makeGraph({
        recipes: {
            '1': makeRecipe(1, {
                products: [{ itemKey: '100', count: 1 }],
                components: [
                    { itemKey: '200', count: 1, returnedOnSuccess: 0 },
                    { itemKey: '300', count: 1, returnedOnSuccess: 0 },
                ],
            }),
            '2': makeRecipe(2, {
                products: [{ itemKey: '200', count: 1 }],
                components: [{ itemKey: '400', count: 1, returnedOnSuccess: 0 }],
            }),
            '3': makeRecipe(3, {
                products: [{ itemKey: '300', count: 1 }],
                components: [{ itemKey: '400', count: 1, returnedOnSuccess: 0 }],
            }),
            '4': makeRecipe(4, {
                products: [{ itemKey: '400', count: 2 }],
                components: [{ itemKey: '500', count: 1, returnedOnSuccess: 0 }],
            }),
        },
        items: Object.fromEntries([100, 200, 300, 400, 500].map((id) => [String(id), makeItem(id)])),
        alternatives: {
            '200': [{ recipeId: 2, yield: 1 }],
            '300': [{ recipeId: 3, yield: 1 }],
            '400': [{ recipeId: 4, yield: 2 }],
        },
    });

    const plan = calculatePlan(graph, {
        quantity: 1,
        targetItemKey: '100',
        choices: {},
        inventory: {},
    });

    assert.equal(findBom(plan, 500).need, 1);
    assert.equal(plan.totalCombines, 4, 'the shared item should be made in one two-output combine');
});

test('applies inventory to intermediates before rounding and to raw materials after expansion', () => {
    const graph = makeGraph({
        recipes: {
            '1': makeRecipe(1, {
                products: [{ itemKey: '100', count: 1 }],
                components: [{ itemKey: '200', count: 5, returnedOnSuccess: 0 }],
            }),
            '2': makeRecipe(2, {
                products: [{ itemKey: '200', count: 2 }],
                components: [{ itemKey: '300', count: 3, returnedOnSuccess: 0 }],
            }),
        },
        items: Object.fromEntries([100, 200, 300].map((id) => [String(id), makeItem(id)])),
        alternatives: {
            '200': [{ recipeId: 2, yield: 2 }],
        },
    });

    const plan = calculatePlan(graph, {
        quantity: 1,
        targetItemKey: '100',
        choices: {},
        inventory: { '200': 1, '300': 2 },
    });

    assert.equal(plan.totalCombines, 3, 'one root combine plus two subcombines');
    assert.equal(findBom(plan, 300).need, 6);
    assert.equal(findBom(plan, 300).owned, 2);
    assert.equal(findBom(plan, 300).remaining, 4);
});

test('an explicit acquire choice stops recursion at that item', () => {
    const graph = makeGraph({
        recipes: {
            '1': makeRecipe(1, {
                products: [{ itemKey: '100', count: 1 }],
                components: [{ itemKey: '200', count: 5, returnedOnSuccess: 0 }],
            }),
            '2': makeRecipe(2, {
                products: [{ itemKey: '200', count: 2 }],
                components: [{ itemKey: '300', count: 3, returnedOnSuccess: 0 }],
            }),
        },
        items: Object.fromEntries([100, 200, 300].map((id) => [String(id), makeItem(id)])),
        alternatives: {
            '200': [{ recipeId: 2, yield: 2 }],
        },
    });

    const plan = calculatePlan(graph, {
        quantity: 1,
        targetItemKey: '100',
        choices: { '200': 0 },
        inventory: {},
    });

    assert.equal(plan.totalCombines, 1);
    assert.equal(findBom(plan, 200).remaining, 5);
    assert.equal(findBom(plan, 300), undefined);
});

test('cycles fall back to acquisition instead of recursing forever', () => {
    const graph = makeGraph({
        recipes: {
            '1': makeRecipe(1, {
                products: [{ itemKey: '100', count: 1 }],
                components: [{ itemKey: '200', count: 1, returnedOnSuccess: 0 }],
            }),
            '2': makeRecipe(2, {
                products: [{ itemKey: '200', count: 1 }],
                components: [{ itemKey: '300', count: 1, returnedOnSuccess: 0 }],
            }),
            '3': makeRecipe(3, {
                products: [{ itemKey: '300', count: 1 }],
                components: [{ itemKey: '200', count: 1, returnedOnSuccess: 0 }],
            }),
        },
        items: Object.fromEntries([100, 200, 300].map((id) => [String(id), makeItem(id)])),
        alternatives: {
            '200': [{ recipeId: 2, yield: 1 }],
            '300': [{ recipeId: 3, yield: 1 }],
        },
    });

    const plan = calculatePlan(graph, {
        quantity: 1,
        targetItemKey: '100',
        choices: {},
        inventory: {},
    });

    assert.match(warningText(plan), /cycle/i);
    assert.ok(plan.bom.some((entry) => ['200', '300'].includes(entry.itemKey)));
    assert.ok(plan.totalCombines < 10);
    assert.equal(plan.overflowed, false);
});

test('a component returned on success is reused across repeated combines', () => {
    const graph = makeGraph({
        recipes: {
            '1': makeRecipe(1, {
                products: [{ itemKey: '100', count: 1 }],
                components: [{ itemKey: '200', count: 2, returnedOnSuccess: 2 }],
            }),
        },
        items: {
            '100': makeItem(100),
            '200': makeItem(200, 'Reusable Mold'),
        },
    });

    const plan = calculatePlan(graph, {
        quantity: 3,
        targetItemKey: '100',
        choices: {},
        inventory: {},
    });

    assert.equal(plan.totalCombines, 3);
    assert.equal(findBom(plan, 200).need, 2, 'the two returned molds are needed once, not per run');
});

test('reusable containers are needed once while consumed containers scale with runs', () => {
    const graph = makeGraph({
        recipes: {
            '1': makeRecipe(1, {
                products: [{ itemKey: '100', count: 1 }],
                containers: [
                    { itemKey: '900', name: 'Reusable Kit', count: 1, consumed: false, worldObject: false },
                    { itemKey: '901', name: 'Consumed Mold', count: 1, consumed: true, worldObject: false },
                ],
            }),
        },
        items: {
            '100': makeItem(100),
            '900': makeItem(900, 'Reusable Kit'),
            '901': makeItem(901, 'Consumed Mold'),
        },
    });

    const plan = calculatePlan(graph, {
        quantity: 3,
        targetItemKey: '100',
        choices: {},
        inventory: {},
    });

    assert.equal(findBom(plan, 900).need, 1);
    assert.equal(findBom(plan, 901).need, 3);
});

test('uses the selected output yield for a multi-output recipe', () => {
    const graph = makeGraph({
        rootProducts: [
            { itemKey: '100', count: 2 },
            { itemKey: '101', count: 5 },
        ],
        recipes: {
            '1': makeRecipe(1, {
                products: [
                    { itemKey: '100', count: 2 },
                    { itemKey: '101', count: 5 },
                ],
                components: [{ itemKey: '200', count: 1, returnedOnSuccess: 0 }],
            }),
        },
        items: {
            '100': makeItem(100, 'Primary Output'),
            '101': makeItem(101, 'Second Output'),
            '200': makeItem(200, 'Material'),
        },
    });

    const primary = calculatePlan(graph, {
        quantity: 3,
        targetItemKey: '100',
        choices: {},
        inventory: {},
    });
    const secondary = calculatePlan(graph, {
        quantity: 6,
        targetItemKey: '101',
        choices: {},
        inventory: {},
    });

    assert.equal(primary.totalCombines, 2);
    assert.equal(findBom(primary, 200).need, 2);
    assert.equal(secondary.totalCombines, 2);
    assert.equal(findBom(secondary, 200).need, 2);
});

test('marks a plan overflow instead of allowing unbounded quantity growth', () => {
    const graph = makeGraph({
        recipes: {
            '1': makeRecipe(1, {
                products: [{ itemKey: '100', count: 1 }],
                components: [{ itemKey: '200', count: 6, returnedOnSuccess: 0 }],
            }),
        },
        items: {
            '100': makeItem(100),
            '200': makeItem(200),
        },
        limits: { maxCalculatedQuantity: 10 },
    });

    const plan = calculatePlan(graph, {
        quantity: 2,
        targetItemKey: '100',
        choices: {},
        inventory: {},
    });

    assert.equal(plan.overflowed, true);
    assert.match(warningText(plan), /limit|large|overflow/i);
});

test('allows aggregate quantities exactly equal to the configured calculation limit', () => {
    const graph = makeGraph({
        recipes: {
            '1': makeRecipe(1, {
                products: [{ itemKey: '100', count: 1 }],
                components: [{ itemKey: '200', count: 10, returnedOnSuccess: 0 }],
            }),
        },
        items: {
            '100': makeItem(100),
            '200': makeItem(200),
        },
        limits: { maxCalculatedQuantity: 10 },
    });

    const plan = calculatePlan(graph, {
        quantity: 1,
        targetItemKey: '100',
        choices: {},
        inventory: {},
    });

    assert.equal(plan.overflowed, false);
    assert.equal(findBom(plan, 200).need, 10);
});

test('uses the shallowest path for shared recipes and expands their tree requirements', () => {
    const graph = makeGraph({
        recipes: {
            '1': makeRecipe(1, {
                products: [{ itemKey: '100', count: 1 }],
                components: [
                    { itemKey: '200', count: 1, returnedOnSuccess: 0 },
                    { itemKey: '300', count: 1, returnedOnSuccess: 0 },
                ],
            }),
            '2': makeRecipe(2, {
                products: [{ itemKey: '200', count: 1 }],
                components: [{ itemKey: '400', count: 1, returnedOnSuccess: 0 }],
            }),
            '3': makeRecipe(3, {
                products: [{ itemKey: '400', count: 1 }],
                components: [{ itemKey: '300', count: 1, returnedOnSuccess: 0 }],
            }),
            '4': makeRecipe(4, {
                products: [{ itemKey: '300', count: 1 }],
                components: [{ itemKey: '500', count: 1, returnedOnSuccess: 0 }],
            }),
            '5': makeRecipe(5, {
                products: [{ itemKey: '500', count: 1 }],
                components: [{ itemKey: '600', count: 1, returnedOnSuccess: 0 }],
            }),
        },
        items: Object.fromEntries([100, 200, 300, 400, 500, 600].map((id) => [String(id), makeItem(id)])),
        alternatives: {
            '200': [{ recipeId: 2, yield: 1 }],
            '300': [{ recipeId: 4, yield: 1 }],
            '400': [{ recipeId: 3, yield: 1 }],
            '500': [{ recipeId: 5, yield: 1 }],
        },
        limits: { maxDepth: 3 },
    });

    const plan = calculatePlan(graph, {
        quantity: 1,
        targetItemKey: '100',
        choices: {},
        inventory: {},
    });
    const treeRows = flattenPlanTree(plan.tree);

    assert.equal(plan.forcedAcquire.includes('500'), false);
    assert.equal(plan.recipeRuns['5'], 2);
    assert.equal(findBom(plan, 600).need, 2);
    assert.ok(treeRows.some((row) => row.type === 'recipe' && row.recipeId === 5));
    assert.ok(treeRows.some((row) => row.type === 'item' && row.itemKey === '600'));
});

test('ignores inherited storage keys and rejects backslash-relative URLs', () => {
    const graph = makeGraph({
        recipes: {
            '1': makeRecipe(1, {
                products: [{ itemKey: '100', count: 1 }],
                components: [{ itemKey: '200', count: 1, returnedOnSuccess: 0 }],
            }),
        },
        items: {
            '100': makeItem(100),
            '200': makeItem(200),
        },
    });

    assert.doesNotThrow(() => calculatePlan(graph, {
        quantity: 1,
        targetItemKey: '100',
        choices: { toString: 2 },
        inventory: { constructor: 5 },
    }));

    const normalized = normalizeGraph({
        ...graph,
        items: {
            ...graph.items,
            '200': { ...graph.items['200'], url: '/\\evil.example/path' },
        },
    });
    assert.equal(normalized.items['200'].url, '');
});

test('share state round-trips only bounded, non-private choices', () => {
    const graph = makeGraph({
        recipes: {
            '1': makeRecipe(1, { products: [{ itemKey: '100', count: 1 }] }),
            '2': makeRecipe(2, { products: [{ itemKey: '200', count: 1 }] }),
            '3': makeRecipe(3, { products: [{ itemKey: '300', count: 1 }] }),
        },
        items: {
            '100': makeItem(100),
            '200': makeItem(200),
            '300': makeItem(300),
        },
        alternatives: {
            '200': [{ recipeId: 2, yield: 1 }],
            '300': [{ recipeId: 3, yield: 1 }],
        },
    });

    const encoded = encodeShareState({
        quantity: 7,
        targetItemKey: '100',
        choices: { '200': 2, '300': 0 },
        inventory: { '400': 99 },
        completed: ['recipe:1'],
    }, graph);
    const decoded = decodeShareState(encoded, graph);

    assert.ok(typeof encoded === 'string' && encoded.length > 0);
    assert.equal(decoded.v, 1);
    assert.equal(decoded.q, 7);
    assert.equal(decoded.target, '100');
    assert.deepEqual({ ...decoded.choices }, { '200': 2, '300': 0 });
    assert.equal(Object.hasOwn(decoded, 'inventory'), false);
    assert.equal(Object.hasOwn(decoded, 'completed'), false);
});

test('share decoder rejects tampered, unsupported, and oversized payloads', () => {
    const limits = { ...LIMITS, maxShareLength: 64 };
    const unsupported = Buffer.from(JSON.stringify({ v: 99, q: 1, target: '100', choices: {} }))
        .toString('base64url');

    assert.equal(decodeShareState('%%%not-base64%%%', limits), null);
    assert.equal(decodeShareState(unsupported, limits), null);
    assert.equal(decodeShareState('x'.repeat(65), limits), null);
});

function savedPlan(id, overrides = {}) {
    return {
        id,
        recipeId: 1,
        recipeName: 'Final Combine',
        targetItemKey: '100',
        quantity: 2,
        choices: {},
        inventory: {},
        completed: [],
        createdAt: '2026-08-22T12:00:00.000Z',
        updatedAt: '2026-08-22T12:00:00.000Z',
        ...overrides,
    };
}

test('saved-plan normalization rejects corrupt envelopes and enforces the configured cap', () => {
    assert.deepEqual(normalizeSavedPlans(null, LIMITS), []);
    assert.deepEqual(normalizeSavedPlans('not an envelope', LIMITS), []);
    assert.deepEqual(normalizeSavedPlans({ version: 99, plans: [savedPlan('bad')] }, LIMITS), []);

    const normalized = normalizeSavedPlans({
        version: 1,
        plans: [savedPlan('one'), savedPlan('two'), savedPlan('three')],
    }, { ...LIMITS, maxSavedPlans: 2 });

    assert.equal(normalized.length, 2);
    assert.ok(normalized.every((plan) => plan.quantity >= 1 && plan.quantity <= LIMITS.maxQuantity));
});

test('saved-plan loading fails closed for corrupt JSON and unavailable storage', () => {
    const corrupt = loadSavedPlans({
        getItem() {
            return '{ definitely not json';
        },
    }, LIMITS);
    const unavailable = loadSavedPlans({
        getItem() {
            throw new Error('storage denied');
        },
    }, LIMITS);

    assert.deepEqual(corrupt.plans, []);
    assert.ok(corrupt.error);
    assert.deepEqual(unavailable.plans, []);
    assert.ok(unavailable.error);
});
