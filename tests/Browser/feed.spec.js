// @ts-check
/**
 * Frontend-Test: Feed anlegen, bearbeiten, Adresse rotieren.
 *
 * Voraussetzung (legt die CI an): Test-Datenbank mit dem Kanal
 * UCbrowsertestchannel0000 ("Browsertest-Kanal"). /api/resolve wird
 * abgefangen, damit kein Request zu YouTube geht; alles andere läuft gegen
 * das echte Backend.
 */
const { test, expect } = require('@playwright/test');

const CHANNEL = {
    channel_id: 'UCbrowsertestchannel0000',
    title: 'Browsertest-Kanal',
    handle: 'browsertest',
    avatar_url: null,
    channel_url: 'https://www.youtube.com/channel/UCbrowsertestchannel0000',
    include_long: true,
    include_shorts: true,
};

/** Sammelt Konsolenfehler und CSP-Verstöße - beides lässt den Test scheitern. */
function watchConsole(page) {
    const problems = [];
    page.on('console', (message) => {
        if (message.type() === 'error') {
            problems.push(message.text());
        }
    });
    page.on('pageerror', (error) => problems.push(error.message));

    return problems;
}

/** Nur eigene Requests - nichts darf zu fremden Servern gehen. */
function watchRequests(page) {
    const foreign = [];
    page.on('request', (request) => {
        const url = new URL(request.url());
        if (url.hostname !== '127.0.0.1' && url.protocol !== 'data:') {
            foreign.push(request.url());
        }
    });

    return foreign;
}

test('Startseite lädt ohne Fehler und ohne CSP-Verstöße', async ({ page }) => {
    const problems = watchConsole(page);
    const response = await page.goto('/');

    expect(response?.status()).toBe(200);
    expect(response?.headers()['content-security-policy']).toContain("script-src 'self'");
    await expect(page.locator('#builder')).toBeVisible();
    await expect(page.locator('#save-btn')).toBeVisible();
    expect(problems).toEqual([]);
});

test('Feed anlegen, bearbeiten und Adresse rotieren', async ({ page }) => {
    const problems = watchConsole(page);
    const foreign = watchRequests(page);

    await page.route('**/api/resolve', async (route) => {
        await route.fulfill({ json: { channels: [CHANNEL], errors: [] } });
    });

    // 1. Kanal hinzufügen
    await page.goto('/');
    await page.fill('#channel-input', '@browsertest');
    await page.click('#resolve-btn');
    await expect(page.locator('#channel-list')).toContainText('Browsertest-Kanal');
    await expect(page.locator('#channel-counter')).toContainText('1');

    // 2. Speichern -> echte API legt den Feed an
    await page.fill('#feed-title', 'Mein Browsertest');
    await page.click('#save-btn');
    await expect(page.locator('#result')).toBeVisible();

    const feedUrl = await page.inputValue('#feed-url');
    const editUrl = await page.inputValue('#edit-url');
    expect(feedUrl).toMatch(/\/f\/[A-Za-z0-9]{6,32}$/);
    expect(editUrl).toMatch(/\/e\/[A-Za-z0-9]{16,64}$/);

    // 3. Der Feed ist abrufbar
    const feed = await page.request.get(feedUrl);
    expect(feed.status()).toBe(200);
    expect(await feed.text()).toContain('Mein Browsertest');

    // 4. Editor öffnen: Kanal und Titel sind da
    await page.goto(editUrl);
    await expect(page.locator('h1')).toContainText('Mein Browsertest');
    await expect(page.locator('#channel-list')).toContainText('Browsertest-Kanal');

    // 5. Adresse rotieren (Bestätigungsdialog annehmen)
    page.once('dialog', (dialog) => dialog.accept());
    await page.click('#rotate-btn');
    await expect(page.locator('#feed-url')).not.toHaveValue(feedUrl);
    const rotatedUrl = await page.inputValue('#feed-url');

    expect((await page.request.get(feedUrl)).status()).toBe(404);
    expect((await page.request.get(rotatedUrl)).status()).toBe(200);
    // Der Bearbeiten-Link bleibt derselbe
    expect(await page.inputValue('#edit-url')).toBe(editUrl);

    expect(problems).toEqual([]);
    expect(foreign).toEqual([]);
});

test('Fehler der API werden angezeigt statt verschluckt', async ({ page }) => {
    await page.route('**/api/resolve', async (route) => {
        await route.fulfill({ status: 422, json: { error: 'Bitte mindestens einen Kanal eingeben.' } });
    });

    await page.goto('/');
    await page.fill('#channel-input', 'x');
    await page.click('#resolve-btn');

    await expect(page.getByText('Bitte mindestens einen Kanal eingeben.')).toBeVisible();
});

test('Editor: Bereiche 1-3 einklappen und Zustand merken', async ({ page }) => {
    const problems = watchConsole(page);

    // Feed direkt über die API anlegen (der Kanal liegt in der Test-DB).
    const created = await page.request.post('/api/feeds', {
        data: { title: 'Einklapp-Test', channels: [{ channel_id: CHANNEL.channel_id }] },
    });
    expect(created.status()).toBe(201);
    const { edit_url: editUrl } = await created.json();

    // Startseite hat keinen Pfeil - nur der Editor.
    await page.goto('/');
    await expect(page.locator('#collapse-btn')).toHaveCount(0);

    await page.goto(editUrl);
    const toggle = page.locator('#collapse-btn');
    const steps  = page.locator('#builder-steps');
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(steps).toBeVisible();

    // Einklappen: Schritte weg, Adressen und Vorschau bleiben.
    await toggle.click();
    await expect(steps).toBeHidden();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('#result')).toBeVisible();

    // Nach dem Neuladen weiter eingeklappt - schon im HTML vom Server.
    const reloaded = await page.reload();
    expect(await reloaded?.text()).toContain('id="builder-steps" hidden');
    await expect(steps).toBeHidden();

    // Wieder aufklappen, auch das bleibt.
    await toggle.click();
    await expect(steps).toBeVisible();
    await page.reload();
    await expect(steps).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');

    expect(problems).toEqual([]);
});
