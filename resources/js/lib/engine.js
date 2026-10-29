import { reactive, watch } from 'vue';

// Engine data for the dyno estimate, remembered per file in this browser.
export function useEngineInputs(fileId) {
    const key = `binbench.dyno.${fileId}`;
    const defaults = { displacement: null, cylinders: null, stockPower: null };
    let stored = {};
    try {
        stored = JSON.parse(localStorage.getItem(key) ?? '{}');
    } catch {
        // storage unavailable or corrupt: start empty
    }
    const engine = reactive({ ...defaults, ...stored });

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
    });

    return { engine, params };
}
