import { test, expect, loginAs } from '../fixtures/auth.fixture';
import { operationalAdministrator } from '../fixtures/personas';
import { expectNoLaravelFailure, navigateReadOnly } from '../helpers/navigation';

test('authorized operational administrator can reach the Main ERP dashboard', async ({ page }) => {
    test.skip(!operationalAdministrator, 'No UAT office/admin credentials are configured.');

    await loginAs(page, operationalAdministrator!);
    const response = await navigateReadOnly(page, '/dashboard');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/dashboard(?:\/)?$/u);
    await expect(page.getByRole('heading', { name: /Панель управления|Dashboard|لوحة التحكم/i })).toBeVisible();
    await expect(
        page.getByRole('navigation', {
            name: /Основная навигация|Main navigation|التنقل الرئيسي/i,
        }),
    ).toBeVisible();
    await expectNoLaravelFailure(page);
});
