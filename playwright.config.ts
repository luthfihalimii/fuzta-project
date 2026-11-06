import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.BASE_URL ?? 'http://127.0.0.1:8000';

export default defineConfig({
    testDir: './e2e',
    globalSetup: './e2e/global-setup.ts',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter: [
        ['list'],
        ['html', { outputFolder: 'playwright-report', open: 'never' }],
    ],
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        actionTimeout: 10_000,
        navigationTimeout: 30_000,
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: {
        command:
            'php artisan cache:clear >/dev/null && php artisan serve --host=127.0.0.1 --port=8000',
        url: `${baseURL}/health`,
        reuseExistingServer: true,
        timeout: 60_000,
    },
});
