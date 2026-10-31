# Frontend (Angular-Workspace)

- `projects/workflow-client` — Library `@apollo29/workflow-client`
- `projects/demo` — Demo-App, bindet die Library über `tsconfig`-Pfade direkt aus dem Quellcode ein
- `e2e/` — Playwright-Abnahme

```bash
npm ci
npm test                       # Vitest (Library und Demo)
npx ng build workflow-client
npx ng build demo
npx ng serve demo              # http://localhost:4200
npx playwright test            # startet die Demo selbst; lokales Chromium per PW_CHROMIUM_PATH
```
