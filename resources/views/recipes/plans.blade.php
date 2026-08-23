@extends('layouts.default')
@section('title', 'Saved Tradeskill Plans')

@section('content')
    <nav aria-label="Recipe planner navigation" class="mb-5">
        <div class="tabs tabs-border">
            <a href="{{ route('recipes.index') }}" class="tab">Recipes</a>
            <a href="{{ route('recipes.plans') }}" aria-current="page"
                class="tab tab-active">Saved plans</a>
        </div>
    </nav>

    <section
        x-data="savedTradeskillPlans(@js([
            'limits' => $limits ?? [],
            'planRouteTemplate' => route('recipes.plan', ['recipe' => '__RECIPE__']),
        ]))"
        aria-labelledby="saved-tradeskill-plans-heading"
        class="space-y-4"
        x-cloak>

        <div class="card bg-base-300 shadow-sm">
            <div class="card-body p-5 md:p-6">
                <p class="text-xs font-semibold uppercase tracking-wider text-accent">Device-local workspace</p>
                <h2 id="saved-tradeskill-plans-heading" class="card-title mt-1 text-2xl">Saved tradeskill plans</h2>
                <p class="max-w-3xl text-sm text-base-content/65">
                    These plans are stored only in this browser. They do not require an account, do not sync to other devices,
                    and can disappear if browser data is cleared.
                </p>
                <div class="card-actions mt-2">
                    <a href="{{ route('recipes.index') }}" class="btn btn-sm btn-primary">Find a recipe to plan</a>
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

        <div x-show="error === 'corrupt'" class="alert alert-error" role="alert">
            <div class="grow">
                <h3 class="font-semibold">Saved plan data is unreadable</h3>
                <p class="text-sm">Resetting removes only the damaged planner data from this browser.</p>
            </div>
            <button type="button" class="btn btn-sm btn-error" @click="resetCorruptStorage()">
                Reset saved plans
            </button>
        </div>

        <div x-show="!error && plans.length === 0" class="card border border-dashed border-base-content/20 bg-base-100">
            <div class="card-body items-center py-12 text-center">
                <h3 class="card-title">No plans saved in this browser</h3>
                <p class="max-w-xl text-sm text-base-content/60">
                    Open a recipe, build its recursive plan, then choose Save to browser. A share link can be copied without
                    saving inventory or checklist progress.
                </p>
                <a href="{{ route('recipes.index') }}" class="btn btn-sm btn-primary mt-2">Browse recipes</a>
            </div>
        </div>

        <div x-show="!error && plans.length > 0" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <template x-for="plan in plans" :key="plan.id">
                <article class="card bg-base-100 shadow-sm">
                    <div class="card-body p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <h3 class="card-title truncate text-lg" x-text="plan.recipeName"></h3>
                                <p class="mt-1 text-xs text-base-content/50">
                                    Updated <time :datetime="plan.updatedAt" x-text="formatDate(plan.updatedAt)"></time>
                                </p>
                            </div>
                            <span class="badge badge-outline whitespace-nowrap tabular-nums"
                                x-text="`${plan.quantity}× target`"></span>
                        </div>

                        <dl class="mt-2 grid grid-cols-2 gap-3 rounded-box bg-base-200/60 p-3 text-sm">
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-base-content/50">Recipe choices</dt>
                                <dd class="mt-1 font-semibold tabular-nums" x-text="Object.keys(plan.choices).length"></dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-base-content/50">Inventory entries</dt>
                                <dd class="mt-1 font-semibold tabular-nums" x-text="Object.keys(plan.inventory).length"></dd>
                            </div>
                        </dl>

                        <div class="mt-1">
                            <div class="flex items-center justify-between gap-3 text-xs text-base-content/55">
                                <span>Checklist progress</span>
                                <span class="tabular-nums"
                                    x-text="plan.stepCount > 0 ? `${completedCount(plan)} of ${plan.stepCount}` : `${completedCount(plan)} complete`"></span>
                            </div>
                            <progress class="progress progress-success mt-2 w-full" value="0" max="100"
                                x-effect="$el.setAttribute('value', String(progressPercent(plan)))"
                                :aria-label="`Checklist progress for ${plan.recipeName}`"></progress>
                        </div>

                        <div class="card-actions mt-2 items-center justify-end gap-2">
                            <a :href="planUrl(plan)" class="btn btn-sm btn-primary">Open</a>
                            <button type="button" class="btn btn-sm btn-outline" @click="copyPlanLink(plan)">
                                Copy link
                            </button>
                            <button type="button" class="btn btn-sm btn-ghost text-error"
                                @click="if (window.confirm('Remove this saved plan from this browser?')) removePlan(plan.id)">
                                Remove
                            </button>
                        </div>
                    </div>
                </article>
            </template>
        </div>
    </section>
@endsection
