<script setup lang="ts">
withDefaults(
    defineProps<{
        open: boolean;
        title?: string;
        message?: string;
        confirmLabel?: string;
        cancelLabel?: string;
        danger?: boolean;
    }>(),
    {
        title: 'Konfirmasi',
        message: '',
        confirmLabel: 'Ya',
        cancelLabel: 'Batal',
        danger: false,
    },
);

const emit = defineEmits<{
    confirm: [];
    cancel: [];
}>();
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-4 sm:items-center"
            role="dialog"
            aria-modal="true"
            :aria-label="title"
            @click.self="emit('cancel')"
        >
            <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl">
                <h2 class="text-lg font-semibold text-ink">{{ title }}</h2>
                <p v-if="message" class="mt-2 text-sm text-muted">
                    {{ message }}
                </p>
                <div class="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        class="min-h-12 rounded-xl border border-line px-4 text-sm font-semibold text-ink"
                        @click="emit('cancel')"
                    >
                        {{ cancelLabel }}
                    </button>
                    <button
                        type="button"
                        class="min-h-12 rounded-xl px-4 text-sm font-semibold text-white"
                        :class="danger ? 'bg-danger' : 'bg-brand'"
                        @click="emit('confirm')"
                    >
                        {{ confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
