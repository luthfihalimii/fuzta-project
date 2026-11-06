import { expect, test } from '@playwright/test';
import { authFile, unique } from './helpers';

test.use({ storageState: authFile('owner') });

test.describe('catalog and identity admin flows', () => {
    test('create and edit a category', async ({ page }) => {
        const name = unique('Kategori E2E');

        await page.goto('/admin/catalog/categories');
        await page.getByLabel('Nama kategori').fill(name);
        await page.getByRole('button', { name: 'Tambah kategori' }).click();

        await expect(page.getByRole('cell', { name })).toBeVisible();

        await page
            .getByRole('row', { name: new RegExp(name) })
            .getByRole('button', { name: /ubah/i })
            .click();
        await page.getByLabel('Nama kategori').fill(`${name} Edit`);
        await page.getByRole('button', { name: 'Simpan perubahan' }).click();

        await expect(
            page.getByRole('cell', { name: `${name} Edit` }),
        ).toBeVisible();
    });

    test('create a supplier', async ({ page }) => {
        const name = unique('Supplier E2E');

        await page.goto('/admin/suppliers');
        await page.getByLabel('Nama', { exact: true }).fill(name);
        await page.getByLabel('Telepon (opsional)').fill('081299990000');
        await page.getByRole('button', { name: 'Tambah supplier' }).click();

        await expect(page.getByRole('cell', { name })).toBeVisible();
    });

    test('create a staff account', async ({ page }) => {
        const name = unique('Kasir E2E');

        await page.goto('/admin/staff');
        await page.getByLabel('Nama', { exact: true }).fill(name);
        await page.getByLabel('Email').fill(`${Date.now()}@pizzafruzta.test`);
        await page
            .getByLabel('Kata sandi', { exact: true })
            .fill('password123');
        await page.getByLabel('Peran').selectOption('cashier');
        await page
            .getByLabel('Cabang')
            .selectOption({ label: 'PF-01 — Pizza Fruzta Pusat' });
        await page.getByRole('button', { name: 'Tambah staf' }).click();

        await expect(page.getByRole('cell', { name })).toBeVisible();
    });

    test('create a branch', async ({ page }) => {
        const name = unique('Cabang E2E');
        const code = `E${Date.now().toString().slice(-4)}`;

        await page.goto('/admin/branches');
        await page.getByLabel('Nama cabang').fill(name);
        await page.getByLabel('Kode cabang').fill(code);
        await page.getByLabel('Alamat').fill('Jl. Uji E2E');
        await page.getByLabel('Telepon').fill('081277770000');
        await page.getByLabel('Float kas').fill('100000');
        await page.getByRole('button', { name: 'Tambah cabang' }).click();

        await expect(page.getByRole('cell', { name })).toBeVisible();
    });

    test('create, edit, and list a product', async ({ page }) => {
        const name = unique('Produk E2E');

        await page.goto('/admin/catalog/products/create');
        await page.getByLabel('Kategori').selectOption({ label: 'Pizza' });
        await page.getByLabel('Nama produk').fill(name);
        await page.getByLabel('Harga dasar (IDR)').fill('42000');
        await page.getByRole('button', { name: 'Tambah produk' }).click();

        await expect(page.getByRole('cell', { name })).toBeVisible();

        await page
            .getByRole('row', { name: new RegExp(name) })
            .getByRole('link', { name: /ubah/i })
            .click();
        await page.getByLabel('Nama produk').fill(`${name} Edit`);
        await page.getByRole('button', { name: 'Simpan perubahan' }).click();

        await expect(
            page.getByRole('cell', { name: `${name} Edit` }),
        ).toBeVisible();
    });

    test('create a modifier group', async ({ page }) => {
        const name = unique('Grup E2E');

        await page.goto('/admin/catalog/modifier-groups');
        await page.getByLabel('Nama grup').fill(name);
        await page.getByRole('button', { name: 'Tambah grup' }).click();

        await expect(page.getByText(name).first()).toBeVisible();
    });

    test('create a unit and a conversion', async ({ page }) => {
        const name = unique('Satuan E2E');
        const code = `e${Date.now().toString().slice(-4)}`;

        await page.goto('/admin/stock/units');
        await page.getByLabel('Nama satuan').fill(name);
        await page.getByLabel('Kode').fill(code);
        await page.getByRole('button', { name: 'Simpan satuan' }).click();
        await expect(page.getByRole('cell', { name })).toBeVisible();

        await page
            .getByLabel('Dari satuan')
            .selectOption({ label: `${code} — ${name}` });
        await page.getByLabel('Ke satuan').selectOption({ label: 'g — Gram' });
        await page.getByLabel('Jumlah dari').fill('1');
        await page.getByLabel('Jumlah menjadi').fill('1000');
        await page
            .getByRole('button', { name: /tambah konversi|simpan konversi/i })
            .click();

        await expect(
            page.getByText(`1 ${code} = 1000 g`).first(),
        ).toBeVisible();
    });
});
