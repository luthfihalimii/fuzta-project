import { expect, test } from '@playwright/test';
import { authFile, unique } from './helpers';

test.use({ storageState: authFile('owner') });
test.setTimeout(90_000);

test.describe('stock and purchasing admin flows', () => {
    test('create an ingredient, record a movement, and run a stock count', async ({
        page,
    }) => {
        const name = unique('Bahan E2E');

        await page.goto('/admin/stock/ingredients');
        await page.getByLabel('Nama bahan').fill(name);
        await page
            .getByLabel('Satuan dasar')
            .selectOption({ label: 'g — Gram' });
        await page.getByLabel('Ambang stok menipis').fill('10');
        await page.getByRole('button', { name: 'Tambah bahan' }).click();
        await expect(page.getByRole('cell', { name })).toBeVisible();

        await page.goto('/admin/stock/movements');
        await page.getByLabel('Jenis').selectOption('opening');
        await page.getByLabel('Bahan').selectOption({ label: `${name} (g)` });
        await page.getByLabel('Jumlah').fill('100');
        await page.getByLabel('Alasan').fill('Uji e2e stok masuk');
        await page.getByRole('button', { name: 'Simpan pergerakan' }).click();
        await expect(
            page.getByText(/tercatat|tersimpan|berhasil/i).first(),
        ).toBeVisible();

        await page.goto('/admin/stock/counts');
        await page.getByLabel(`Hitung ${name}`).fill('95');
        await page.getByRole('button', { name: 'Simpan opname' }).click();
        await expect(page.getByText(/opname|berhasil/i).first()).toBeVisible();
    });

    test('receive a purchase and see it in the purchase list', async ({
        page,
    }) => {
        await page.goto('/admin/purchases/create');

        await page.getByLabel('Pemasok').selectOption({ index: 1 });
        await page.getByLabel('Bahan').first().selectOption({ index: 1 });
        const unitSelect = page.getByLabel('Satuan').first();
        await unitSelect.selectOption({ index: 1 });
        await page.getByLabel('Jumlah').first().fill('5');
        await page.getByLabel('Harga satuan').first().fill('10000');
        await page.getByRole('button', { name: 'Simpan penerimaan' }).click();

        await expect(
            page.getByText(/berhasil|penerimaan/i).first(),
        ).toBeVisible();
        await page.goto('/admin/purchases');
        await expect(
            page.locator('a[href^="/admin/purchases/"]').first(),
        ).toBeVisible();
    });

    test('create, dispatch, and receive a stock transfer', async ({ page }) => {
        const ingredient = unique('Bahan Transfer E2E');

        // Seed stock in branch PF-01 first.
        await page.goto('/admin/stock/ingredients');
        await page.getByLabel('Nama bahan').fill(ingredient);
        await page
            .getByLabel('Satuan dasar')
            .selectOption({ label: 'g — Gram' });
        await page.getByLabel('Ambang stok menipis').fill('1');
        await page.getByRole('button', { name: 'Tambah bahan' }).click();

        await page.goto('/admin/stock/movements');
        await page.getByLabel('Jenis').selectOption('opening');
        await page
            .getByLabel('Bahan')
            .selectOption({ label: `${ingredient} (g)` });
        await page.getByLabel('Jumlah').fill('100');
        await page.getByLabel('Alasan').fill('Stok awal uji transfer');
        await page.getByRole('button', { name: 'Simpan pergerakan' }).click();

        await page.goto('/admin/stock/transfers');
        await page
            .getByLabel('Cabang asal')
            .selectOption({ label: 'Pizza Fruzta Pusat' });
        await page
            .getByLabel('Cabang tujuan')
            .selectOption({ label: 'Pizza Fruzta Cabang 2' });
        await page
            .getByLabel('Bahan')
            .selectOption({ label: `${ingredient} (g)` });
        await page.getByLabel('Jumlah').fill('10');
        await page.getByRole('button', { name: 'Buat transfer' }).click();

        const card = page
            .locator('article')
            .filter({ hasText: ingredient })
            .first();
        await expect(card).toBeVisible();

        await card.getByRole('button', { name: 'Kirim' }).click();
        await expect(card.getByText('In-transit')).toBeVisible();

        await card.getByRole('button', { name: 'Terima' }).click();
        await expect(card.getByText('Diterima')).toBeVisible();
    });

    test('create a recipe for a product', async ({ page }) => {
        await page.goto('/admin/stock/recipes');

        await page.getByLabel('Jenis target').selectOption({ index: 0 });
        await page
            .getByRole('combobox', { name: 'Target', exact: true })
            .selectOption({ index: 1 });
        await page.getByLabel('Bahan').first().selectOption({ index: 1 });
        await page.getByLabel('Jumlah').first().fill('25');
        await page.getByRole('button', { name: 'Simpan resep' }).click();

        await expect(page.getByText(/resep/i).first()).toBeVisible();
    });

    test('cashier can open the POS purchase page and submit a receipt', async ({
        browser,
    }) => {
        const context = await browser.newContext({
            storageState: authFile('cashier'),
        });
        const page = await context.newPage();

        await page.goto('/pos/purchases');
        await page.getByLabel('Pemasok').selectOption({ index: 1 });
        await page.getByLabel('Bahan').first().selectOption({ index: 1 });
        await page.getByLabel('Satuan').first().selectOption({ index: 1 });
        await page.getByLabel('Jumlah').first().fill('2');
        await page.getByLabel('Harga satuan').first().fill('5000');
        await page.getByRole('button', { name: 'Simpan penerimaan' }).click();

        await expect(
            page.getByText(/berhasil|penerimaan/i).first(),
        ).toBeVisible();
        await context.close();
    });
});
