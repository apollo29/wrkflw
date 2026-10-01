/**
 * Die Linien einer Verzweigung in der Ablauf-Vorschau — als SVG-Pfade.
 *
 * Eigenes Modul und ohne Angular: die Zahlen haengen an der Anzahl der Spuren
 * (zwei Zweige brauchen andere Kurven als vier), und eine Vorlage kann das
 * nicht ausrechnen. Ein festes Bild je Anzahl waere eine Grenze, die der Editor
 * nicht hat — Uebergaenge sind beliebig viele. Hier liegt es isoliert und ist
 * damit nachrechenbar (flow-geometry.spec.ts) statt nur ansehbar.
 *
 * Die Form stammt aus dem Entwurf «Workflow-Builder Screen-Flow», Abschnitt
 * 01b: aus dem verzweigenden Schritt faechert ein Buendel auf, je Spur eine
 * Linie, und am Ende fuehrt ein zweites Buendel sie mit einer Pfeilspitze
 * wieder zusammen.
 */

/**
 * Hoehe einer Spur in px.
 *
 * Aus dem Entwurf und nicht frei gewaehlt: an dieser Zahl haengen die Kurven,
 * die Mitte, in der beide Buendel ansetzen, und die Zeilenhoehe `.wfb__lane`
 * in workflow-builder.component.css. Wer sie aendert, aendert alle drei.
 */
export const LANE_H = 56;

/**
 * Die Mitten der Spuren und die Mitte des Buendels.
 *
 * Bei einer UNGERADEN Anzahl Spuren faellt die mittlere genau auf die Mitte.
 * Dort ist die Linie gerade statt gebogen — kein Sonderfall, den man
 * wegabstrahieren sollte: eine Kurve von der Mitte zur Mitte waere ein
 * sichtbarer Knick.
 */
export function laneGeometry(lanes: number): { height: number; mid: number; ys: number[] } {
  const height = lanes * LANE_H;

  return {
    height,
    mid: height / 2,
    ys: Array.from({ length: lanes }, (_, i) => LANE_H / 2 + LANE_H * i),
  };
}

/** Das Buendel, das aus dem verzweigenden Schritt in die Spuren laeuft. */
export function forkPaths(lanes: number): string[] {
  const { mid, ys } = laneGeometry(lanes);

  return ys.map((y) =>
    y === mid ? `M0 ${mid}H56` : `M0 ${mid}h8C28 ${mid} 28 ${y} 48 ${y}h8`,
  );
}

/**
 * Das Buendel, das die Spuren wieder zusammenfuehrt — mit Pfeilspitze, weil
 * hier eine Richtung gemeint ist und nicht nur eine Verbindung.
 *
 * Die Spitze ist der letzte Pfad und sitzt auf der Mitte, dort wo das Buendel
 * in das Plaettchen der Zusammenfuehrung laeuft.
 */
export function mergePaths(lanes: number): string[] {
  const { mid, ys } = laneGeometry(lanes);
  const linien = ys.map((y) =>
    y === mid ? `M0 ${mid}H50` : `M0 ${y}h8C28 ${y} 28 ${mid} 48 ${mid}h2`,
  );

  return [...linien, `M45 ${mid - 4.5} 50 ${mid}l-5 4.5`];
}
