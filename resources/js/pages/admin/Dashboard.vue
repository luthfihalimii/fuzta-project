<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import StaffLayout from '@/layouts/StaffLayout.vue';
</script>

<template>
    <Head title="Dashboard" />

    <StaffLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-ink">Dashboard Pemilik</h1>
                <p class="mt-1 text-sm text-muted">
                    Kelola staf, cabang, dan operasional Pizza Fruzta.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <Link
                    href="/admin/staff"
                    class="rounded-2xl border border-line bg-white p-5 transition hover:border-brand/40 hover:shadow-sm"
                >
                    <h2 class="font-semibold text-ink">Staf</h2>
                    <p class="mt-1 text-sm text-muted">
                        Tambah, ubah peran, dan nonaktifkan akun staf.
                    </p>
                </Link>

                <Link
                    href="/admin/branches"
                    class="rounded-2xl border border-line bg-white p-5 transition hover:border-brand/40 hover:shadow-sm"
                >
                    <h2 class="font-semibold text-ink">Cabang</h2>
                    <p class="mt-1 text-sm text-muted">
                        Lihat dan kelola cabang yang beroperasi.
                    </p>
                </Link>
            </div>
        </div>
    </StaffLayout>
</template>
