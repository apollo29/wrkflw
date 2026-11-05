import { defineConfig, devices } from '@playwright/test';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/**
 * Abnahme gegen das echte Backend (PLAN.md, Phase 12): Demo mit Konfiguration «echt»,
 * Engine über `php -S` und MariaDB mit den Beispieldaten 9.13 (`backend/demo/seed.php`).
 *
 * Voraussetzung: MariaDB läuft und `WF_DB_DSN` zeigt auf eine Datenbank mit «demo» im Namen.
 * Die Tests teilen sich die Datenbank, deshalb ein Worker und vorher ein frischer Seed.
 */
const port = Number(process.env['E2E_PORT'] ?? 4320);
const apiPort = Number(process.env['E2E_API_PORT'] ?? 8080);
const executablePath = process.env['PW_CHROMIUM_PATH'] || undefined;
export const MAIL_LOG =
  process.env['WF_DEMO_MAIL_LOG'] ?? join(tmpdir(), 'wrkflw-demo-e2e-mails.jsonl');
const env = {
  ...(process.env as Record<string, string>),
  WF_DEMO_MAIL_LOG: MAIL_LOG,
  WF_API_OPEN: '1',
  WF_API_BOOTSTRAP: join(__dirname, '../backend/demo/api.php'),
};

export default defineConfig({
  testDir: './e2e-echt',
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env['CI'],
  retries: 0,
  reporter: process.env['CI']
    ? [['list'], ['html', { open: 'never', outputFolder: 'playwright-report-echt' }]]
    : 'list',
  globalSetup: './e2e-echt/seed.ts',
  use: {
    baseURL: `http://localhost:${port}`,
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'echt', use: { ...devices['Desktop Chrome'], launchOptions: { executablePath } } },
  ],
  webServer: [
    {
      // Erst Schema und Beispieldaten, sonst antwortet /workflows auf einer leeren Datenbank mit 500.
      command: `php ../backend/demo/seed.php && php -S 127.0.0.1:${apiPort} -t ../backend/api`,
      url: `http://127.0.0.1:${apiPort}/workflows`,
      env,
      // php -S meldet jede Anfrage auf stderr; Fehler zeigt der Test selbst.
      stderr: 'ignore',
      reuseExistingServer: !process.env['CI'],
      timeout: 30_000,
    },
    {
      command: `npx ng serve demo -c echt --port ${port}`,
      url: `http://localhost:${port}`,
      reuseExistingServer: !process.env['CI'],
      timeout: 180_000,
    },
  ],
});
