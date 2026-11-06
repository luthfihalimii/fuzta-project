import { expect, type Page } from '@playwright/test';

export const USERS = {
    owner: 'owner@pizzafruzta.test',
    cashier: 'kasir.pf-01@pizzafruzta.test',
    kitchen: 'dapur.pf-01@pizzafruzta.test',
} as const;

export type Role = keyof typeof USERS;

export function authFile(role: string): string {
    return `e2e/.auth/${role}.json`;
}

export function unique(prefix: string): string {
    return `${prefix}-${Date.now()}`;
}

export async function login(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Kata sandi').fill('password');
    await page.getByRole('button', { name: 'Masuk' }).click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

export async function assertCleanPage(page: Page): Promise<void> {
    await expect(page.locator('h1').first()).toBeVisible();
    await expect(page.locator('body')).not.toContainText(
        /Whoops|Server Error|SQLSTATE/,
    );
}

/** Open a shift when the POS shows the shift gate. */
export async function openShiftIfNeeded(page: Page): Promise<void> {
    await page.goto('/pos');

    const gate = page.getByRole('heading', { name: 'Buka shift' });

    if (await gate.isVisible()) {
        await page.getByRole('checkbox').check();
        await page.getByRole('button', { name: 'Buka shift' }).click();
        await expect(gate).toBeHidden();
    }
}
