import { reactive, watch } from 'vue';

// Engine data and per-role map choices for the dyno and Stage 1, remembered per file in this browser.
export function useEngineInputs(fileId) {
    const key = `binbench.dyno.${fileId}`;
    const defaults = { displacement: null, cylinders: null, stockPower: null, roles: {} };
    let stored = {};
    try {
        stored = JSON.parse(localStorage.getItem(key) ?? '{}');
    } catch {
        // storage unavailable or corrupt: start empty
    }
    const engine = reactive({ ...defaults, ...stored, roles: { ...(stored.roles ?? {}) } });

    watch(engine, () => {
        try {
            localStorage.setItem(key, JSON.stringify(engine));
        } catch {
            // storage unavailable: inputs just won't be remembered
        }
    });

    const params = () => ({
        displacement: engine.displacement,
        cylinders: engine.cylinders,
        stock_power: engine.stockPower,
        ...Object.fromEntries(Object.entries(engine.roles).filter(([, id]) => id).map(([role, id]) => [`roles[${role}]`, id])),
    });

    return { engine, params };
}
