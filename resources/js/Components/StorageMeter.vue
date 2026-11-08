<script setup>
import { useTrans } from '@/Composables/useTrans';

defineProps({
    used: { type: Number, required: true },
    total: { type: Number, required: true },
    percent: { type: Number, required: true },
});

const { t } = useTrans();

function humanSize(bytes) {
    const units = [
        t('unit.bytes', 'بايت'),
        t('unit.kb', 'كيلوبايت'),
        t('unit.mb', 'ميغابايت'),
        t('unit.gb', 'غيغابايت'),
        t('unit.tb', 'تيرابايت'),
    ];
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
        <div class="mb-1 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
            <span>{{ t('storage.used_label', 'المساحة المستخدمة') }}</span>
            <span>{{ percent }}%</span>
        </div>
        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
            <div
                class="h-full rounded-full transition-all"
                :class="percent > 90 ? 'bg-red-500' : 'bg-[var(--accent-600)]'"
                :style="{ width: `${Math.min(percent, 100)}%` }"
            />
        </div>
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ humanSize(used) }} {{ t('storage.used_of', 'من') }} {{ humanSize(total) }}</p>
    </div>
</template>
