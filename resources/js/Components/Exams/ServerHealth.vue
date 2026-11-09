<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useTrans } from '@/Composables/useTrans';
import { ServerStackIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';

// The server's health while exams run: polled every 20 seconds. `compact` shows only warnings.
defineProps({ compact: Boolean });
const { t } = useTrans();
const h = ref(null);
const failed = ref(false);
let timer = null;
async function load() {
    try { h.value = (await window.axios.get(route('exams.monitor.health'))).data; failed.value = false; } catch { failed.value = true; }
}
onMounted(() => { load(); timer = setInterval(load, 20000); });
onBeforeUnmount(() => clearInterval(timer));

const gb = (b) => (b / 1024 ** 3).toFixed(1);
const pct = (free, total) => (total ? Math.round((1 - free / total) * 100) : null);
const WARN = { disk: ['المساحة على القرص قاربت النفاد', 'exam.health_disk'], memory: ['الذاكرة قاربت النفاد', 'exam.health_memory'],
    load: ['المعالج تحت ضغط عالٍ', 'exam.health_load'], database: ['قاعدة البيانات لا تستجيب', 'exam.health_db'] };
</script>

<template>
    <div v-if="compact ? failed || h?.warnings?.length : true" class="rounded-xl border p-3 text-sm"
        :class="failed || h?.warnings?.length ? 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-900/20' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'">
        <div class="flex flex-wrap items-center gap-x-5 gap-y-1">
            <span class="flex items-center gap-1 font-medium"><ServerStackIcon class="h-5 w-5 text-gray-500" /> {{ t('exam.health', 'حالة السيرفر') }}</span>
            <template v-if="h && !compact">
                <span v-if="h.disk">{{ t('exam.health_disk_short', 'القرص') }}: {{ gb(h.disk.free) }} GB {{ t('exam.health_free', 'متاح') }} ({{ pct(h.disk.free, h.disk.total) }}%)</span>
                <span v-if="h.memory">{{ t('exam.health_memory_short', 'الذاكرة') }}: {{ gb(h.memory.free) }} / {{ gb(h.memory.total) }} GB {{ t('exam.health_free', 'متاح') }}</span>
                <span v-if="h.load !== null">{{ t('exam.health_load_short', 'الحِمل') }}: {{ h.load }}<template v-if="h.cpus"> / {{ h.cpus }}</template></span>
                <span>{{ t('exam.health_active', 'متصلون الآن') }}: <b>{{ h.active }}</b> / {{ h.running }}</span>
                <span :class="h.database ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'">{{ t('exam.health_db_short', 'قاعدة البيانات') }}: {{ h.database ? t('exam.health_ok', 'تعمل') : t('exam.health_down', 'متوقفة') }}</span>
            </template>
        </div>
        <p v-if="failed" class="mt-1 flex items-center gap-1 text-red-700 dark:text-red-300"><ExclamationTriangleIcon class="h-4 w-4" /> {{ t('exam.health_unreachable', 'تعذّر الاتصال بالسيرفر.') }}</p>
        <p v-for="w in h?.warnings ?? []" :key="w" class="mt-1 flex items-center gap-1 font-medium text-red-700 dark:text-red-300"><ExclamationTriangleIcon class="h-4 w-4" /> {{ t(WARN[w][1], WARN[w][0]) }}</p>
    </div>
</template>
