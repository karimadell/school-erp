import { test, expect, loginAs } from '../fixtures/auth.fixture';
import { operationalAdministrator } from '../fixtures/personas';

test('administrative user can log out and loses the protected session', async ({ page }) => {
    test.skip(!operationalAdministrator, 'No UAT office/admin credentials are configured.');

    await loginAs(page, operationalAdministrator!);
    await page.goto('/dashboard');
    await page.locator('header button[data-bs-toggle="dropdown"]').last().click();
    await page.getByRole('button', { name: /Выйти|Выход|Log out|تسجيل الخروج/i }).click();

    await expect(page).toHaveURL(/\/login(?:\?|$)/u);
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login(?:\?|$)/u);
});
