<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Button from '@/components/Button.vue';
import Input from '@/components/Input.vue';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit(): void {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Masuk" />

    <main
        class="flex min-h-screen items-center justify-center bg-canvas px-4 py-10"
    >
        <div
            class="w-full max-w-sm rounded-2xl border border-line bg-white p-6 shadow-sm"
        >
            <p class="text-sm font-semibold tracking-wide text-brand uppercase">
                Pizza Fruzta
            </p>
            <h1 class="mt-2 text-2xl font-bold text-ink">
                Masuk ke operasional
            </h1>
            <p class="mt-1 text-sm text-muted">
                Gunakan akun staf yang diberikan pemilik cabang.
            </p>

            <form class="mt-6 space-y-4" @submit.prevent="submit">
                <Input
                    v-model="form.email"
                    label="Email"
                    type="email"
                    autocomplete="username"
                    :error="form.errors.email"
                    required
                />
                <Input
                    v-model="form.password"
                    label="Kata sandi"
                    type="password"
                    autocomplete="current-password"
                    :error="form.errors.password"
                    required
                />

                <label
                    class="flex min-h-12 items-center gap-2 text-sm text-muted"
                >
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        class="h-4 w-4 rounded border-line text-brand focus:ring-brand"
                    />
                    Ingat saya
                </label>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Memproses...' : 'Masuk' }}
                </Button>
            </form>
        </div>
    </main>
</template>
