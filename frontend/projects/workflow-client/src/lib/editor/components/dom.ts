/** Wert eines Eingabe-Elements aus einem Ereignis. */
export function valueOf(event: Event): string {
  const target = event.target as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement | null;
  return target?.value ?? '';
}

export function checkedOf(event: Event): boolean {
  return (event.target as HTMLInputElement | null)?.checked ?? false;
}
