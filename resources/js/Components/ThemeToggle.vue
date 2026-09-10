<script setup>
import Dropdown from '@/Components/Dropdown.vue';
import { useTheme } from '@/Composables/useTheme';
import { useTrans } from '@/Composables/useTrans';
import { SunIcon, MoonIcon, ComputerDesktopIcon, CheckIcon } from '@heroicons/vue/24/outline';

const { theme, isDark, setTheme } = useTheme();
const { t } = useTrans();

const options = [
    { value: 'light', label: () => t('theme.light', 'فاتح'), icon: SunIcon },
    { value: 'dark', label: () => t('theme.dark', 'داكن'), icon: MoonIcon },
    { value: 'system', label: () => t('theme.system', 'حسب النظام'), icon: ComputerDesktopIcon },
];
</script>

<template>
    <Dropdown align="right" width="48">
        <template #trigger>
            <button
                type="button"
                class="rounded-full p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                :title="t('theme.toggle', 'المظهر')"
            >
                <MoonIcon v-if="isDark" class="h-5 w-5" />
                <SunIcon v-else class="h-5 w-5" />
            </button>
        </template>
        <template #content>
            <button
                v-for="option in options"
                :key="option.value"
                type="button"
                @click="setTheme(option.value)"
                class="flex w-full items-center gap-2 px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none dark:text-gray-300 dark:hover:bg-gray-800 dark:focus:bg-gray-800"
            >
                <component :is="option.icon" class="h-4 w-4 shrink-0" />
                <span class="flex-1">{{ option.label() }}</span>
                <CheckIcon v-if="theme === option.value" class="h-4 w-4 shrink-0 text-[var(--accent-600)]" />
            </button>
        </template>
    </Dropdown>
</template>
