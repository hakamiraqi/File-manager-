<script setup>
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ChartBarIcon } from '@heroicons/vue/24/outline';
import Chart from 'chart.js/auto';

const props = defineProps({ days: Number, labels: Array, series: Array, totals: Array });

const canvas = ref(null);
let chart = null;
const days = ref(props.days);

const PALETTE = ['#4f46e5', '#059669', '#d97706', '#0284c7', '#e11d48', '#7c3aed'];

function renderChart() {
    if (chart) chart.destroy();

    chart = new Chart(canvas.value, {
        type: 'line',
        data: {
            labels: props.labels,
            datasets: props.series.map((s, i) => ({
                label: s.label,
                data: s.data,
                borderColor: PALETTE[i % PALETTE.length],
                backgroundColor: PALETTE[i % PALETTE.length] + '33',
                tension: 0.3,
                fill: false,
            })),
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        },
    });
}

onMounted(renderChart);
onBeforeUnmount(() => chart?.destroy());
watch(() => props.series, renderChart);

function changeRange() {
    router.get(route('admin.analytics.index'), { days: days.value }, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head title="التحليلات" />

    <AdminLayout>
        <template #header>
            <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-800">
                <ChartBarIcon class="h-5 w-5 text-gray-500" />
                التحليلات
            </h2>
        </template>

        <div class="space-y-6 p-6">
            <div class="flex items-center gap-3">
                <label class="text-sm text-gray-600">النطاق الزمني:</label>
                <select v-model.number="days" @change="changeRange" class="rounded-md border-gray-300 text-sm">
                    <option :value="7">آخر 7 أيام</option>
                    <option :value="30">آخر 30 يوماً</option>
                    <option :value="90">آخر 90 يوماً</option>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <div v-for="t in totals" :key="t.label" class="rounded-xl border border-gray-200 bg-white p-4 text-center">
                    <p class="text-2xl font-bold text-gray-800">{{ t.total }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ t.label }}</p>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <canvas ref="canvas" height="100" />
            </div>
        </div>
    </AdminLayout>
</template>
