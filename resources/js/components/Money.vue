<script setup lang="ts">
import { computed } from 'vue';
import { formatRupiah } from '@/lib/money';

const props = defineProps<{ value: number }>();

const formatted = computed(() => formatRupiah(props.value));
</script>

<template>
    <span class="tabular-nums">{{ formatted }}</span>
</template>
