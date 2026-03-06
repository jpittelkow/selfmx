import { describe, it, expect } from 'vitest';
import { sanitizeCss } from '@/lib/sanitize';

describe('sanitizeCss', () => {
  it('returns empty string for falsy input', () => {
    expect(sanitizeCss('')).toBe('');
    expect(sanitizeCss(null as unknown as string)).toBe('');
    expect(sanitizeCss(undefined as unknown as string)).toBe('');
  });

  it('preserves normal CSS', () => {
    const css = 'body { color: red; font-size: 16px; margin: 0 auto; }';
    expect(sanitizeCss(css)).toBe(css);
  });

  describe('@import removal', () => {
    it('strips @import with semicolon', () => {
      expect(sanitizeCss('@import url("evil.css");')).toBe('/* removed */');
    });

    it('strips @import without semicolon', () => {
      expect(sanitizeCss('@import url("evil.css")')).toBe('/* removed */');
    });

    it('strips @import with string syntax', () => {
      expect(sanitizeCss('@import "evil.css";')).toBe('/* removed */');
    });

    it('is case-insensitive', () => {
      expect(sanitizeCss('@IMPORT url("evil.css");')).toBe('/* removed */');
    });
  });

  describe('expression() removal', () => {
    it('strips expression()', () => {
      expect(sanitizeCss('width: expression(document.body.clientWidth)')).toBe(
        'width: /* removed */'
      );
    });

    it('handles spaces before parenthesis', () => {
      const result = sanitizeCss('width: expression (alert(1))');
      expect(result).not.toContain('alert');
    });
  });

  describe('behavior: removal', () => {
    it('strips behavior property', () => {
      expect(sanitizeCss('behavior: url(xss.htc)')).toBe('/* removed */');
    });

    it('is case-insensitive', () => {
      expect(sanitizeCss('BEHAVIOR: url(xss.htc)')).toBe('/* removed */');
    });
  });

  describe('-moz-binding removal', () => {
    it('strips -moz-binding property', () => {
      const result = sanitizeCss('-moz-binding: url("xbl.xml#xss")');
      expect(result).not.toContain('-moz-binding');
    });
  });

  describe('javascript: URL removal', () => {
    it('strips javascript: in url()', () => {
      const input = 'background: url(javascript:alert(1))';
      expect(sanitizeCss(input)).toContain('/* removed */');
      expect(sanitizeCss(input)).not.toContain('javascript:');
    });
  });

  describe('external URL removal', () => {
    it('strips http:// URLs in url()', () => {
      expect(sanitizeCss('background: url(http://evil.com/track.png)')).toBe(
        'background: /* removed */'
      );
    });

    it('strips https:// URLs in url()', () => {
      expect(sanitizeCss('background: url(https://evil.com/track.png)')).toBe(
        'background: /* removed */'
      );
    });

    it('strips quoted URLs', () => {
      expect(sanitizeCss("background: url('https://evil.com/x.png')")).toBe(
        'background: /* removed */'
      );
    });

    it('preserves data: URLs', () => {
      const css = 'background: url(data:image/png;base64,abc123)';
      expect(sanitizeCss(css)).toBe(css);
    });

    it('preserves relative URLs', () => {
      const css = 'background: url(images/bg.png)';
      expect(sanitizeCss(css)).toBe(css);
    });
  });

  it('handles mixed dangerous and safe CSS', () => {
    const input = `
      body { color: red; }
      @import url("evil.css");
      .safe { font-size: 14px; }
      .bad { background: url(https://evil.com/x.png); }
    `;
    const result = sanitizeCss(input);
    expect(result).toContain('color: red');
    expect(result).toContain('font-size: 14px');
    expect(result).not.toContain('evil.css');
    expect(result).not.toContain('evil.com');
  });
});
