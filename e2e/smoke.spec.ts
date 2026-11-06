import { expect, test } from '@playwright/test';
import { assertCleanPage, authFile } from './helpers';

const ownerPages: Array<[string, string]> = [
    ['/admin', 'Dashboard Pemilik'],
    ['/admin/catalog/categories', 'Kategori'],
    ['/admin/catalog/products', 'Produk'],
    ['/admin/catalog/products/create', 'Tambah produk'],
    ['/admin/catalog/modifier-groups', 'Modifier'],
    ['/admin/stock/ingredients', 'Bahan'],
    ['/admin/stock/units', 'Satuan & konversi'],
    ['/admin/stock/recipes', 'Resep'],
    ['/admin/stock/movements', 'Pergerakan stok'],
    ['/admin/stock/counts', 'Opname stok'],
    ['/admin/stock/transfers', 'Transfer antar cabang'],
    ['/admin/purchases', 'Pembelian'],
    ['/admin/purchases/create', 'Terima barang'],
    ['/admin/suppliers', 'Supplier'],
    ['/admin/staff', 'Staf'],
    ['/admin/branches', 'Cabang'],
    ['/admin/shifts', 'Shift'],
    ['/admin/reports/sales', 'Laporan penjualan'],
    ['/admin/reports/stock', 'Laporan stok'],
    ['/admin/audit', 'Audit'],
    ['/pos', 'POS'],
    ['/kitchen', 'Dapur'],
];

test.describe('owner page smoke', () => {
    test.use({ storageState: authFile('owner') });

    for (const [path, heading] of ownerPages) {
        test(`GET ${path}`, async ({ page }) => {
            const response = await page.goto(path);
            expect(response?.status()).toBe(200);

            if (path === '/kitchen') {
                await expect(
                    page.getByText(heading, { exact: true }).first(),
                ).toBeVisible();
            } else {
                await expect(
                    page.getByRole('heading', { name: heading, exact: true }),
                ).toBeVisible();
            }

            await assertCleanPage(page);
        });
    }
});

test.describe('cashier page smoke', () => {
    test.use({ storageState: authFile('cashier') });

    for (const path of ['/pos', '/pos/orders', '/pos/purchases']) {
        test(`GET ${path}`, async ({ page }) => {
            const response = await page.goto(path);
            expect(response?.status()).toBe(200);
            await assertCleanPage(page);
        });
    }
});

test.describe('kitchen page smoke', () => {
    test.use({ storageState: authFile('kitchen') });

    for (const path of ['/kitchen', '/kitchen/stock', '/kitchen/purchases']) {
        test(`GET ${path}`, async ({ page }) => {
            const response = await page.goto(path);
            expect(response?.status()).toBe(200);
            await assertCleanPage(page);
        });
    }
});
