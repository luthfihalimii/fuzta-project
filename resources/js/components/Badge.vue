<script setup lang="ts">
type Tone = 'neutral' | 'success' | 'danger' | 'warning' | 'info';

withDefaults(defineProps<{ tone?: Tone }>(), {
    tone: 'neutral',
});

const tones: Record<Tone, string> = {
    neutral: 'border-line bg-canvas text-muted',
    success: 'border-success/20 bg-success/10 text-success',
    danger: 'border-danger/20 bg-danger/10 text-danger',
    warning: 'border-warning/20 bg-warning/10 text-warning',
    info: 'border-info/20 bg-info/10 text-info',
};
</script>

<template>
    <span
        class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold"
        :class="tones[tone]"
    >
        <slot />
    </span>
</template>
