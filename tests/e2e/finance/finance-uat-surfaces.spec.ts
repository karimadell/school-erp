import { test, expect, loginAs } from '../fixtures/auth.fixture';
import { operationalAdministrator } from '../fixtures/personas';
import { expectNoLaravelFailure, navigateReadOnly } from '../helpers/navigation';

test.skip(process.env.UAT_EXPECT_FINANCE_REVIEW !== '1', 'Finance review changes are not deployed to the configured UAT environment.');

test('office user can discover the canonical read-only finance surfaces', async ({ page }) => {
    test.skip(!operationalAdministrator, 'No UAT office/admin credentials are configured.');
    await loginAs(page, operationalAdministrator!);

    const workspace = await navigateReadOnly(page, '/dashboard/finance/workspace');
    expect(workspace?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Финансовый центр' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Выставить счёт' })).toBeVisible();
    await expectNoLaravelFailure(page);

    const invoices = await navigateReadOnly(page, '/dashboard/invoices');
    expect(invoices?.status()).toBe(200);
    await expect(page.getByRole('link', { name: /Выставить счёт/u })).toBeVisible();
    await expectNoLaravelFailure(page);

    for (const path of ['/dashboard/finance/services', '/dashboard/finance/tariffs']) {
        const response = await navigateReadOnly(page, path);
        expect(response?.status()).toBe(200);
        await expectNoLaravelFailure(page);
    }
});
