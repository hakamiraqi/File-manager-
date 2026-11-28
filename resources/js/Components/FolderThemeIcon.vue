<script setup>
// The small picture of a folder theme (a tooth for dentistry, scales for law…).
import {
    AcademicCapIcon, HeartIcon, BeakerIcon, Cog6ToothIcon, FireIcon, CpuChipIcon, BookOpenIcon, TrophyIcon,
    ScaleIcon, GlobeAltIcon, ChartBarIcon, MoonIcon, FolderIcon, ArchiveBoxIcon, SparklesIcon,
} from '@heroicons/vue/24/outline';

defineProps({ icon: { type: String, default: 'folder' } });

const HERO = {
    cap: AcademicCapIcon, heart: HeartIcon, beaker: BeakerIcon, cog: Cog6ToothIcon, fire: FireIcon, chip: CpuChipIcon,
    book: BookOpenIcon, trophy: TrophyIcon, scale: ScaleIcon, globe: GlobeAltIcon, chart: ChartBarIcon, moon: MoonIcon,
    folder: FolderIcon, archive: ArchiveBoxIcon, atom: SparklesIcon,
};
</script>

<template>
    <component :is="HERO[icon]" v-if="HERO[icon]" />
    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path v-if="icon === 'tooth'" d="M7 3c-2.2 0-4 1.8-4 4.2 0 2.3.9 3.9 1.6 5.5.6 1.4.9 3 1.2 4.7.3 1.9.7 3.6 1.9 3.6 1.4 0 1.6-2 2-4 .3-1.5.7-2.5 2.3-2.5s2 1 2.3 2.5c.4 2 .6 4 2 4 1.2 0 1.6-1.7 1.9-3.6.3-1.7.6-3.3 1.2-4.7.7-1.6 1.6-3.2 1.6-5.5C21 4.8 19.2 3 17 3c-1.9 0-3 1-5 1S8.9 3 7 3z" />
        <template v-else-if="icon === 'paw'">
            <circle cx="6" cy="10" r="1.8" /><circle cx="9.5" cy="5.8" r="1.8" /><circle cx="14.5" cy="5.8" r="1.8" /><circle cx="18" cy="10" r="1.8" />
            <path d="M12 11c-2.6 0-5 3.2-5 5.6 0 1.7 1.2 2.6 2.6 2.6 1 0 1.6-.5 2.4-.5s1.4.5 2.4.5c1.4 0 2.6-.9 2.6-2.6 0-2.4-2.4-5.6-5-5.6z" />
        </template>
        <path v-else-if="icon === 'leaf'" d="M5 20c-.5-8.5 5.5-15 15-15 .5 9.5-6 15.5-14 15M5 20l8-8" />
        <template v-else-if="icon === 'tablet'">
            <rect x="4" y="3" width="16" height="18" rx="2.5" />
            <path d="M8 8l1.5-1M8 8l1.5 1M12 8l1.5-1M12 8l1.5 1M8 13h8M8 16.5l1.5-1M8 16.5l1.5 1M12.5 16.5h3.5" />
        </template>
    </svg>
</template>
