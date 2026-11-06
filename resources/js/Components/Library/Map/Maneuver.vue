<script setup>
// Big satnav arrow for one step of the walk.
defineProps({ type: { type: String, default: 'start' } });
</script>

<template>
    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <template v-if="type === 'right'"><path d="M16 42V24a6 6 0 0 1 6-6h14" /><path d="M29 10l8 8-8 8" /></template>
        <template v-else-if="type === 'left'"><path d="M32 42V24a6 6 0 0 0-6-6H12" /><path d="M19 10l-8 8 8 8" /></template>
        <template v-else-if="type === 'slight_right'"><path d="M18 42V28l14-16" /><path d="M22 10h11v11" /></template>
        <template v-else-if="type === 'slight_left'"><path d="M30 42V28L16 12" /><path d="M26 10H15v11" /></template>
        <template v-else-if="type === 'uturn'"><path d="M30 42V18a8 8 0 0 0-16 0v12" /><path d="M8 24l6 7 6-7" /></template>
        <template v-else-if="type.startsWith('arrive')"><path d="M24 44s13-12.5 13-23a13 13 0 1 0-26 0c0 10.5 13 23 13 23z" /><circle cx="24" cy="21" r="4.5" /></template>
        <template v-else-if="type === 'stairs_up'"><path d="M6 40h10v-9h9v-9h9v-9h8" /><path d="M30 6h12v12" /></template>
        <template v-else-if="type === 'stairs_down'"><path d="M6 13h10v9h9v9h9v9h8" /><path d="M42 30v12H30" /></template>
        <template v-else-if="type === 'enter'"><path d="M10 44V8h20v36" /><path d="M30 26h12" /><path d="M37 20l6 6-6 6" /><circle cx="24" cy="27" r="1.5" fill="currentColor" /></template>
        <template v-else><path d="M24 42V8" /><path d="M14 18l10-10 10 10" /></template>
    </svg>
</template>
