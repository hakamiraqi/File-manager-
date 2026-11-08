<script setup>
import { BackspaceIcon } from '@heroicons/vue/24/outline';
import { ref } from 'vue';

// The exam's calculator: basic (+ − × ÷ %) or scientific (powers, roots, trigonometry, logs).
const props = defineProps({ scientific: Boolean });
const expr = ref('');
const result = ref('');
const deg = ref(true);
const press = (k) => (expr.value += k);
function evaluate() {
    try {
        let e = expr.value.replace(/×/g, '*').replace(/÷/g, '/').replace(/π/g, 'pi').replace(/√/g, 'sqrt').replace(/\^/g, '**').replace(/(\d+(?:\.\d+)?)%/g, '($1/100)');
        if (!/^[\d+\-*/().,\s a-z]*$/i.test(e)) throw 0;
        const toRad = deg.value ? '*Math.PI/180' : '';
        const env = {
            sin: `(x)=>Math.sin(x${toRad})`, cos: `(x)=>Math.cos(x${toRad})`, tan: `(x)=>Math.tan(x${toRad})`,
            sqrt: 'Math.sqrt', log: 'Math.log10', ln: 'Math.log', abs: 'Math.abs', pi: 'Math.PI', e: 'Math.E',
        };
        const names = (e.match(/[a-z]+/gi) || []).filter((n) => !(n in env));
        if (names.length) throw 0;
        // Only the names above reach this function; nothing else of the page is in scope.
        const fn = new Function(`"use strict";const ${Object.entries(env).map(([k, v]) => `${k}=${v}`).join(',')};return (${e || 0});`);
        const v = fn();
        result.value = Number.isFinite(v) ? String(Math.round(v * 1e10) / 1e10) : 'خطأ';
    } catch {
        result.value = 'خطأ';
    }
}
const KEYS = ['7', '8', '9', '÷', '4', '5', '6', '×', '1', '2', '3', '-', '0', '.', '%', '+'];
const SCI = ['(', ')', '^', '√(', 'sin(', 'cos(', 'tan(', 'π', 'log(', 'ln(', 'abs(', 'e'];
</script>

<template>
    <div class="w-64 space-y-2 rounded-xl border border-gray-200 bg-white p-3 shadow-lg dark:border-gray-700 dark:bg-gray-800" dir="ltr">
        <input v-model="expr" @keydown.enter="evaluate" class="w-full rounded border-gray-300 text-end font-mono text-sm dark:border-gray-600 dark:bg-gray-700" />
        <p class="h-6 text-end font-mono text-lg font-semibold">{{ result }}</p>
        <div v-if="scientific" class="grid grid-cols-4 gap-1">
            <button v-for="k in SCI" :key="k" type="button" @click="press(k)" class="rounded bg-gray-100 py-1 text-xs dark:bg-gray-700">{{ k.replace('(', '') }}</button>
            <button type="button" @click="deg = !deg" class="col-span-4 rounded bg-gray-100 py-1 text-xs dark:bg-gray-700">{{ deg ? 'DEG' : 'RAD' }}</button>
        </div>
        <div class="grid grid-cols-4 gap-1">
            <button v-for="k in KEYS" :key="k" type="button" @click="press(k)" class="rounded bg-gray-100 py-2 font-mono text-sm dark:bg-gray-700">{{ k }}</button>
            <button type="button" @click="expr = ''; result = ''" class="col-span-2 rounded bg-red-50 py-2 text-sm text-red-700 dark:bg-red-900/30">C</button>
            <button type="button" @click="expr = expr.slice(0, -1)" class="rounded bg-gray-100 py-2 text-sm dark:bg-gray-700"><BackspaceIcon class="mx-auto h-5 w-5" /></button>
            <button type="button" @click="evaluate" class="rounded bg-[var(--accent-600)] py-2 text-sm text-white">=</button>
        </div>
    </div>
</template>
