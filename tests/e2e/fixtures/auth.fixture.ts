import { expect, test as base, type Page, type TestInfo } from '@playwright/test';
import { configuredSecrets, type UatPersona } from './personas';

const allowedSessionPosts = new Set(['/login', '/logout']);
const configuredUatOrigin = new URL(process.env.UAT_BASE_URL!).origin;
const livewireUpdatePath = /^\/livewire(?:-[a-z0-9]+)?\/update$/iu;

type JsonRecord = Record<string, unknown>;

function isRecord(value: unknown): value is JsonRecord {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isStrictlyReadOnlyLivewirePayload(rawPayload: string | null | undefined): boolean {
    if (!rawPayload) {
        return false;
    }

    let payload: unknown;
    try {
        payload = JSON.parse(rawPayload);
    } catch {
        return false;
    }

    if (!isRecord(payload) || !Array.isArray(payload.components) || payload.components.length === 0) {
        return false;
    }

    return payload.components.every((component) => {
        if (!isRecord(component)
            || typeof component.snapshot !== 'string'
            || !isRecord(component.updates)
            || Object.keys(component.updates).length !== 0
            || !Array.isArray(component.calls)) {
            return false;
        }

        return component.calls.every((call) => isRecord(call)
            && call.method === '__lazyLoad'
            && Array.isArray(call.params)
            && call.params.length === 1
            && typeof call.params[0] === 'string'
            && isRecord(call.metadata));
    });
}

export function isPhaseZeroRequestAllowed(
    method: string,
    rawUrl: string,
    postData?: string | null,
    headers: Record<string, string> = {},
): boolean {
    const normalizedMethod = method.toUpperCase();

    if (['GET', 'HEAD', 'OPTIONS'].includes(normalizedMethod)) {
        return true;
    }

    let url: URL;
    try {
        url = new URL(rawUrl);
    } catch {
        return false;
    }

    if (url.origin !== configuredUatOrigin) {
        return false;
    }

    if (url.pathname.startsWith('/cdn-cgi/')) {
        return true;
    }

    if (normalizedMethod === 'POST'
        && livewireUpdatePath.test(url.pathname)
        && headers['x-livewire'] === '1'
        && headers['content-type']?.toLowerCase().startsWith('application/json')) {
        return isStrictlyReadOnlyLivewirePayload(postData);
    }

    return normalizedMethod === 'POST' && allowedSessionPosts.has(url.pathname);
}

function safeUrl(rawUrl: string): string {
    try {
        const url = new URL(rawUrl);
        return ['http:', 'https:'].includes(url.protocol)
            ? `${url.origin}${url.pathname}`
            : `${url.protocol}${url.pathname}`;
    } catch {
        return '[invalid URL]';
    }
}

function redact(value: string): string {
    return configuredSecrets().reduce(
        (redacted, secret) => redacted.replaceAll(secret, '[REDACTED]'),
        value,
    );
}

async function attachDiagnostics(testInfo: TestInfo, name: string, entries: string[]): Promise<void> {
    if (entries.length === 0) {
        return;
    }

    await testInfo.attach(name, {
        body: Buffer.from(redact(entries.join('\n')), 'utf8'),
        contentType: 'text/plain',
    });
}

type PhaseZeroFixtures = {
    phaseZeroSafety: void;
    diagnostics: void;
};

export const test = base.extend<PhaseZeroFixtures>({
    phaseZeroSafety: [async ({ page }, use) => {
        const blockedRequests: string[] = [];

        await page.route('**/*', async (route) => {
            const request = route.request();
            const method = request.method().toUpperCase();

            if (!isPhaseZeroRequestAllowed(method, request.url(), request.postData(), request.headers())) {
                blockedRequests.push(`${method} ${safeUrl(request.url())}`);
                await route.abort('blockedbyclient');
                return;
            }

            await route.continue();
        });

        await use();

        expect(blockedRequests, `Phase 0 blocked mutating requests:\n${blockedRequests.join('\n')}`).toEqual([]);
    }, { auto: true }],

    diagnostics: [async ({ page }, use, testInfo) => {
        const consoleErrors: string[] = [];
        const pageErrors: string[] = [];
        const failedRequests: string[] = [];

        page.on('console', (message) => {
            if (message.type() === 'error') {
                consoleErrors.push(message.text());
            }
        });
        page.on('pageerror', (error) => pageErrors.push(error.message));
        page.on('requestfailed', (request) => {
            failedRequests.push(`${request.method()} ${safeUrl(request.url())}: ${request.failure()?.errorText ?? 'unknown'}`);
        });

        await use();

        if (testInfo.status !== testInfo.expectedStatus) {
            try {
                await testInfo.attach('failure-screenshot', {
                    body: await page.screenshot({ fullPage: true }),
                    contentType: 'image/png',
                });
            } catch {
                // Navigation can fail before Chromium creates a capturable surface.
            }
            await attachDiagnostics(testInfo, 'final-url', [safeUrl(page.url())]);
            await attachDiagnostics(testInfo, 'browser-console-errors', consoleErrors);
            await attachDiagnostics(testInfo, 'page-errors', pageErrors);
            await attachDiagnostics(testInfo, 'failed-network-requests', failedRequests);
        }
    }, { auto: true }],
});

export { expect };

export async function loginAs(page: Page, persona: UatPersona): Promise<void> {
    if (!persona.available || !persona.email || !persona.password) {
        throw new Error(`UAT persona ${persona.key} is not configured.`);
    }

    const { navigateToLogin } = await import('../helpers/navigation');
    await navigateToLogin(page);
    await page.getByRole('textbox', { name: /^(Email|Электронная почта|البريد الإلكتروني)$/i }).fill(persona.email);
    await page.getByRole('textbox', { name: /^(Пароль|Password|كلمة المرور)$/i }).fill(persona.password);
    const loginButton = page.getByRole('button', { name: /Войти|Log in|تسجيل الدخول/i });

    try {
        await loginButton.click();
    } catch (clickError) {
        try {
            await page.waitForURL((url) => !/\/login(?:\?|$)/u.test(url.pathname), { timeout: 60_000 });
        } catch {
            throw clickError;
        }
    }

    await expect(page).not.toHaveURL(/\/login(?:\?|$)/u, { timeout: 60_000 });
}
