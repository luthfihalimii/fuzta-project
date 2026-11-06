import { expect, test } from '@playwright/test';
import { authFile, openShiftIfNeeded } from './helpers';

test.describe('POS to kitchen order flow', () => {
    test.use({ storageState: authFile('cashier') });
    test.setTimeout(90_000);

    test('create, pay, and progress an order through the kitchen', async ({
        browser,
    }) => {
        const cashierContext = await browser.newContext({
            storageState: authFile('cashier'),
        });
        const cashier = await cashierContext.newPage();

        await openShiftIfNeeded(cashier);
        await expect(
            cashier.getByRole('heading', { name: 'POS', exact: true }),
        ).toBeVisible();

        // Add a seeded product (auto-selects its first variant).
        await cashier.getByRole('button', { name: /Pizza Margherita/ }).click();
        await cashier.getByRole('button', { name: /Tambah ·/ }).click();
        const [orderResponse] = await Promise.all([
            cashier.waitForResponse(
                (response) =>
                    response.url().endsWith('/pos/orders') &&
                    response.request().method() === 'POST',
            ),
            cashier.getByRole('button', { name: 'Buat pesanan' }).click(),
        ]);
        const orderNumber = (await orderResponse.json()).order
            .order_number as string;
        expect(orderNumber).toBeTruthy();

        const payment = cashier.getByRole('dialog', { name: 'Pembayaran' });
        await expect(payment).toBeVisible();
        await payment.getByRole('button', { name: 'Konfirmasi' }).click();

        await expect(cashier.getByText('Pembayaran berhasil')).toBeVisible();

        await cashierContext.close();

        // Kitchen sees it in the "Baru" tab and advances all statuses.
        const kitchenContext = await browser.newContext({
            storageState: authFile('kitchen'),
        });
        const kitchen = await kitchenContext.newPage();

        await kitchen.goto('/kitchen');
        await kitchen
            .getByRole('button', { name: 'Baru', exact: true })
            .click();

        const card = kitchen
            .locator('article')
            .filter({ hasText: orderNumber as string })
            .first();
        await expect(card).toBeVisible();

        for (const [tab, action] of [
            ['Diterima', 'Terima pesanan'],
            ['Disiapkan', 'Mulai masak'],
            ['Siap', 'Siap disajikan'],
            ['Selesai', 'Selesaikan'],
        ] as const) {
            const activeCard = kitchen
                .locator('article')
                .filter({ hasText: orderNumber as string })
                .first();
            await expect(activeCard).toBeVisible();
            await activeCard.getByRole('button', { name: action }).click();
            await kitchen
                .getByRole('button', { name: tab, exact: true })
                .click();
            await expect(
                kitchen
                    .locator('article')
                    .filter({ hasText: orderNumber as string })
                    .first(),
            ).toBeVisible();
        }

        await kitchenContext.close();
    });

    test('orders page lists the order and opens its receipt', async ({
        page,
    }) => {
        await page.goto('/pos/orders');
        await expect(
            page.getByRole('heading', { name: 'Pesanan' }),
        ).toBeVisible();
        await expect(page.locator('article').first()).toBeVisible();

        await page.getByRole('button', { name: 'Struk' }).first().click();
        await expect(page.getByText(/Pizza Fruzta/i).last()).toBeVisible();
    });

    test('order can be cancelled before payment', async ({ page }) => {
        await openShiftIfNeeded(page);
        await page.getByRole('button', { name: /Air Mineral/ }).click();
        const [orderResponse] = await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.url().endsWith('/pos/orders') &&
                    response.request().method() === 'POST',
            ),
            page.getByRole('button', { name: 'Buat pesanan' }).click(),
        ]);
        const orderNumber = (await orderResponse.json()).order
            .order_number as string;

        const payment = page.getByRole('dialog', { name: 'Pembayaran' });
        await expect(payment).toBeVisible();
        await payment.getByRole('button', { name: 'Batal' }).click();

        await page.goto('/pos/orders');
        const card = page
            .locator('article')
            .filter({ hasText: orderNumber })
            .first();
        await expect(card).toBeVisible();
        await card.getByRole('button', { name: 'Batalkan' }).click();

        const dialog = page.getByRole('dialog');
        await dialog.getByLabel(/alasan/i).fill('Uji e2e pembatalan');
        await dialog.getByRole('button', { name: /batalkan|simpan/i }).click();

        await expect(
            page.getByText(/dibatalkan|cancel/i).first(),
        ).toBeVisible();
    });
});

test.describe('void flow', () => {
    test.use({ storageState: authFile('cashier') });

    test('a paid order can be voided by the owner with a reason', async ({
        browser,
    }) => {
        const cashierContext = await browser.newContext({
            storageState: authFile('cashier'),
        });
        const page = await cashierContext.newPage();
        await openShiftIfNeeded(page);

        await page.getByRole('button', { name: /Air Mineral/ }).click();
        const [orderResponse] = await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.url().endsWith('/pos/orders') &&
                    response.request().method() === 'POST',
            ),
            page.getByRole('button', { name: 'Buat pesanan' }).click(),
        ]);
        const orderNumber = (await orderResponse.json()).order
            .order_number as string;

        const payment = page.getByRole('dialog', { name: 'Pembayaran' });
        await expect(payment).toBeVisible();
        await payment.getByRole('button', { name: 'Konfirmasi' }).click();
        await expect(page.getByText('Pembayaran berhasil')).toBeVisible();

        await cashierContext.close();

        const ownerContext = await browser.newContext({
            storageState: authFile('owner'),
        });
        const owner = await ownerContext.newPage();
        await owner.goto('/pos/orders?branch=1');

        const card = owner
            .locator('article')
            .filter({ hasText: orderNumber })
            .first();
        await expect(card).toBeVisible();
        await card.getByRole('button', { name: 'Void' }).click();

        const dialog = owner.getByRole('dialog', { name: 'Void pesanan' });
        await dialog.getByLabel('Alasan').fill('Uji e2e void');
        await dialog
            .getByRole('button', { name: 'Void pesanan', exact: true })
            .click();

        await expect(owner.getByText('Pesanan di-void.')).toBeVisible();
        await expect(
            card.getByText('Dibatalkan', { exact: true }).first(),
        ).toBeVisible();
        await ownerContext.close();
    });
});
