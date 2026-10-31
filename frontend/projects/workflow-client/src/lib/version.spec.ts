import { API_VERSION, CLIENT_VERSION, isCompatibleEngine } from './version';

describe('version', () => {
  it('client major matches the API version', () => {
    expect(Number.parseInt(CLIENT_VERSION, 10)).toBe(API_VERSION);
  });

  it('accepts engines of the same major version only', () => {
    expect(isCompatibleEngine('3.0.0')).toBe(true);
    expect(isCompatibleEngine('3.4.1-dev')).toBe(true);
    expect(isCompatibleEngine('2.3.1')).toBe(false);
    expect(isCompatibleEngine('')).toBe(false);
  });
});
