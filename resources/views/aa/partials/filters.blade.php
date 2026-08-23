@php
    $classes = collect(config('everquest.classes_bit') ?? [])->sort()->toArray();
@endphp

<form id="aa-filters" method="GET" action="{{ route('aa.index') }}" class="flex gap-2 items-end"
    data-aa-base-url="{{ route('aa.index') }}" data-ability-selected="{{ isset($ability) ? '1' : '0' }}">
    <div class="w-72">
        <label class="label label-text">Ability</label>
        <select id="ability-filter" name="ability" class="select w-full">
            <option value="">Any</option>
            @foreach($allAbilities as $a)
                <option value="{{ $a->id }}"
                    @selected(
                        (string) request('ability') === (string) $a->id
                        || (!request()->has('ability') && isset($ability) && (string) ($ability->id ?? '') === (string) $a->id)
                    )
                >
                    {{ $a->name }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="w-48">
        <label class="label label-text">Classes</label>
        @php $selectedClass = (string) request('classes', ''); @endphp
        @if (isset($ability))
            <select id="aa-class-filter" name="classes" class="select w-full">
        @else
            <select id="aa-class-filter" name="classes" class="select w-full">
        @endif
            <option value="" @selected($selectedClass === '')>Any</option>
            @foreach($classes as $bit => $label)
                <option value="{{ $bit }}" @selected((string)$bit === $selectedClass)>
                    {{ $label }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="flex gap-2">
        @if (request()->hasAny(['ability', 'classes']))
            <a href="{{ url()->current() }}" class="btn btn-soft btn-error">Reset</a>
        @endif
    </div>
</form>
