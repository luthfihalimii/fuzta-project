import { expect, test } from '@playwright/test';
import { USERS, authFile, login } from './helpers';

const anonymous = { storageState: { cookies: [], origins: [] } };

test.use(anonymous);

test.describe('authentication', () => {
    test('guest is redirected to login', async ({ page }) => {
        await page.goto('/admin/staff');
        await expect(page).toHaveURL(/\/login$/);
        await expect(
            page.getByRole('heading', { name: 'Masuk ke operasional' }),
        ).toBeVisible();
    });

    test('wrong password shows an error and stays on login', async ({
        page,
    }) => {
        await page.goto('/login');
        await page.getByLabel('Email').fill(USERS.owner);
        await page.getByLabel('Kata sandi').fill('wrong-password');
        await page.getByRole('button', { name: 'Masuk' }).click();

        await expect(page).toHaveURL(/\/login$/);
        await expect(page.locator('body')).toContainText(/kredensial|salah/i);
    });

    test('owner lands on admin dashboard', async ({ page }) => {
        await login(page, USERS.owner);
        await expect(page).toHaveURL(/\/admin$/);
        await expect(
            page.getByRole('heading', { name: 'Dashboard Pemilik' }),
        ).toBeVisible();
    });

    test('cashier lands on POS', async ({ page }) => {
        await login(page, USERS.cashier);
        await expect(page).toHaveURL(/\/pos$/);
        await expect(
            page.getByRole('heading', { name: 'POS', exact: true }),
        ).toBeVisible();
    });

    test('kitchen lands on kitchen display', async ({ page }) => {
        await login(page, USERS.kitchen);
        await expect(page).toHaveURL(/\/kitchen$/);
        await expect(
            page.getByText('Dapur', { exact: true }).first(),
        ).toBeVisible();
    });

    test('logout returns to login', async ({ page }) => {
        await login(page, USERS.owner);
        await page.getByRole('button', { name: /keluar/i }).click();
        await expect(page).toHaveURL(/\/login$/);
    });
});

test.describe('route guards', () => {
    test('cashier cannot open owner pages', async ({ browser }) => {
        const context = await browser.newContext({
            storageState: authFile('cashier'),
        });
        const page = await context.newPage();

        for (const path of ['/admin', '/admin/staff', '/admin/reports/sales']) {
            const response = await page.goto(path);
            expect(response?.status(), path).toBe(403);
        }

        await context.close();
    });

    test('kitchen cannot open POS or owner pages', async ({ browser }) => {
        const context = await browser.newContext({
            storageState: authFile('kitchen'),
        });
        const page = await context.newPage();

        for (const path of ['/pos', '/admin']) {
            const response = await page.goto(path);
            expect(response?.status(), path).toBe(403);
        }

        await context.close();
    });
});
