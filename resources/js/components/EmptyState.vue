<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="rounded-2xl border border-dashed border-line bg-white p-8 text-center"
    >
        <p class="font-semibold text-ink">{{ title }}</p>
        <p v-if="description" class="mt-1 text-sm text-muted">
            {{ description }}
        </p>
        <div v-if="$slots.default" class="mt-4">
            <slot />
        </div>
    </div>
</template>
