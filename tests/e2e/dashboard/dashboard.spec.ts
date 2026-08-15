import { test, expect, loginAs } from '../fixtures/auth.fixture';
import { operationalAdministrator } from '../fixtures/personas';
import { expectNoLaravelFailure, navigateReadOnly } from '../helpers/navigation';

test('Main ERP dashboard exposes stable Russian navigation and KPI content', async ({ page }) => {
    test.skip(!operationalAdministrator, 'No UAT office/admin credentials are configured.');

    await loginAs(page, operationalAdministrator!);
    await navigateReadOnly(page, '/dashboard');

    await expect(page.locator('html')).toHaveAttribute('lang', 'ru');
    await expect(page.getByRole('heading', { name: 'Панель управления' })).toBeVisible();
    await expect(page.getByText(/Ученики|Студенты/u).first()).toBeVisible();
    await expect(page.getByText(/Финансы|Счета/u).first()).toBeVisible();
    await expectNoLaravelFailure(page);
});
