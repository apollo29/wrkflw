import { forkPaths, LANE_H, laneGeometry, mergePaths } from './flow-geometry';

/**
 * Die Linien der Verzweigung, nachgerechnet.
 *
 * Die erwarteten Pfade unten sind von Hand aus dem Entwurf abgeleitet
 * («Workflow-Builder Screen-Flow», 01b) und nicht aus der Umsetzung
 * abgeschrieben. Genau darum geht es: ein Bild sieht auch dann plausibel aus,
 * wenn eine Kurve einen Pixel daneben ansetzt — hier steht, wo sie ansetzen
 * soll.
 */
describe('flow-geometry', () => {
  it('legt die Spuren in 56er-Schritten und die Mitte dazwischen', () => {
    expect(LANE_H).toBe(56);
    expect(laneGeometry(2)).toEqual({ height: 112, mid: 56, ys: [28, 84] });
    expect(laneGeometry(3)).toEqual({ height: 168, mid: 84, ys: [28, 84, 140] });
  });

  it('faechert zwei Spuren symmetrisch aus der Mitte auf', () => {
    expect(forkPaths(2)).toEqual([
      'M0 56h8C28 56 28 28 48 28h8',
      'M0 56h8C28 56 28 84 48 84h8',
    ]);
  });

  /**
   * Bei drei Spuren liegt die mittlere auf der Mitte. Dort muss die Linie
   * GERADE sein — eine Kurve von der Mitte zur Mitte waere ein sichtbarer
   * Knick, und genau so sah der erste Entwurf aus.
   */
  it('zieht die mittlere Spur gerade, wenn es eine gibt', () => {
    expect(forkPaths(3)).toEqual([
      'M0 84h8C28 84 28 28 48 28h8',
      'M0 84H56',
      'M0 84h8C28 84 28 140 48 140h8',
    ]);
    expect(mergePaths(3)[1]).toBe('M0 84H50');
  });

  it('fuehrt zusammen und setzt die Pfeilspitze auf die Mitte', () => {
    expect(mergePaths(2)).toEqual([
      'M0 28h8C28 28 28 56 48 56h2',
      'M0 84h8C28 84 28 56 48 56h2',
      'M45 51.5 50 56l-5 4.5',
    ]);
  });

  /**
   * Die Zusagen, die fuer JEDE Anzahl gelten muessen — der Editor kennt keine
   * Obergrenze fuer Uebergaenge.
   */
  it('haelt die Zusagen auch bei vielen Spuren', () => {
    for (const n of [2, 3, 4, 5, 6, 9]) {
      const { height, mid, ys } = laneGeometry(n);

      expect(height).toBe(n * LANE_H);
      expect(ys.length).toBe(n);
      // Je Spur eine Linie im Auffaecherungs-Buendel.
      expect(forkPaths(n).length).toBe(n);
      // Beim Zusammenfuehren kommt die Pfeilspitze dazu.
      expect(mergePaths(n).length).toBe(n + 1);
      expect(mergePaths(n)[n]).toBe(`M45 ${mid - 4.5} 50 ${mid}l-5 4.5`);
      // Eine gerade Linie gibt es genau dann, wenn eine Spur auf der Mitte liegt.
      const gerade = forkPaths(n).filter((d) => d === `M0 ${mid}H56`).length;
      expect(gerade).toBe(n % 2 === 1 ? 1 : 0);
      // Jede Linie beginnt am Buendel-Anfang (x = 0) und endet rechts davon.
      for (const d of forkPaths(n)) {
        expect(d.startsWith('M0 ')).toBeTrue();
      }
    }
  });
});
