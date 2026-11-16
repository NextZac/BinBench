import { ref } from 'vue';

// Light/dark theme: the user's choice is remembered per browser; otherwise follow the OS.
const KEY = 'binbench.theme';

function stored() {
    try {
        return localStorage.getItem(KEY);
    } catch {
        return null;
    }
}

function preferred() {
    return window.matchMedia?.('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
}

export const theme = ref(stored() ?? preferred());

function apply(value) {
    document.documentElement.dataset.theme = value;
}

apply(theme.value);

export function toggleTheme() {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    apply(theme.value);
    try {
        localStorage.setItem(KEY, theme.value);
    } catch {
        // private mode / blocked storage: the choice just isn't remembered
    }
}

/** Current value of a theme token, e.g. css('--bb-ink'), for canvas/SVG charts. */
export function css(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

/** Plotly styling taken from the active theme; charts re-render when `theme` changes. */
export function chartTheme() {
    const ink = css('--bb-ink');
    const muted = css('--bb-muted');
    const line = css('--bb-line');
    return {
        ink,
        muted,
        accent: css('--bb-accent-fill'),
        mod: css('--bb-mod'),
        ref: css('--bb-ref'),
        font: { family: 'IBM Plex Sans, sans-serif', color: ink, size: 11 },
        hoverlabel: { bgcolor: css('--bb-panel'), bordercolor: css('--bb-line-strong'), font: { family: 'IBM Plex Mono, monospace', color: ink, size: 11 } },
        axis: { gridcolor: line, zerolinecolor: css('--bb-line-strong'), color: muted, linecolor: line },
        colorscale: [[0, '#3465a4'], [0.25, '#3e96a0'], [0.5, '#609c54'], [0.75, '#d6a834'], [1, '#ce4a34']],
        refSurface: [[0, muted], [1, muted]],
    };
}
