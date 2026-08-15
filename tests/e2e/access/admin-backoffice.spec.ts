import { test, expect, loginAs } from '../fixtures/auth.fixture';
import { personas, technicalAdministrator } from '../fixtures/personas';
import { expectForbidden } from '../helpers/access';
import { expectNoLaravelFailure } from '../helpers/navigation';

test('authorized technical administrator can reach the Filament back office', async ({ page }) => {
    test.skip(!technicalAdministrator, 'No UAT super-admin/admin credentials are configured.');

    await loginAs(page, technicalAdministrator!);
    const response = await page.goto('/admin', { waitUntil: 'domcontentloaded' });

    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/admin(?:\/)?$/u);
    await expect(page.locator('main')).toBeVisible();
    await expectNoLaravelFailure(page);
});

test('teacher cannot reach the Filament back office', async ({ page }) => {
    test.skip(!personas.teacher.available, 'No UAT teacher credentials are configured.');

    await loginAs(page, personas.teacher);
    const response = await page.goto('/admin', { waitUntil: 'domcontentloaded' });

    expectForbidden(response);
    await expect(page).toHaveURL(/\/admin(?:\/)?$/u);
});
