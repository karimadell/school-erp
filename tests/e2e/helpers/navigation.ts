import { expect, type Locator, type Page, type Response } from '@playwright/test';

const cloudflareChallenge = /Just a moment|Verifying you are human|Performing security verification|Enable JavaScript and cookies|Проверка безопасности/i;

async function cloudflareInterstitialIsVisible(page: Page): Promise<boolean> {
    const challengeElement = page.locator([
        '#challenge-running',
        '#challenge-stage',
        'iframe[src*="/cdn-cgi/challenge-platform/"]',
        '[data-translate="checking_browser"]',
    ].join(', '));

    return await challengeElement.first().isVisible().catch(() => false)
        || await page.getByText(cloudflareChallenge).first().isVisible().catch(() => false)
        || cloudflareChallenge.test(await page.title().catch(() => ''));
}

export async function waitForApplicationSurface(
    page: Page,
    surface: Locator,
    description: string,
    timeout = 60_000,
): Promise<void> {
    try {
        await expect(surface, `Waiting for ${description}`).toBeVisible({ timeout });
    } catch (error) {
        if (await cloudflareInterstitialIsVisible(page)) {
            throw new Error(`Cloudflare challenge did not resolve within ${timeout / 1000} seconds while waiting for ${description}.`, { cause: error });
        }

        throw new Error(`The expected ERP surface did not appear within ${timeout / 1000} seconds: ${description}.`, { cause: error });
    }
}

export async function navigateReadOnly(page: Page, path: string): Promise<Response | null> {
    let response: Response | null = null;
    let lastError: unknown;

    for (let attempt = 1; attempt <= 2; attempt++) {
        try {
            response = await page.goto(path, { waitUntil: 'commit', timeout: 45_000 });
            break;
        } catch (error) {
            lastError = error;
        }
    }

    if (!response) {
        if (await cloudflareInterstitialIsVisible(page)) {
            throw new Error(`Cloudflare challenge did not resolve while navigating to ${path} after two bounded attempts.`, { cause: lastError });
        }

        throw new Error(`UAT navigation did not commit for ${path} after two bounded attempts.`, { cause: lastError });
    }

    expect(response, `Navigation to ${path} did not return a main-document response.`).not.toBeNull();
    expect(response?.status(), `Navigation to ${path} returned a server error.`).toBeLessThan(500);

    return response;
}

export async function navigateToLogin(page: Page): Promise<Response | null> {
    const response = await navigateReadOnly(page, '/login');
    const emailField = page.getByRole('textbox', { name: /^(Email|Электронная почта|البريد الإلكتروني)$/i });
    const passwordField = page.getByRole('textbox', { name: /^(Пароль|Password|كلمة المرور)$/i });

    await waitForApplicationSurface(
        page,
        emailField,
        'the School ERP login form',
    );
    await expect(passwordField).toBeVisible();
    await expect(page.getByRole('button', { name: /Войти|Log in|تسجيل الدخول/i })).toBeVisible();

    return response;
}

export async function expectNoLaravelFailure(page: Page): Promise<void> {
    const body = page.locator('body');
    await expect(body).not.toContainText(/Whoops|Illuminate\\|Symfony\\Component|Stack trace|SQLSTATE\[/i);
}
