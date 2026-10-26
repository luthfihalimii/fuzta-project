<script setup lang="ts">
import { computed } from 'vue';

type Variant = 'primary' | 'secondary' | 'danger';

const props = withDefaults(
    defineProps<{
        variant?: Variant;
        type?: 'button' | 'submit' | 'reset';
        disabled?: boolean;
    }>(),
    {
        variant: 'primary',
        type: 'button',
        disabled: false,
    },
);

const classes = computed(() => {
    const base =
        'inline-flex min-h-12 items-center justify-center gap-2 rounded-xl px-5 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50';

    const variants: Record<Variant, string> = {
        primary:
            'bg-brand text-white hover:bg-brand/90 focus-visible:outline-brand',
        secondary:
            'border border-line bg-white text-ink hover:bg-canvas focus-visible:outline-brand',
        danger:
            'bg-danger text-white hover:bg-danger/90 focus-visible:outline-danger',
    };

    return `${base} ${variants[props.variant]}`;
});
</script>

<template>
    <button :type="type" :disabled="disabled" :class="classes">
        <slot />
    </button>
</template>
