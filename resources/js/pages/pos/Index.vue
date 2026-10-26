<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import EmptyState from '@/components/EmptyState.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
</script>

<template>
    <Head title="POS" />

    <StaffLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-ink">POS</h1>
                <p class="mt-1 text-sm text-muted">
                    Pesanan baru diproses di sini.
                </p>
            </div>

            <EmptyState
                title="Layar kasir sedang disiapkan"
                description="Katalog dan pembuatan pesanan sudah aktif; antarmuka checkout menyusul."
            />
        </div>
    </StaffLayout>
</template>
