import { defineConfig } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const localEnvironmentFile = resolve(process.cwd(), '.env.uat.local');

for (const key of Object.keys(process.env)) {
    if (key.startsWith('UAT_')) {
        delete process.env[key];
    }
}

if (!existsSync(localEnvironmentFile)) {
    throw new Error('Missing .env.uat.local. Copy .env.uat.example and add local UAT values.');
}

for (const sourceLine of readFileSync(localEnvironmentFile, 'utf8').split(/\r?\n/u)) {
    const line = sourceLine.trim();

    if (line === '' || line.startsWith('#')) {
        continue;
    }

    const separator = line.indexOf('=');
    if (separator < 1) {
        continue;
    }

    const key = line.slice(0, separator).trim();
    if (!/^UAT_[A-Z0-9_]+$/u.test(key)) {
        continue;
    }

    let value = line.slice(separator + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
        value = value.slice(1, -1);
    }

    process.env[key] = value;
}

const baseURL = process.env.UAT_BASE_URL;
if (!baseURL) {
    throw new Error('UAT_BASE_URL is required in .env.uat.local.');
}

const parsedBaseURL = new URL(baseURL);
if (parsedBaseURL.protocol !== 'https:') {
    throw new Error('UAT_BASE_URL must use HTTPS.');
}

export default defineConfig({
    testDir: './tests/e2e',
    outputDir: 'test-results',
    fullyParallel: false,
    forbidOnly: true,
    workers: 1,
    retries: 0,
    timeout: 150_000,
    expect: {
        timeout: 10_000,
    },
    reporter: [
        ['list'],
        ['html', { outputFolder: 'playwright-report', open: 'never' }],
    ],
    use: {
        baseURL: parsedBaseURL.origin,
        viewport: { width: 1440, height: 900 },
        actionTimeout: 15_000,
        navigationTimeout: 45_000,
        ignoreHTTPSErrors: false,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        video: 'off',
    },
    projects: [
        {
            name: 'chromium',
            use: { browserName: 'chromium' },
        },
    ],
    metadata: {
        phase: 'Phase 0 — read-only authentication and access smoke',
        mutationCapability: 'disabled',
    },
});
