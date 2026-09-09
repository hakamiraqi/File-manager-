<script setup>
defineProps({
    used: { type: Number, required: true },
    total: { type: Number, required: true },
    percent: { type: Number, required: true },
});

function humanSize(bytes) {
    const units = ['بايت', 'كيلوبايت', 'ميغابايت', 'غيغابايت', 'تيرابايت'];
    let i = 0;
    let value = bytes;
    while (value >= 1024 && i < units.length - 1) {
        value /= 1024;
        i++;
    }
    return `${value.toFixed(1)} ${units[i]}`;
}
</script>

<template>
    <div>
        <div class="mb-1 flex items-center justify-between text-xs text-gray-500">
            <span>المساحة المستخدمة</span>
            <span>{{ percent }}%</span>
        </div>
        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200">
            <div
                class="h-full rounded-full transition-all"
                :class="percent > 90 ? 'bg-red-500' : 'bg-[var(--accent-600)]'"
                :style="{ width: `${Math.min(percent, 100)}%` }"
            />
        </div>
        <p class="mt-1 text-xs text-gray-400">{{ humanSize(used) }} من {{ humanSize(total) }}</p>
    </div>
</template>
