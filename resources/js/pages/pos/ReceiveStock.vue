<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import ReceiveStockForm from '@/components/purchasing/ReceiveStockForm.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { BranchOption, BranchSummary } from '@/lib/order';

type SupplierOption = {
    id: number;
    name: string;
};

type UnitOption = {
    id: number;
    code: string;
    name: string;
};

type IngredientOption = {
    id: number;
    name: string;
    base_unit_id: number;
    unit: string | null;
};

const props = defineProps<{
    branch: BranchSummary | null;
    branches: BranchOption[];
    canChooseBranch: boolean;
    suppliers: SupplierOption[];
    units: UnitOption[];
    ingredients: IngredientOption[];
    default_date: string;
}>();

const page = usePage();
</script>

<template>
    <Head title="Terima barang" />

    <StaffLayout>
        <div class="space-y-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Terima barang</h1>
                    <p class="mt-1 text-sm text-muted">
                        Catat barang masuk dari pemasok untuk cabang ini.
                    </p>
                </div>
                <Link
                    href="/pos"
                    class="inline-flex min-h-12 items-center rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                >
                    POS
                </Link>
            </div>

            <div
                v-if="page.props.flash.success"
                class="rounded-xl border border-kitchen-ready/40 bg-kitchen-ready/10 px-4 py-3 text-sm font-semibold text-kitchen-success-text"
                role="status"
            >
                {{ page.props.flash.success }}
            </div>
            <div
                v-if="page.props.flash.error"
                class="rounded-xl border border-danger/40 bg-danger/10 px-4 py-3 text-sm font-semibold text-kitchen-danger-text"
                role="alert"
            >
                {{ page.props.flash.error }}
            </div>

            <ReceiveStockForm
                :branch="props.branch"
                :branches="props.branches"
                :can-choose-branch="props.canChooseBranch"
                :suppliers="props.suppliers"
                :units="props.units"
                :ingredients="props.ingredients"
                :default-date="props.default_date"
                submit-url="/pos/purchases"
            />
        </div>
    </StaffLayout>
</template>
