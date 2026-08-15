import { test, expect, loginAs } from '../fixtures/auth.fixture';
import { personas } from '../fixtures/personas';
import { expectForbidden, expectTeacherAdministrativeRedirect } from '../helpers/access';
import { navigateReadOnly } from '../helpers/navigation';

test.beforeEach(async ({ page }) => {
    test.skip(!personas.teacher.available, 'No UAT teacher credentials are configured.');
    await loginAs(page, personas.teacher);
    await expect(page).toHaveURL(/\/teacher(?:\/)?$/u);
});

test('teacher is redirected away from direct Main ERP finance URLs', async ({ page }) => {
    for (const path of ['/dashboard/finance/workspace', '/dashboard/invoices']) {
        const response = await navigateReadOnly(page, path);
        await expectTeacherAdministrativeRedirect(page, response);
    }
});

test('teacher receives the current exact forbidden response from technical admin', async ({ page }) => {
    const response = await page.goto('/admin', { waitUntil: 'domcontentloaded' });

    expectForbidden(response);
    await expect(page).toHaveURL(/\/admin(?:\/)?$/u);
});
