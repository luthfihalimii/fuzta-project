<script setup lang="ts">
defineProps<{
    label?: string;
    error?: string;
    type?: string;
    autocomplete?: string;
    placeholder?: string;
    required?: boolean;
    min?: string | number;
    max?: string | number;
    step?: string | number;
    inputmode?: 'text' | 'numeric' | 'decimal' | 'email' | 'tel';
}>();

const model = defineModel<string | number>();
</script>

<template>
    <label class="block">
        <span v-if="label" class="mb-1.5 block text-sm font-medium text-ink">
            {{ label }}
        </span>
        <input
            v-model="model"
            :type="type ?? 'text'"
            :autocomplete="autocomplete"
            :placeholder="placeholder"
            :required="required"
            :min="min"
            :max="max"
            :step="step"
            :inputmode="inputmode"
            :aria-invalid="error ? 'true' : 'false'"
            class="min-h-12 w-full rounded-xl border border-line bg-white px-3.5 text-base text-ink transition outline-none placeholder:text-muted/70 focus:border-brand focus:ring-2 focus:ring-brand/20"
        />
        <span v-if="error" class="mt-1.5 block text-sm text-danger">
            {{ error }}
        </span>
    </label>
</template>
