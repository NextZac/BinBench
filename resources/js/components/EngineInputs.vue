<template>
    <div class="flex flex-wrap items-end gap-3 text-xs">
        <label>Displacement (l)
            <input v-model.number="engine.displacement" type="number" step="0.1" min="0.5" max="10" :placeholder="derived?.displacement" class="input mt-1 block w-20">
        </label>
        <label>Cylinders
            <input v-model.number="engine.cylinders" type="number" min="1" max="16" :placeholder="derived?.cylinders" class="input mt-1 block w-16">
        </label>
        <label title="Rated power of the stock engine. Scales the estimate so the stock curve matches it.">Stock power (PS)
            <input v-model.number="engine.stockPower" type="number" min="20" max="2000" placeholder="optional" class="input mt-1 block w-24">
        </label>
    </div>
</template>

<script setup>
// engine: reactive inputs from useEngineInputs; derived: what the server read from the file (shown as placeholders).
defineProps({
    engine: { type: Object, required: true },
    derived: { type: Object, default: null },
});
</script>
