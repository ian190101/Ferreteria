import { expect, test } from '@playwright/test';

const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;

test.describe('QA visual critico', () => {
    test.beforeEach(async ({ page }) => {
        test.skip(!email || !password, 'Define E2E_EMAIL y E2E_PASSWORD para ejecutar pruebas autenticadas.');

        await page.goto('/login');
        await expect(page.getByRole('heading', { name: /iniciar sesion|iniciar sesión/i })).toBeVisible();
        await page.getByLabel(/correo/i).fill(email);
        await page.getByLabel(/contraseña/i).fill(password);
        await page.getByRole('button', { name: /iniciar sesion|iniciar sesión/i }).click();
        await expect(page).not.toHaveURL(/\/login$/);
    });

    test('el sistema maestro no aparece dentro de demo completa', async ({ page }) => {
        await page.goto('/system-superadmin/business-profiles');

        const enterDemo = page.getByRole('button', { name: /entrar a demo completa/i });
        test.skip(!(await enterDemo.isVisible().catch(() => false)), 'No hay demo completa disponible para este entorno.');

        await enterDemo.click();
        await expect(page.getByText(/modo demo completo activo/i)).toBeVisible();
        await expect(page.getByText(/configuracion de negocio|configuración de negocio/i)).toHaveCount(0);

        await page.goto('/system-superadmin/business-profiles');
        await expect(page).toHaveURL(/\/dashboard$/);
        await expect(page.getByText(/configurador maestro no esta disponible|configurador maestro no está disponible/i)).toBeVisible();
    });

    test('la plantilla mantiene estilo claro aunque la app este en modo oscuro', async ({ page }) => {
        await page.goto('/sales');
        const firstDocument = page.locator('a[href*="/sales/"]').first();
        test.skip(!(await firstDocument.isVisible().catch(() => false)), 'No hay documentos de venta para previsualizar.');

        await firstDocument.click();
        await expect(page.locator('.ticket-paper')).toBeVisible();
        await setDarkMode(page);

        const paper = page.locator('.ticket-paper');
        await expect(paper).toHaveCSS('background-color', 'rgb(255, 255, 255)');
        await expect(paper).toHaveCSS('color', 'rgb(0, 0, 0)');

        const firstCell = page.locator('.ticket-paper td').first();
        if (await firstCell.count()) {
            await expect(firstCell).not.toHaveCSS('position', 'sticky');
        }
    });

    test('POS rapido mantiene contraste de metodo de pago en modo oscuro', async ({ page }) => {
        await page.goto('/pos');
        await setDarkMode(page);
        await expect(page.getByText(/metodo de pago|método de pago/i)).toBeVisible();

        const paymentSelect = page.locator('select').filter({ has: page.locator('option') }).last();
        await expect(paymentSelect).toHaveCSS('background-color', 'rgb(255, 255, 255)');
        await expect(paymentSelect).toHaveCSS('color', 'rgb(15, 23, 42)');
    });
});

async function setDarkMode(page) {
    await page.evaluate(() => {
        localStorage.setItem('appearance-mode', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    });
    await page.reload();
}
