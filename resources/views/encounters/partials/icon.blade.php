<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="{{ $class ?? 'h-5 w-5' }}">
    @if (($name ?? 'book') === 'shield')
        <path d="M12 3 4 6v6c0 4 5 8 8 9 3-1 8-5 8-9V6l-8-3Z" /><path d="m9 12 2 2 4-4" />
    @elseif (($name ?? 'book') === 'lock')
        <rect x="5" y="10" width="14" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3" />
    @elseif (($name ?? 'book') === 'arrow')
        <path d="M5 12h14m-6-6 6 6-6 6" />
    @elseif (($name ?? 'book') === 'spark')
        <path d="m12 3 2.8 6.2L21 12l-6.2 2.8L12 21l-2.8-6.2L3 12l6.2-2.8L12 3Z" />
    @else
        <path d="M12 5v16m0-16C9 3 5 3 2 4v15c3-1 7-1 10 2 3-3 7-3 10-2V4c-3-1-7-1-10 1Z" />
        <path d="M6 8h2m-2 4h2m8-4h2m-2 4h2" />
    @endif
</svg>
