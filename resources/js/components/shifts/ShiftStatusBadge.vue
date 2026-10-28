<script setup lang="ts">
import { computed } from 'vue';
import Badge from '@/components/Badge.vue';
import {
    shiftStatusLabel,
    shiftStatusTone,
    type ShiftSummaryData,
} from '@/lib/shift';

const props = defineProps<{ shift: ShiftSummaryData }>();

const label = computed(() => shiftStatusLabel(props.shift));
const tone = computed(() => shiftStatusTone(props.shift));
</script>

<template>
    <Badge :tone="tone">{{ label }}</Badge>
</template>
