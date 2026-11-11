// @ts-check
/**
 * Browser-Tests (Playwright).
 *
 * Startet den eingebauten PHP-Server gegen eine Test-Datenbank. Die
 * config.inc.php dafür legt die CI an (Wegwerf-Container) - nie gegen die
 * echte Konfiguration laufen lassen. Externe Dienste werden nicht
 * angefragt: /api/resolve wird im Test abgefangen.
 */
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
    testDir: 'tests/Browser',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter: process.env.CI ? 'list' : 'line',
    use: {
        baseURL: 'http://127.0.0.1:8099',
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
    webServer: {
        command: 'php -S 127.0.0.1:8099 -t public public/index.php',
        url: 'http://127.0.0.1:8099/',
        reuseExistingServer: false,
        timeout: 30000,
    },
});
