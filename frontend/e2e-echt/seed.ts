import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
import { MAIL_LOG } from '../playwright.echt.config';

/** Frischer Seed 9.13 vor jedem Lauf; der Seed leert auch das Mail-Log. */
export default function seed(): void {
  execFileSync('php', [join(__dirname, '../../backend/demo/seed.php')], {
    stdio: 'inherit',
    env: { ...process.env, WF_DEMO_MAIL_LOG: MAIL_LOG },
  });
}
