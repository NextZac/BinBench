<template>
    <div>
        <!-- peaks -->
        <div class="grid grid-cols-2 gap-2 px-3 pt-3 text-xs sm:grid-cols-4">
            <div v-for="card in cards" :key="card.label" class="rounded-md border border-line bg-panel-2 px-3 py-2">
                <div class="text-[11px] text-muted">{{ card.label }}</div>
                <div class="font-mono text-base font-semibold">{{ card.value }}</div>
                <div class="text-[11px]" :class="card.deltaClass">{{ card.detail }}</div>
            </div>
        </div>

        <div ref="el" class="h-[360px]" />

        <!-- active limiter per rpm -->
        <div class="px-3 pb-2 text-[11px]">
            <div class="mb-1 flex items-center justify-between text-muted">
                <span>What limits fuel{{ result.engine.fuel === 'petrol' ? ' / load' : '' }} ({{ result.modified ? modifiedLabel.toLowerCase() : 'original' }})</span>
                <span class="flex flex-wrap gap-2">
                    <span v-for="name in limiterNames" :key="name" class="flex items-center gap-1">
                        <span class="h-2 w-2 rounded-sm" :style="{ background: limiterColor(name) }" />{{ name }}
                    </span>
                </span>
            </div>
            <div class="flex h-2.5 overflow-hidden rounded">
                <div v-for="(s, i) in limiterSegments" :key="i" :style="{ flex: s.count, background: limiterColor(s.name) }"
                    :title="`${s.name}: ${s.from}–${s.to} rpm`" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Plotly from 'plotly.js-dist-min';

// result: a dyno estimate from the API (stock curve, optional modified curve).
const props = defineProps({
    result: { type: Object, required: true },
    modifiedLabel: { type: String, default: 'Modified' },
});
const el = ref(null);
let resizeObs = null;

const limiterColors = {
    "Driver's wish": '#64748b',
    'Torque limiter': '#06b6d4',
    'Smoke limiter': '#a855f7',
    'Max load (LDRXN)': '#eab308',
    'Torque request (KFMIRL)': '#22c55e',
};
const powerColor = '#f97316';
const torqueColor = '#06b6d4';
const axisStyle = { gridcolor: '#2a3442', zerolinecolor: '#2a3442', color: '#8b98a8' };

const shown = computed(() => props.result.modified ?? props.result.stock);
const limiterNames = computed(() => [...new Set(shown.value.limiter)]);
const limiterSegments = computed(() => {
    const c = shown.value;
    return c.limiter.reduce((segs, name, i) => {
        const last = segs[segs.length - 1];
        if (last?.name === name) {
            last.count++;
            last.to = c.rpm[i];
        } else {
            segs.push({ name, count: 1, from: c.rpm[i], to: c.rpm[i] });
        }
        return segs;
    }, []);
});
const cards = computed(() => {
    const { stock, modified } = props.result;
    const delta = (a, b) => (b - a >= 0 ? '+' : '') + Math.round(b - a);
    const out = [
        { label: 'Original power', value: `${Math.round(stock.peakPower.value)} PS`, detail: `@ ${stock.peakPower.rpm} rpm` },
        { label: 'Original torque', value: `${Math.round(stock.peakTorque.value)} Nm`, detail: `@ ${stock.peakTorque.rpm} rpm` },
    ];
    if (modified) {
        const dp = modified.peakPower.value - stock.peakPower.value;
        const dt = modified.peakTorque.value - stock.peakTorque.value;
        out.push(
            { label: `${props.modifiedLabel} power`, value: `${Math.round(modified.peakPower.value)} PS`, detail: `${delta(stock.peakPower.value, modified.peakPower.value)} PS @ ${modified.peakPower.rpm} rpm`, deltaClass: dp >= 0 ? 'text-ok' : 'text-bad' },
            { label: `${props.modifiedLabel} torque`, value: `${Math.round(modified.peakTorque.value)} Nm`, detail: `${delta(stock.peakTorque.value, modified.peakTorque.value)} Nm @ ${modified.peakTorque.rpm} rpm`, deltaClass: dt >= 0 ? 'text-ok' : 'text-bad' },
        );
    }
    return out.map((c) => ({ deltaClass: 'text-muted', ...c }));
});

function limiterColor(name) {
    return limiterColors[name] ?? '#8b98a8';
}

function render() {
    if (!el.value) return;
    const { stock, modified } = props.result;
    const label = props.modifiedLabel.toLowerCase();
    const line = (curve, key, name, color, dashed, yaxis) => ({
        type: 'scatter', mode: 'lines', x: curve.rpm, y: curve[key], name, yaxis,
        line: { color, width: dashed ? 1.5 : 2.5, dash: dashed ? 'dot' : 'solid', shape: 'spline' },
        hovertemplate: `%{y:.0f} ${key === 'power' ? 'PS' : 'Nm'}<extra>${name}</extra>`,
    });
    const traces = [
        line(stock, 'power', modified ? 'Power (original)' : 'Power', powerColor, !!modified, 'y'),
        line(stock, 'torque', modified ? 'Torque (original)' : 'Torque', torqueColor, !!modified, 'y2'),
    ];
    if (modified) {
        traces.push(line(modified, 'power', `Power (${label})`, powerColor, false, 'y'), line(modified, 'torque', `Torque (${label})`, torqueColor, false, 'y2'));
    }
    Plotly.react(el.value, traces, {
        paper_bgcolor: 'rgba(0,0,0,0)',
        plot_bgcolor: 'rgba(0,0,0,0)',
        font: { color: '#e6edf3', size: 11 },
        margin: { l: 55, r: 60, t: 50, b: 50 },
        hovermode: 'x unified',
        legend: { orientation: 'h', x: 0, y: 1, yanchor: 'bottom' },
        xaxis: { ...axisStyle, title: { text: 'Engine speed (rpm)' } },
        yaxis: { ...axisStyle, title: { text: 'Power (PS)', font: { color: powerColor } }, rangemode: 'tozero', tickformat: '.0f' },
        yaxis2: { ...axisStyle, title: { text: 'Torque (Nm)', font: { color: torqueColor } }, overlaying: 'y', side: 'right', rangemode: 'tozero', showgrid: false, tickformat: '.0f' },
    }, { displaylogo: false, responsive: true });
}

watch(() => props.result, render);
onMounted(() => {
    render();
    resizeObs = new ResizeObserver(() => el.value && Plotly.Plots.resize(el.value));
    resizeObs.observe(el.value);
});
onBeforeUnmount(() => {
    resizeObs?.disconnect();
    if (el.value) Plotly.purge(el.value);
});
</script>
