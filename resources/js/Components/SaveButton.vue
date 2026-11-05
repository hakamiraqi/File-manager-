<script setup>
import { ref } from 'vue';
import { BookmarkIcon } from '@heroicons/vue/24/outline';
import { BookmarkIcon as BookmarkSolidIcon } from '@heroicons/vue/24/solid';
import { useSaved } from '@/Composables/useSaved';
import { useTrans } from '@/Composables/useTrans';

// Bookmark toggle for a file or folder.
const props = defineProps({
    type: { type: String, required: true },
    id: { type: Number, required: true },
    size: { type: String, default: 'h-5 w-5' },
});
const { isSaved, toggle } = useSaved();
const { t } = useTrans();
const pop = ref(false);

async function click() {
    try {
        const saved = await toggle(props.type, props.id);
        if (saved) {
            pop.value = true;
            setTimeout(() => (pop.value = false), 300);
        }
    } catch { /* state already restored */ }
}
</script>

<template>
    <button
        type="button"
        class="rounded-full p-1 transition hover:bg-gray-100 dark:hover:bg-gray-700"
        :class="pop ? 'scale-125' : 'scale-100'"
        :title="isSaved(type, id) ? t('saved.remove', 'إزالة من المحفوظات') : t('saved.add', 'حفظ')"
        :aria-pressed="isSaved(type, id)"
        @click.stop="click"
    >
        <BookmarkSolidIcon v-if="isSaved(type, id)" :class="[size, 'text-[var(--accent-600)]']" />
        <BookmarkIcon v-else :class="[size, 'text-gray-400']" />
    </button>
</template>
