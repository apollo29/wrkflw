import { TestBed } from '@angular/core/testing';
import { WfDialogService } from './dialog';

describe('WfDialogService', () => {
  afterEach(() => document.querySelectorAll('.wf-dialog-host').forEach((el) => el.remove()));

  async function open(): Promise<{ result: Promise<string>; dialog: HTMLDialogElement }> {
    const result = TestBed.inject(WfDialogService).confirm({
      title: 'Titel',
      text: 'Text',
      cancelValue: 'esc',
      actions: [
        { value: 'a', label: 'Erster' },
        { value: 'b', label: 'Zweiter', variant: 'primary' },
      ],
    });
    await new Promise((r) => setTimeout(r));
    const dialog = document.querySelector('.wf-dialog-host dialog') as HTMLDialogElement;
    return { result, dialog };
  }

  it('resolves with the clicked action and cleans up', async () => {
    const { result, dialog } = await open();
    expect(dialog.hasAttribute('open')).toBe(true);
    expect(dialog.getAttribute('aria-labelledby')).toBeTruthy();
    expect(document.activeElement?.textContent?.trim()).toBe('Erster');
    expect(dialog.closest('.wf')?.getAttribute('data-theme')).toBe('hell');

    (dialog.querySelectorAll('button')[1] as HTMLButtonElement).click();
    expect(await result).toBe('b');
    expect(document.querySelector('.wf-dialog-host')).toBeNull();
  });

  it('resolves with the cancel value on Escape', async () => {
    const { result, dialog } = await open();
    dialog.dispatchEvent(new Event('cancel', { cancelable: true }));
    expect(await result).toBe('esc');
  });
});
