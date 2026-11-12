import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

/** Query-string contract shared with App\Support\Http\TableQuery. */
export type ServerTableState = {
    page: number;
    perPage: number;
    /** `name` ascending, `-name` descending, or null for the server default. */
    sort: string | null;
    q: string;
    filters: Record<string, string>;
};

export const DEFAULT_TABLE_STATE: ServerTableState = {
    page: 1,
    perPage: 25,
    sort: null,
    q: '',
    filters: {},
};

export function parseTableQuery(
    search: string,
    defaults: ServerTableState = DEFAULT_TABLE_STATE,
): ServerTableState {
    const params = new URLSearchParams(search);
    const filters: Record<string, string> = {};
    for (const [key, value] of params) {
        const match = /^filter\[([^\]]+)\]$/.exec(key);
        if (match?.[1] && value !== '') {
            filters[match[1]] = value;
        }
    }
    const int = (name: string, fallback: number) => {
        const n = Number.parseInt(params.get(name) ?? '', 10);

        return Number.isFinite(n) && n > 0 ? n : fallback;
    };

    return {
        page: int('page', defaults.page),
        perPage: int('per_page', defaults.perPage),
        sort: params.get('sort') || defaults.sort,
        q: params.get('q') ?? defaults.q,
        filters,
    };
}

/** Only non-default values are sent, so URLs stay short and shareable. */
export function buildTableQuery(
    state: ServerTableState,
    defaults: ServerTableState = DEFAULT_TABLE_STATE,
): Record<string, string | number> {
    const query: Record<string, string | number> = {};
    if (state.page > 1) {
        query['page'] = state.page;
    }
    if (state.perPage !== defaults.perPage) {
        query['per_page'] = state.perPage;
    }
    if (state.sort && state.sort !== defaults.sort) {
        query['sort'] = state.sort;
    }
    if (state.q !== '') {
        query['q'] = state.q;
    }
    for (const [key, value] of Object.entries(state.filters)) {
        if (value !== '') {
            query[`filter[${key}]`] = value;
        }
    }

    return query;
}

/** asc → desc → default. */
export function nextSort(
    current: string | null,
    column: string,
): string | null {
    if (current === column) {
        return `-${column}`;
    }

    return current === `-${column}` ? null : column;
}

type Options = {
    /** Inertia props to reload (partial reload); omit to reload all. */
    only?: string[];
    defaults?: ServerTableState;
    searchDebounceMs?: number;
};

/**
 * URL-driven table state: every change is an Inertia partial reload (`preserveState`, `replace`),
 * so back/forward and shared links work and nothing is fetched on the client by hand.
 */
export function useServerTable({
    only,
    defaults = DEFAULT_TABLE_STATE,
    searchDebounceMs = 400,
}: Options = {}) {
    const url = usePage().url;
    const search = url.includes('?') ? url.slice(url.indexOf('?')) : '';
    const state = parseTableQuery(search, defaults);
    const [loading, setLoading] = useState(false);
    const [searchText, setSearchText] = useState(state.q);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const visit = useCallback(
        (next: ServerTableState) => {
            const path = url.split('?')[0] ?? url;
            router.get(path, buildTableQuery(next, defaults), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                ...(only ? { only } : {}),
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            });
        },
        [url, defaults, only],
    );

    useEffect(
        () => () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        },
        [],
    );

    return {
        state,
        loading,
        searchText,
        setPage: (page: number) => visit({ ...state, page: Math.max(1, page) }),
        setPerPage: (perPage: number) => visit({ ...state, perPage, page: 1 }),
        toggleSort: (column: string) =>
            visit({ ...state, sort: nextSort(state.sort, column), page: 1 }),
        setFilter: (key: string, value: string) =>
            visit({
                ...state,
                filters: { ...state.filters, [key]: value },
                page: 1,
            }),
        setSearch: (text: string) => {
            setSearchText(text);
            if (timer.current) {
                clearTimeout(timer.current);
            }
            const q = text.trim();
            timer.current = setTimeout(
                () => visit({ ...state, q, page: 1 }),
                searchDebounceMs,
            );
        },
    };
}
