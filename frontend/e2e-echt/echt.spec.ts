import { expect, Page, test } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { MAIL_LOG } from '../playwright.echt.config';

/** Offene, nicht archivierte Anträge laut Engine («Jetzt prüfen» des Seed-Triggers). */
async function openApplications(page: Page): Promise<number> {
  const res = await page.request.post('/api/data-sources/triggers/offene-antraege/check-now');
  expect(res.ok()).toBe(true);
  return ((await res.json()) as { matches: number }).matches;
}

async function completed(page: Page): Promise<number> {
  const res = await page.request.get('/api/instances?limit=1');
  return ((await res.json()) as { counts: { completed: number } }).counts.completed;
}

function mails(): { to: string; subject: string; body: string }[] {
  return existsSync(MAIL_LOG)
    ? readFileSync(MAIL_LOG, 'utf8')
        .split('\n')
        .filter(Boolean)
        .map((line) => JSON.parse(line))
    : [];
}

test.describe('Echtes Backend (Beispieldaten 9.13)', () => {
  test('Übersicht zeigt die Abläufe aus dem Seed mit Zählern', async ({ page }) => {
    await page.goto('/admin');
    const row = page.locator('[data-workflow="antrag-pruefung"]');
    await expect(row).toContainText('Antrag prüfen');
    await expect(row).toContainText('2 laufend · 12 gesamt');
    await expect(page.locator('[data-workflow="archivierung"]')).toBeVisible();
    await expect(page.locator('[data-workflow="mahnung"]')).toBeVisible();
  });

  test('Durchlauf bis zum Abschluss: Daten geschrieben, Mail versandt', async ({ page }) => {
    const before = await openApplications(page);
    const completedBefore = await completed(page);
    await page.goto('/durchspielen');
    await page.getByTestId('playground-workflow').selectOption('antrag-pruefung');
    await page.locator('[data-input="antrag_id"]').fill('A-1030');
    await page.getByTestId('playground-start').click();
    await expect(page.getByRole('heading', { level: 2, name: 'Antrag prüfen' })).toBeVisible();
    await expect(page.getByTestId('runner-display')).toHaveText('1250');
    await page.locator('[data-field="bemerkung"]').fill('Geprüft im echten Backend');
    await page.getByLabel('Unterlagen vollständig').check();
    await page.locator('[data-field="beleg"]').setInputFiles({
      name: 'beleg.pdf',
      mimeType: 'application/pdf',
      buffer: Buffer.from('%PDF'),
    });
    await expect(page.getByText('Hochgeladen: beleg.pdf')).toBeVisible();
    await page.getByRole('button', { name: 'Freigeben' }).click();
    await expect(page.getByRole('heading', { level: 2, name: 'Zusammenfassung' })).toBeVisible();
    await page.getByRole('button', { name: 'Bestätigen' }).click();
    await expect(page.getByTestId('runner-state')).toContainText('Der Ablauf ist abgeschlossen.');

    // write_data hat den Status gesetzt: A-1030 ist nicht mehr offen.
    expect(await openApplications(page)).toBe(before - 1);
    const mail = mails().find((m) => m.subject.includes('A-1030'));
    expect(mail?.to).toBe('person@example.org');
    expect(mail?.body).toContain('A-1030');

    // Der Durchlauf und der gestartete Unterablauf «archivierung» sind abgeschlossen.
    expect(await completed(page)).toBe(completedBefore + 2);
  });

  test('Editor: Entwurf speichern ergibt eine neue Version in der Übersicht', async ({ page }) => {
    await page.goto('/admin/workflows/antrag-pruefung');
    await expect(page.getByTestId('editor-version')).toHaveText('v3');
    await page.getByTestId('editor-name').fill('Antrag prüfen (Entwurf)');
    await page.getByTestId('editor-status').getByRole('radio', { name: 'Entwurf' }).click();
    await page.getByTestId('editor-save').click();
    await expect(page.getByTestId('editor-message')).toHaveText(
      'Gespeichert: antrag-pruefung v4 (entwurf).',
    );
    await page.goto('/admin');
    await expect(page.locator('[data-workflow="antrag-pruefung"]')).toContainText('v4');
  });

  test('Vorlagen: Verwendung ablesen und neue Vorlage speichern', async ({ page }) => {
    await page.goto('/admin/vorlagen?id=antrag-ok');
    await expect(page.getByTestId('templates-usage')).toContainText('bestaetigung');
    await page.getByTestId('templates-new').click();
    await page.getByTestId('templates-id').fill('echt-test');
    await page.getByTestId('templates-name').fill('Echt');
    await page.getByTestId('templates-subject').fill('Hallo {{antrag_id}}');
    await page.locator('.wf-html__content .ProseMirror').click();
    await page.keyboard.type('Inhalt');
    await page.getByTestId('templates-save').click();
    await expect(page.getByTestId('templates-message')).toContainText('Gespeichert: echt-test.');
    const res = await page.request.get('/api/templates/echt-test');
    expect(((await res.json()) as { subject: string }).subject).toBe('Hallo {{antrag_id}}');
  });

  test('Datenquellen: interne Adresse abgewiesen, Zugangsdaten nie in Antworten', async ({
    page,
  }) => {
    const responses: string[] = [];
    page.on('response', async (r) => {
      if (r.url().includes('/api/data-sources')) {
        responses.push(await r.text().catch(() => ''));
      }
    });
    await page.goto('/admin/datenquellen');
    await expect(page.locator('[data-item="antrag-api"]')).toBeVisible();
    await page.getByTestId('ds-new').click();
    await page.getByTestId('conn-id').fill('intern');
    await page.getByTestId('conn-name').fill('Intern');
    await page.getByTestId('conn-url').fill('https://127.0.0.1');
    await page.getByTestId('conn-auth').selectOption('bearer');
    await page.getByTestId('conn-secret').fill('geheim-echt-1');
    await page.getByTestId('ds-save').click();
    await expect(page.locator('wf-field').filter({ hasText: 'Basis-URL' })).toContainText(
      /127\.0\.0\.1|intern|gesperrt|nicht erlaubt/i,
    );

    await page.goto('/admin/datenquellen?bereich=verbindungen&id=antrag-api');
    await expect(page.getByTestId('conn-stored')).toHaveText('Hinterlegt');
    await page.getByTestId('conn-replace').click();
    await page.getByTestId('conn-secret').fill('geheim-echt-2');
    await page.getByTestId('conn-replace-apply').click();
    await expect(page.getByTestId('ds-message')).toContainText('Zugangsdaten ersetzt.');
    await page.getByTestId('ds-audit').locator('summary').click();
    await expect(page.getByTestId('ds-audit')).toContainText('Zugangsdaten ersetzt');
    for (const body of responses) {
      expect(body).not.toContain('geheim-echt');
    }
  });

  test('Trigger: Takt unter 5 Minuten wird abgewiesen, «Jetzt prüfen» zählt', async ({ page }) => {
    await page.goto('/admin/datenquellen?bereich=trigger&id=offene-antraege');
    await expect(page.getByTestId('trigger-model')).toHaveValue('antrag');
    await page.getByTestId('trigger-check').click();
    await expect(page.getByTestId('trigger-check-result')).toContainText(
      'Datensätze treffen aktuell zu.',
    );
    await page.getByTestId('trigger-every').fill('3');
    await page.getByTestId('ds-save').click();
    await expect(page.getByText('Mindestens 5 Minuten.').first()).toBeVisible();
    await expect(page.getByTestId('ds-message')).toContainText(
      'Bitte die markierten Angaben prüfen.',
    );
  });
});
