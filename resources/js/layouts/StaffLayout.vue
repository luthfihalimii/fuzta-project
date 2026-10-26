<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();

const user = computed(() => page.props.auth.user);

const nav = computed(() => {
    const current = page.props.auth.user;

    if (!current) {
        return [];
    }

    if (current.role === 'owner') {
        return [
            { label: 'Dashboard', href: '/admin' },
            { label: 'POS', href: '/pos' },
            { label: 'Katalog', href: '/admin/catalog/products' },
            { label: 'Staf', href: '/admin/staff' },
            { label: 'Cabang', href: '/admin/branches' },
        ];
    }

    if (current.role === 'cashier') {
        return [
            { label: 'POS', href: '/pos' },
            { label: 'Pesanan', href: '/pos/orders' },
        ];
    }

    return [{ label: 'Dapur', href: '/kitchen' }];
});

function isActive(href: string): boolean {
    if (href === '/admin' || href === '/pos') {
        return page.url === href;
    }

    return page.url === href || page.url.startsWith(`${href}/`);
}

function logout(): void {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-screen bg-canvas text-ink">
        <header
            class="sticky top-0 z-20 border-b border-line bg-white/95 backdrop-blur"
        >
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-3"
            >
                <Link href="/" class="text-lg font-bold text-brand">
                    Pizza Fruzta
                </Link>

                <nav
                    class="order-3 flex w-full items-center gap-1 overflow-x-auto sm:order-2 sm:w-auto"
                    aria-label="Navigasi utama"
                >
                    <Link
                        v-for="item in nav"
                        :key="item.href"
                        :href="item.href"
                        :aria-current="isActive(item.href) ? 'page' : undefined"
                        class="flex min-h-11 shrink-0 items-center rounded-xl px-3 text-sm font-semibold transition"
                        :class="
                            isActive(item.href)
                                ? 'bg-brand text-white'
                                : 'text-muted hover:bg-canvas hover:text-ink'
                        "
                    >
                        {{ item.label }}
                    </Link>
                </nav>

                <div class="order-2 flex items-center gap-3 sm:order-3">
                    <span class="hidden text-sm text-muted sm:block">
                        {{ user?.name }}
                    </span>
                    <button
                        type="button"
                        class="min-h-11 rounded-xl px-3 text-sm font-semibold text-muted transition hover:bg-canvas hover:text-ink"
                        @click="logout"
                    >
                        Keluar
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl px-4 py-6">
            <slot />
        </main>
    </div>
</template>
