import './bootstrap';
import Alpine from 'alpinejs'
import npcLocationMap from './components/npc-location-map';

const baseUrl = document.querySelector('base')?.getAttribute('href') || '/';

Alpine.data('npcLocationMap', npcLocationMap);

Alpine.data('eqsearch', (initialQuery = '') => ({
    query: initialQuery,
    results: [],
    loading: false,

    async load() {
        const cleanQuery = this.query.replace(/[^a-zA-Z0-9 -_.'`]/g, '');
        this.query = cleanQuery;

        if (cleanQuery.length < 2) {
            this.results = [];
            this.loading = false;
            return;
        }

        this.loading = true;

        try {
            const res = await fetch(`${baseUrl}search/suggest?q=${encodeURIComponent(cleanQuery)}`);
            const data = await res.json();
            this.results = data;
        } catch (e) {

        } finally {
            this.loading = false;
        }
    }
}));

Alpine.data('itemDrops', (itemId) => ({
    itemId,
    loading: true,
    drops: [],
    top_npcs: [],
    zoneList: [],
    selectedZone: '',
    async load() {
        this.loading = true;
        try {
            const res = await fetch(`${baseUrl}items/drops_by_zone/${this.itemId}`);
            const data = await res.json();
            this.drops = data.drops_by_zone;
            this.top_npcs = data.top_npcs;

            this.zoneList = data.drops_by_zone.map(z => ({
                key: `${z.zone}:${z.version}`,
                label: `${z.zone_name} (${z.zone})`,
            }));
        } catch (e) {
            console.error('error loading npc droppers:', e);
        } finally {
            this.loading = false;
        }
    },
    scrollToZone(event) {
        if (event && event.preventDefault) event.preventDefault();
        if (!this.selectedZone) return;

        const targetId = this.selectedZone === 'zone-top-npcs' ? 'zone-top-npcs' : `zone-${this.selectedZone}`;
        const container = document.querySelector('.drops-by-zone');
        const el = document.getElementById(targetId);

        if (container && el) {
            container.scrollTo({
                top: el.offsetTop - container.offsetTop - 5,
                behavior: 'smooth',
            });
        }
    }
}));

Alpine.data('spellLevelSticky', () => ({
    show: true,
    init() {
        const sentinel = document.getElementById('extra');
        if (!sentinel) return;

        const observer = new IntersectionObserver(([entry]) => {
            const sentinelAboveViewport = entry.boundingClientRect.top < 0;
            this.show = !sentinelAboveViewport;
        }, {
            root: null,
            threshold: 0.01,
        });

        observer.observe(sentinel);
    }
}));

Alpine.store('otherSpells', {
    page: 1,
    spells: '',
    exclude: '',
    load(excludeIds) {
        this.exclude = excludeIds;
        this.loadMore(1);
    },
    loadMore(page = 1) {
        this.page = page;
        let params = new URLSearchParams(window.location.search);
        params.set('page', page);
        params.set('exclude', this.exclude);

        return fetch(`${baseUrl}spells/other?${params.toString()}`)
            .then(res => res.text())
            .then(html => {
                this.spells = html;
            });
    }
});

Alpine.store('itemSearch', {
    open: false,
    toggle() {
        this.open = !this.open;
        localStorage.setItem('item_search', this.open);
    },
    init() {
        this.open = localStorage.getItem('item_search') === 'true';
    }
});

Alpine.store('tooltip', {
    content: '',
    visible: false,
    cache: new Map(),
    tooltipEl: null,

    async loadTooltip(url, triggerEl, event) {
        if (!triggerEl) return;
        if (event && event.preventDefault) event.preventDefault();

        this.loadingUrl = url;
        this.tooltipEl = document.getElementById('global-tooltip');

        const effectsOnly = triggerEl.dataset.effectsOnly === '1';
        if (effectsOnly) {
            url += '?effects-only=1';
        }

        if (this.cache.has(url)) {
            this.content = this.cache.get(url);
            this.loadingUrl = null;
        } else {
            try {
                const response = await fetch(url);
                const data = await response.json();
                this.cache.set(url, data.html);
                this.content = data.html;
            } catch (err) {
                this.content = '<div class="text-error">Failed to load tooltip.</div>';
            }
            this.loadingUrl = null;
        }

        this.visible = true;

        requestAnimationFrame(() => {
            this.positionTooltip(event, triggerEl);
        });
    },

    hideTooltip() {
        this.visible = false;
    },

    positionTooltip(e, triggerEl) {
        const tooltip = this.tooltipEl;
        if (!tooltip || !triggerEl) return;

        tooltip.style.visibility = 'hidden';
        tooltip.style.display = 'block';

        const tooltipHeight = tooltip.offsetHeight;
        const tooltipWidth = tooltip.offsetWidth;
        const rect = triggerEl.getBoundingClientRect();
        const scrollX = window.scrollX;
        const scrollY = window.scrollY;

        let top = rect.top + rect.height / 2 - tooltipHeight / 2 + scrollY;
        let left;

        const spaceRight = window.innerWidth - (rect.right + 10);
        const spaceLeft = rect.left - 10;

        if (spaceRight >= tooltipWidth) {
            left = rect.right + 10 + scrollX;
        } else if (spaceLeft >= tooltipWidth) {
            left = rect.left - tooltipWidth - 10 + scrollX;
        } else {
            left = scrollX + rect.left + (rect.width / 2) - (tooltipWidth / 2);
        }

        // Vertical bounds
        const maxBottom = scrollY + window.innerHeight - 10;
        if (top + tooltipHeight > maxBottom) {
            top = maxBottom - tooltipHeight;
        }
        if (top < scrollY + 10) {
            top = scrollY + 10;
        }

        tooltip.style.left = `${left}px`;
        tooltip.style.top = `${top}px`;
        tooltip.style.visibility = 'visible';
    }
});

window.Alpine = Alpine
Alpine.start()

document.addEventListener("DOMContentLoaded", function () {
    const logo = document.getElementById('eqemu-desktop-logo');
    const navbartrigger = document.getElementById('navbar-trigger');

    const observer = new IntersectionObserver(([entry]) => {
        if (entry.intersectionRatio === 0) {
            logo.classList.add('stuck');
        } else {
            logo.classList.remove('stuck');
        }
    });

    observer.observe(navbartrigger);

    // faction select
    const select = document.getElementById('select-faction');
    if (select) {
        select.addEventListener('change', (e) => {
            const value = e.target.value;
            if (value) {
                window.location.href = `${baseUrl}factions/${value}`;
            }
        });
    }

    // pet class select
    const petSelect = document.getElementById('select-pet-class');
    if (petSelect) {
        petSelect.addEventListener('change', (e) => {
            const value = e.target.value;
            if (value) {
                window.location.href = `${baseUrl}pets/${value}`;
            }
        });
    }

    // populate task reward column with something at least
    const rewardCol = document.querySelectorAll('td.task-rewards');
    rewardCol.forEach(function (reward) {
        if (reward.textContent.trim() === '') {
            reward.textContent = '-';
        }
    });
});

document.body.addEventListener('click', () => {
    Alpine.store('tooltip').hideTooltip();
});
