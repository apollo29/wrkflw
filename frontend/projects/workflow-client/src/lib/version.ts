/** Version des Clients. Wird beim Release aus dem Tag `client-vX.Y.Z` gesetzt. */
export const CLIENT_VERSION = '3.0.0-dev';

/** Hauptversion der HTTP-API, die dieser Client spricht. */
export const API_VERSION = 3;

/** Prüft, ob eine Engine-Version (z. B. aus `GET /version`) zu diesem Client passt. */
export function isCompatibleEngine(engineVersion: string): boolean {
  const major = Number.parseInt(engineVersion.split('.', 1)[0] ?? '', 10);
  return major === API_VERSION;
}
