<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ value: number }>();

const formatted = computed(() =>
    new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
        minimumFractionDigits: 0,
    }).format(props.value),
);
</script>

<template>
    <span class="tabular-nums">{{ formatted }}</span>
</template>
