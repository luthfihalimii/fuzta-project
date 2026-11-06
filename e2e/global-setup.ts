import { chromium, type FullConfig } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { USERS, authFile } from './helpers';

export default async function globalSetup(config: FullConfig): Promise<void> {
    const baseURL = config.projects[0].use.baseURL ?? 'http://127.0.0.1:8000';
    const browser = await chromium.launch();

    await mkdir('e2e/.auth', { recursive: true });

    try {
        for (const [role, email] of Object.entries(USERS)) {
            const context = await browser.newContext({ baseURL });
            const page = await context.newPage();

            await page.goto('/login');
            await page.getByLabel('Email').fill(email);
            await page.getByLabel('Kata sandi').fill('password');
            await page.getByRole('button', { name: 'Masuk' }).click();
            await page.waitForURL((url) => !url.pathname.startsWith('/login'));

            await context.storageState({ path: authFile(role) });
            await context.close();
        }
    } finally {
        await browser.close();
    }
}
