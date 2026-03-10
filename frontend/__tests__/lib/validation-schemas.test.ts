import { describe, it, expect } from 'vitest';
import {
  emailSchema,
  passwordSchema,
  passwordWithConfirmation,
  requiredString,
  optionalString,
  urlSchema,
  optionalUrl,
  positiveInt,
  nonNegativeInt,
  hexColor,
  cronExpression,
  apiKeySchema,
} from '@/lib/validation-schemas';

describe('emailSchema', () => {
  it('accepts valid emails', () => {
    expect(emailSchema.safeParse('user@example.com').success).toBe(true);
    expect(emailSchema.safeParse('name+tag@domain.co.uk').success).toBe(true);
  });

  it('rejects invalid emails', () => {
    expect(emailSchema.safeParse('not-an-email').success).toBe(false);
    expect(emailSchema.safeParse('').success).toBe(false);
    expect(emailSchema.safeParse('@domain.com').success).toBe(false);
  });
});

describe('passwordSchema', () => {
  it('accepts valid passwords', () => {
    expect(passwordSchema.safeParse('12345678').success).toBe(true);
    expect(passwordSchema.safeParse('a very long password').success).toBe(true);
  });

  it('rejects short passwords', () => {
    expect(passwordSchema.safeParse('short').success).toBe(false);
    expect(passwordSchema.safeParse('').success).toBe(false);
  });
});

describe('passwordWithConfirmation', () => {
  it('accepts matching passwords', () => {
    const result = passwordWithConfirmation.safeParse({
      password: 'mypassword',
      password_confirmation: 'mypassword',
    });
    expect(result.success).toBe(true);
  });

  it('rejects mismatched passwords', () => {
    const result = passwordWithConfirmation.safeParse({
      password: 'mypassword',
      password_confirmation: 'different',
    });
    expect(result.success).toBe(false);
  });

  it('rejects short password even with matching confirmation', () => {
    const result = passwordWithConfirmation.safeParse({
      password: 'short',
      password_confirmation: 'short',
    });
    expect(result.success).toBe(false);
  });
});

describe('requiredString', () => {
  it('accepts non-empty strings', () => {
    expect(requiredString.safeParse('hello').success).toBe(true);
  });

  it('rejects empty strings', () => {
    expect(requiredString.safeParse('').success).toBe(false);
    expect(requiredString.safeParse('   ').success).toBe(false);
  });
});

describe('optionalString', () => {
  it('accepts strings and undefined', () => {
    expect(optionalString.safeParse('hello').success).toBe(true);
    expect(optionalString.safeParse(undefined).success).toBe(true);
  });
});

describe('urlSchema', () => {
  it('accepts valid URLs', () => {
    expect(urlSchema.safeParse('https://example.com').success).toBe(true);
    expect(urlSchema.safeParse('http://localhost:3000/path').success).toBe(true);
  });

  it('rejects invalid URLs', () => {
    expect(urlSchema.safeParse('not-a-url').success).toBe(false);
    expect(urlSchema.safeParse('').success).toBe(false);
  });
});

describe('optionalUrl', () => {
  it('accepts valid URLs and empty strings', () => {
    expect(optionalUrl.safeParse('https://example.com').success).toBe(true);
    expect(optionalUrl.safeParse('').success).toBe(true);
  });

  it('rejects invalid non-empty URLs', () => {
    expect(optionalUrl.safeParse('not-a-url').success).toBe(false);
  });
});

describe('positiveInt', () => {
  it('accepts positive integers', () => {
    expect(positiveInt.safeParse(1).success).toBe(true);
    expect(positiveInt.safeParse(100).success).toBe(true);
  });

  it('rejects zero and negative numbers', () => {
    expect(positiveInt.safeParse(0).success).toBe(false);
    expect(positiveInt.safeParse(-1).success).toBe(false);
  });

  it('rejects floats', () => {
    expect(positiveInt.safeParse(1.5).success).toBe(false);
  });
});

describe('nonNegativeInt', () => {
  it('accepts zero and positive integers', () => {
    expect(nonNegativeInt.safeParse(0).success).toBe(true);
    expect(nonNegativeInt.safeParse(42).success).toBe(true);
  });

  it('rejects negative numbers', () => {
    expect(nonNegativeInt.safeParse(-1).success).toBe(false);
  });
});

describe('hexColor', () => {
  it('accepts valid hex colors', () => {
    expect(hexColor.safeParse('#000').success).toBe(true);
    expect(hexColor.safeParse('#ff00ff').success).toBe(true);
    expect(hexColor.safeParse('#ABC').success).toBe(true);
  });

  it('rejects invalid hex colors', () => {
    expect(hexColor.safeParse('red').success).toBe(false);
    expect(hexColor.safeParse('#GG0000').success).toBe(false);
    expect(hexColor.safeParse('000000').success).toBe(false);
  });
});

describe('cronExpression', () => {
  it('accepts valid cron expressions', () => {
    expect(cronExpression.safeParse('* * * * *').success).toBe(true);
    expect(cronExpression.safeParse('0 0 * * *').success).toBe(true);
    expect(cronExpression.safeParse('*/5 * * * *').success).toBe(true);
  });

  it('rejects invalid cron expressions', () => {
    expect(cronExpression.safeParse('not-cron').success).toBe(false);
    expect(cronExpression.safeParse('* * *').success).toBe(false);
  });
});

describe('apiKeySchema', () => {
  it('accepts non-empty strings', () => {
    expect(apiKeySchema.safeParse('sk-abc123').success).toBe(true);
  });

  it('rejects empty strings', () => {
    expect(apiKeySchema.safeParse('').success).toBe(false);
    expect(apiKeySchema.safeParse('   ').success).toBe(false);
  });
});
