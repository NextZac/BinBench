<template>
    <div ref="el" class="h-full w-full" />
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Plotly from 'plotly.js-dist-min';
import { readAxis, readGrid } from '../lib/binary';
import { useEditor } from '../stores/editor';

const props = defineProps({ map: { type: Object, required: true } });
const ed = useEditor();
const el = ref(null);
let resizeObs = null;

const colorscale = [[0, '#2563eb'], [0.25, '#06b6d4'], [0.5, '#22c55e'], [0.75, '#eab308'], [1, '#ef4444']];
const axisStyle = { gridcolor: '#2a3442', zerolinecolor: '#2a3442', color: '#8b98a8' };

function render() {
    if (!el.value) return;
    const m = props.map;
    const z = readGrid(ed.current, m);
    const zo = readGrid(ed.refBytes, m);
    const x = readAxis(ed.current, m.x, m.cols);
    const y = readAxis(ed.current, m.y, m.rows);
    const changed = JSON.stringify(z) !== JSON.stringify(zo);
    const layoutBase = {
        paper_bgcolor: 'rgba(0,0,0,0)',
        plot_bgcolor: 'rgba(0,0,0,0)',
        font: { color: '#e6edf3', size: 11 },
        hoverlabel: { bgcolor: '#1c2430', bordercolor: '#2a3442', font: { color: '#e6edf3', size: 11 } },
        margin: { l: 50, r: 20, t: 20, b: 40 },
        showlegend: changed,
        legend: { x: 0, y: 1 },
    };

    if (m.rows > 1 && m.cols > 1) {
        const traces = [{
            type: 'surface', x, y, z, colorscale, name: 'Modified', showscale: false,
            contours: { z: { show: true, usecolormap: true, project: { z: false } } },
        }];
        if (changed) {
            traces.push({ type: 'surface', x, y, z: zo, opacity: 0.35, colorscale: [[0, '#94a3b8'], [1, '#94a3b8']], showscale: false, name: ed.refLabel });
        }
        Plotly.react(el.value, traces, {
            ...layoutBase,
            margin: { l: 0, r: 0, t: 0, b: 0 },
            scene: {
                xaxis: { ...axisStyle, title: { text: m.x?.unit || 'X' } },
                yaxis: { ...axisStyle, title: { text: m.y?.unit || 'Y' } },
                zaxis: { ...axisStyle, title: { text: m.unit || 'Z' } },
                camera: { eye: { x: -1.6, y: -1.6, z: 1.0 } },
            },
        }, { displaylogo: false, responsive: true });
        return;
    }

    // curve: one line per row (or the single column)
    const series = m.cols === 1 ? [{ xs: y, ys: z.map((r) => r[0]), os: zo.map((r) => r[0]) }] : z.map((row, i) => ({ xs: x, ys: row, os: zo[i] }));
    const traces = series.flatMap((s, i) => [
        { type: 'scatter', mode: 'lines+markers', x: s.xs, y: s.ys, name: series.length > 1 ? `Row ${i}` : 'Modified', line: { color: '#f97316', width: 2 } },
        ...(changed ? [{ type: 'scatter', mode: 'lines', x: s.xs, y: s.os, name: ed.refLabel, line: { color: '#94a3b8', dash: 'dot' } }] : []),
    ]);
    Plotly.react(el.value, traces, {
        ...layoutBase,
        xaxis: { ...axisStyle, title: { text: (m.cols === 1 ? m.y?.unit : m.x?.unit) || '' } },
        yaxis: { ...axisStyle, title: { text: m.unit || '' } },
    }, { displaylogo: false, responsive: true });
}

watch(() => [props.map.id, ed.revision], render);
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
