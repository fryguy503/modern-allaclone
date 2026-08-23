@extends('layouts.default')
@section('title', 'Tradeskill Plan - ' . ucRomanNumeral($recipe->name))

@section('content')
    <nav aria-label="Recipe planner navigation" class="mb-5">
        <div class="tabs tabs-border">
            <a href="{{ route('recipes.plan', $recipe) }}" aria-current="page"
                class="tab tab-active">Plan</a>
            <a href="{{ route('recipes.plans') }}" class="tab">Saved plans</a>
            <a href="{{ route('recipes.show', $recipe) }}" class="tab">Recipe details</a>
        </div>
    </nav>

    @if (!empty($plannerError) || empty($plannerData))
        <div class="alert alert-warning" role="alert">
            <div>
                <h2 class="font-semibold">This recipe could not be planned</h2>
                <p class="text-sm">
                    {{ $plannerError ?? 'The recipe graph is not available. Try again later or review the basic recipe details.' }}
                </p>
            </div>
        </div>
        <div class="mt-4">
            <a href="{{ route('recipes.show', $recipe) }}" class="btn btn-soft">Back to recipe details</a>
        </div>
    @else
        <section
            x-data="tradeskillPlanner(@js($plannerData), @js([
                'savedPlansUrl' => route('recipes.plans'),
                'planRouteTemplate' => route('recipes.plan', ['recipe' => '__RECIPE__']),
            ]))"
            aria-labelledby="tradeskill-planner-heading"
            class="space-y-4"
            x-cloak>

            <div class="card bg-base-300 shadow-sm overflow-hidden">
                <div class="card-body gap-5 p-5 md:p-6">
                    <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wider text-accent">Recursive tradeskill planner</p>
                            <h2 id="tradeskill-planner-heading" class="mt-1 text-2xl font-semibold text-base-content"
                                x-text="graph.rootName"></h2>
                            <p class="mt-1 max-w-2xl text-sm text-base-content/65">
                                Shared ingredients are combined once, inventory is deducted globally, and prerequisites are
                                ordered before the final combine.
                            </p>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end xl:justify-end">
                            <label class="form-control" x-show="graph.rootProducts.length > 1">
                                <span class="label pb-1"><span class="label-text text-xs">Target output</span></span>
                                <select class="select select-bordered select-sm min-w-52"
                                    :value="targetItemKey" @change="changeTarget($event.target.value)">
                                    <template x-for="product in graph.rootProducts" :key="product.itemKey">
                                        <option :value="product.itemKey"
                                            :selected="targetItemKey === product.itemKey"
                                            x-text="`${graph.items[product.itemKey]?.name || graph.rootName} (yields ${product.count})`"></option>
                                    </template>
                                </select>
                            </label>

                            <div class="form-control">
                                <label for="tradeskill-plan-quantity" class="label pb-1">
                                    <span class="label-text text-xs">Target quantity</span>
                                </label>
                                <div class="join">
                                    <button type="button" class="btn btn-sm join-item" @click="adjustQuantity(-1)"
                                        :disabled="quantity <= 1" aria-label="Decrease target quantity">&minus;</button>
                                    <input id="tradeskill-plan-quantity" type="number" min="1"
                                        :max="graph.limits.maxQuantity" inputmode="numeric"
                                        class="input input-bordered input-sm join-item w-20 text-center tabular-nums"
                                        :value="quantity" @change="changeQuantity($event.target.value)"
                                        @keydown.enter.prevent="changeQuantity($event.target.value)">
                                    <button type="button" class="btn btn-sm join-item" @click="adjustQuantity(1)"
                                        :disabled="quantity >= graph.limits.maxQuantity"
                                        aria-label="Increase target quantity">+</button>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="btn btn-sm btn-primary" @click="savePlan()">
                                    <span x-text="saved ? 'Save changes' : 'Save to browser'"></span>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline" @click="copyPlanLink()">
                                    Copy plan link
                                </button>
                            </div>
                        </div>
                    </div>

                    <p class="text-xs text-base-content/55">
                        Browser saves stay on this device. Shared links contain only the target and recipe choices&mdash;never
                        inventory or checklist progress.
                    </p>
                </div>

                <div class="grid grid-cols-2 border-t border-base-content/10 bg-base-100/45 md:grid-cols-4">
                    <div class="border-b border-r border-base-content/10 p-4 md:border-b-0">
                        <span class="block text-xs uppercase tracking-wide text-base-content/55">Target units</span>
                        <strong class="mt-1 block text-lg tabular-nums" x-text="plan.quantity"></strong>
                    </div>
                    <div class="border-b border-base-content/10 p-4 md:border-b-0 md:border-r">
                        <span class="block text-xs uppercase tracking-wide text-base-content/55">Total combines</span>
                        <strong class="mt-1 block text-lg tabular-nums" x-text="plan.totalCombines"></strong>
                    </div>
                    <div class="border-r border-base-content/10 p-4">
                        <span class="block text-xs uppercase tracking-wide text-base-content/55">Raw units remaining</span>
                        <strong class="mt-1 block text-lg tabular-nums" x-text="plan.rawItemCount"></strong>
                    </div>
                    <div class="p-4">
                        <div class="flex items-center justify-between gap-2 text-xs text-base-content/55">
                            <span>Checklist</span>
                            <span class="tabular-nums" x-text="`${completedCount()} / ${plan.checklist.length}`"></span>
                        </div>
                        <progress class="progress progress-success mt-2 w-full" value="0" max="100"
                            x-effect="$el.setAttribute('value', String(progressPercent()))"
                            aria-label="Checklist progress"></progress>
                    </div>
                </div>
            </div>

            <div x-show="status" role="status" aria-live="polite" class="alert"
                :class="{
                    'alert-error': statusType === 'error',
                    'alert-success': statusType === 'success',
                    'alert-info': statusType === 'info'
                }">
                <span x-text="status"></span>
            </div>

            <div x-show="plan.warnings.length" class="space-y-2" aria-label="Planner warnings">
                <template x-for="warning in plan.warnings" :key="`${warning.code}:${warning.itemKey || ''}:${warning.recipeId || ''}`">
                    <div class="alert alert-warning py-3" role="alert">
                        <span class="text-sm" x-text="warning.message"></span>
                    </div>
                </template>
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(18rem,0.9fr)_minmax(32rem,1.35fr)]">
                <section class="card bg-base-100 shadow-sm" aria-labelledby="dependency-tree-heading">
                    <div class="card-body p-4 md:p-5">
                        <div class="flex items-baseline justify-between gap-3">
                            <h3 id="dependency-tree-heading" class="card-title text-lg">Dependency tree</h3>
                            <span class="text-xs text-base-content/50">Select a row to collapse</span>
                        </div>

                        <div class="mt-2 min-w-0" role="list" aria-label="Recipe dependencies">
                            <template x-for="row in visibleTreeRows()" :key="row.key">
                                <div role="listitem" class="planner-tree-row grid min-h-10 grid-cols-[1.75rem_minmax(0,1fr)_auto] items-center gap-1 border-b border-base-content/5 py-1.5"
                                    :style="`padding-inline-start: ${Math.min(row.depth, 12) * 1.05}rem`">
                                    <button x-show="row.hasChildren" type="button" class="btn btn-ghost btn-xs px-1"
                                        @click="toggleBranch(row.key)" :aria-expanded="String(!collapsed[row.key])"
                                        :aria-label="`${collapsed[row.key] ? 'Expand' : 'Collapse'} ${row.name}`">
                                        <span aria-hidden="true" class="transition-transform"
                                            :class="collapsed[row.key] ? '-rotate-90' : ''">&#9662;</span>
                                    </button>
                                    <span x-show="!row.hasChildren" aria-hidden="true" class="text-center text-base-content/35">&middot;</span>

                                    <div class="min-w-0">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <span class="badge badge-xs shrink-0"
                                                :class="row.type === 'recipe' ? 'badge-secondary' : 'badge-info'"
                                                x-text="row.type === 'recipe' ? 'Combine' : (row.role === 'container' ? 'Container' : 'Item')"></span>
                                            <a x-show="row.url" :href="row.url" class="link link-info min-w-0 truncate font-medium"
                                                x-text="row.name"></a>
                                            <span x-show="!row.url" class="min-w-0 truncate font-medium" x-text="row.name"></span>
                                        </div>
                                        <p class="truncate text-xs text-base-content/50" x-text="row.meta"></p>
                                    </div>

                                    <strong class="whitespace-nowrap text-sm tabular-nums text-accent"
                                        x-text="row.type === 'recipe' ? `${row.quantity}x` : `×${row.quantity}`"></strong>
                                </div>
                            </template>
                        </div>
                    </div>
                </section>

                <section class="card min-w-0 bg-base-100 shadow-sm" aria-labelledby="bill-of-materials-heading">
                    <div class="card-body min-w-0 p-4 md:p-5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 id="bill-of-materials-heading" class="card-title text-lg">Bill of materials</h3>
                                <p class="text-xs text-base-content/50">Edit Owned quantities to deduct inventory across the entire plan.</p>
                            </div>
                            <button type="button" class="btn btn-ghost btn-xs" @click="clearInventory()"
                                :disabled="Object.keys(inventory).length === 0">Clear inventory</button>
                        </div>

                        <div class="flex flex-wrap gap-2" aria-label="Filter bill of materials">
                            <button type="button" class="btn btn-xs" @click="bomFilter = 'all'"
                                :class="bomFilter === 'all' ? 'btn-primary' : 'btn-soft'"
                                :aria-pressed="String(bomFilter === 'all')">All <span x-text="plan.bom.length"></span></button>
                            <button type="button" class="btn btn-xs" @click="bomFilter = 'acquire'"
                                :class="bomFilter === 'acquire' ? 'btn-primary' : 'btn-soft'"
                                :aria-pressed="String(bomFilter === 'acquire')">Acquire <span x-text="filterCount('acquire')"></span></button>
                            <button type="button" class="btn btn-xs" @click="bomFilter = 'craft'"
                                :class="bomFilter === 'craft' ? 'btn-primary' : 'btn-soft'"
                                :aria-pressed="String(bomFilter === 'craft')">Subcombine <span x-text="filterCount('craft')"></span></button>
                            <button type="button" class="btn btn-xs" @click="bomFilter = 'inventory'"
                                :class="bomFilter === 'inventory' ? 'btn-primary' : 'btn-soft'"
                                :aria-pressed="String(bomFilter === 'inventory')">Covered <span x-text="filterCount('inventory')"></span></button>
                        </div>

                        <div class="overflow-x-auto rounded-box border border-base-content/10">
                            <table class="table table-sm min-w-[48rem]">
                                <caption class="sr-only">Materials, required quantity, inventory, remaining quantity, and sources</caption>
                                <thead class="bg-base-300 text-xs uppercase">
                                    <tr>
                                        <th scope="col">Material</th>
                                        <th scope="col" class="text-right">Need</th>
                                        <th scope="col" class="w-24 text-right">Owned</th>
                                        <th scope="col" class="text-right">Remaining</th>
                                        <th scope="col" class="w-[42%]">Source or recipe</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="material in filteredBom()" :key="material.itemKey">
                                        <tr>
                                            <th scope="row" class="font-normal">
                                                <div class="flex min-w-44 items-center gap-2">
                                                    <a x-show="material.url" :href="material.url" class="link link-info font-medium"
                                                        x-text="material.name"></a>
                                                    <span x-show="!material.url" class="font-medium" x-text="material.name"></span>
                                                    <span x-show="material.reusable > 0" class="badge badge-xs badge-outline">Reusable</span>
                                                </div>
                                            </th>
                                            <td class="text-right tabular-nums" x-text="material.need"></td>
                                            <td class="text-right">
                                                <input type="number" min="0" :max="graph.limits.maxInventoryPerItem"
                                                    inputmode="numeric" class="input input-bordered input-xs w-20 text-right tabular-nums"
                                                    x-model.lazy.number="inventory[material.itemKey]" placeholder="0"
                                                    :aria-label="`Owned quantity for ${material.name}`"
                                                    @change="changeInventory(material.itemKey, $event.target.value)"
                                                    :disabled="material.worldObject">
                                            </td>
                                            <td class="text-right font-semibold tabular-nums"
                                                :class="material.remaining === 0 ? 'text-success' : 'text-accent'"
                                                x-text="material.remaining"></td>
                                            <td>
                                                <div class="space-y-1.5">
                                                    <div class="flex items-start gap-2">
                                                        <span class="badge badge-xs mt-0.5"
                                                            :class="{
                                                                'badge-success': material.acquisition === 'inventory' || material.sourceKind === 'vendor',
                                                                'badge-secondary': material.acquisition === 'craft',
                                                                'badge-info': ['forage', 'fishing', 'drop', 'ground'].includes(material.sourceKind),
                                                                'badge-ghost': material.acquisition === 'world-object' || material.sourceKind === 'unknown'
                                                            }"
                                                            x-text="materialSourceLabel(material)"></span>
                                                        <span class="text-xs text-base-content/65" x-text="materialSource(material)"></span>
                                                    </div>

                                                    <div x-show="material.sources.length > 0 && material.acquisition !== 'craft'"
                                                        class="space-y-1 border-l border-base-content/10 pl-2">
                                                        <template x-for="(source, sourceIndex) in material.sources.slice(0, 3)"
                                                            :key="`${material.itemKey}:${source.kind}:${sourceIndex}`">
                                                            <div class="text-xs">
                                                                <a x-show="source.url" :href="source.url" class="link link-hover link-info"
                                                                    x-text="source.label"></a>
                                                                <span x-show="!source.url" x-text="source.label"></span>
                                                                <span x-show="source.details" class="text-base-content/50"
                                                                    x-text="` · ${source.details}`"></span>
                                                            </div>
                                                        </template>
                                                    </div>

                                                    <label x-show="hasAlternatives(material.itemKey)" class="block">
                                                        <span class="sr-only" x-text="`Recipe choice for ${material.name}`"></span>
                                                        <select class="select select-bordered select-xs w-full max-w-sm"
                                                            :value="choiceValue(material.itemKey)"
                                                            @change="changeChoice(material.itemKey, $event.target.value)"
                                                            :aria-label="`Recipe choice for ${material.name}`">
                                                            <template x-for="option in choiceOptions(material.itemKey)" :key="option.value">
                                                                <option :value="option.value"
                                                                    :selected="choiceValue(material.itemKey) === String(option.value)"
                                                                    x-text="option.label"></option>
                                                            </template>
                                                        </select>
                                                    </label>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr x-show="filteredBom().length === 0">
                                        <td colspan="5" class="py-8 text-center text-sm text-base-content/55">
                                            No materials match this filter.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>

            <section class="card bg-base-100 shadow-sm" aria-labelledby="planner-checklist-heading">
                <div class="card-body p-4 md:p-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 id="planner-checklist-heading" class="card-title text-lg">Ordered checklist</h3>
                            <p class="text-xs text-base-content/50">Acquisition first, deepest subcombines next, final combine last.</p>
                        </div>
                        <div class="text-sm tabular-nums text-base-content/60"
                            x-text="`${completedCount()} of ${plan.checklist.length} complete`"></div>
                    </div>

                    <div class="space-y-2">
                        <template x-for="(step, index) in plan.checklist" :key="step.id">
                            <label class="grid cursor-pointer grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-3 rounded-box border border-base-content/10 bg-base-200/40 p-3 transition-colors hover:bg-base-200">
                                <input type="checkbox" class="checkbox checkbox-success checkbox-sm mt-0.5"
                                    :checked="isCompleted(step.id)"
                                    @change="toggleCompleted(step.id, $event.target.checked)"
                                    :aria-label="`Mark ${step.label} complete`">
                                <span class="min-w-0">
                                    <span class="flex flex-wrap items-center gap-2 font-medium"
                                        :class="isCompleted(step.id) ? 'line-through text-base-content/45' : ''">
                                        <span x-text="step.label"></span>
                                        <span class="badge badge-xs badge-outline tabular-nums"
                                            x-text="`${step.quantity}×`"></span>
                                    </span>
                                    <span class="mt-0.5 block text-xs text-base-content/55" x-text="step.description"></span>
                                </span>
                                <span class="badge badge-sm whitespace-nowrap"
                                    :class="step.type === 'finish' ? 'badge-primary' : (step.type === 'craft' ? 'badge-secondary' : 'badge-info')"
                                    x-text="`${index + 1} · ${step.type === 'finish' ? 'Finish' : (step.type === 'craft' ? 'Craft' : 'Acquire')}`"></span>
                            </label>
                        </template>

                        <p x-show="plan.checklist.length === 0" class="rounded-box bg-base-200 p-5 text-center text-sm text-base-content/55">
                            Inventory already covers every prerequisite.
                        </p>
                    </div>
                </div>
            </section>
        </section>
    @endif
@endsection
