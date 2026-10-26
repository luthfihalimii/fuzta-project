<script setup lang="ts">
export type SelectOption = {
    value: string | number | null;
    label: string;
};

defineProps<{
    label?: string;
    error?: string;
    options: SelectOption[];
    placeholder?: string;
    required?: boolean;
}>();

const model = defineModel<string | number | null>();
</script>

<template>
    <label class="block">
        <span v-if="label" class="mb-1.5 block text-sm font-medium text-ink">
            {{ label }}
        </span>
        <select
            v-model="model"
            :required="required"
            :aria-invalid="error ? 'true' : 'false'"
            class="min-h-12 w-full rounded-xl border border-line bg-white px-3.5 text-base text-ink outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/20"
        >
            <option v-if="placeholder" :value="null">{{ placeholder }}</option>
            <option
                v-for="option in options"
                :key="String(option.value)"
                :value="option.value"
            >
                {{ option.label }}
            </option>
        </select>
        <span v-if="error" class="mt-1.5 block text-sm text-danger">
            {{ error }}
        </span>
    </label>
</template>
