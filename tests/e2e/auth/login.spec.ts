import { test, expect, loginAs } from '../fixtures/auth.fixture';
import { personas, technicalAdministrator } from '../fixtures/personas';
import { expectNoLaravelFailure, navigateToLogin } from '../helpers/navigation';

test('login page loads over HTTPS with accessible authentication controls', async ({ page }) => {
    const response = await navigateToLogin(page);

    expect(response?.status()).toBe(200);
    expect(page.url()).toMatch(/^https:\/\//u);
    await expect(page.getByRole('textbox', { name: /^(Email|Электронная почта|البريد الإلكتروني)$/i })).toBeVisible();
    await expect(page.getByRole('textbox', { name: /^(Пароль|Password|كلمة المرور)$/i })).toBeVisible();
    await expect(page.getByRole('button', { name: /Войти|Log in|تسجيل الدخول/i })).toBeVisible();
    await expectNoLaravelFailure(page);
});

test('valid administrative user login reaches an administrative workspace', async ({ page }) => {
    test.skip(!technicalAdministrator, 'No UAT super-admin/admin credentials are configured.');

    await loginAs(page, technicalAdministrator!);
    await expect(page).toHaveURL(/\/(dashboard|admin)(?:\/)?$/u);
});

test('valid teacher login reaches the Teacher Portal', async ({ page }) => {
    test.skip(!personas.teacher.available, 'No UAT teacher credentials are configured.');

    await loginAs(page, personas.teacher);
    await expect(page).toHaveURL(/\/teacher(?:\/)?$/u);
});

test('wrong password does not authenticate', async ({ page }) => {
    await navigateToLogin(page);
    await page.getByRole('textbox', { name: /^(Email|Электронная почта|البريد الإلكتروني)$/i }).fill('phase0-nonexistent@example.invalid');
    await page.getByRole('textbox', { name: /^(Пароль|Password|كلمة المرور)$/i }).fill('deliberately-invalid-phase0-password');
    await page.getByRole('button', { name: /Войти|Log in|تسجيل الدخول/i }).click();

    await expect(page).toHaveURL(/\/login(?:\?|$)/u);
    await expect(page.getByRole('textbox', { name: /^(Email|Электронная почта|البريد الإلكتروني)$/i })).toBeVisible();
});
