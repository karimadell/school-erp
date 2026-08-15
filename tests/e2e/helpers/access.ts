import { expect, type Page, type Response } from '@playwright/test';

export async function expectOk(response: Response | null, expectedPath: RegExp): Promise<void> {
    expect(response?.status()).toBe(200);
    expect(new URL(response?.url() ?? '').pathname).toMatch(expectedPath);
}

export async function expectTeacherAdministrativeRedirect(page: Page, response: Response | null): Promise<void> {
    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/teacher(?:\/)?$/u);
}

export function expectForbidden(response: Response | null): void {
    expect(response?.status()).toBe(403);
}
