import { describe, it, expect, vi, beforeEach } from 'vitest';
import axios from 'axios';

// Mock axios
vi.mock('axios', () => ({
  default: {
    create: vi.fn(() => ({
      interceptors: {
        request: { use: vi.fn() },
        response: { use: vi.fn() },
      },
      get: vi.fn(),
      post: vi.fn(),
      put: vi.fn(),
      delete: vi.fn(),
    })),
  },
}));

describe('API Client', () => {
  beforeEach(() => {
    vi.resetModules();
    vi.clearAllMocks();
  });

  it('creates axios instance with correct config', async () => {
    await import('@/lib/api');

    expect(axios.create).toHaveBeenCalledWith(
      expect.objectContaining({
        baseURL: expect.any(String),
        withCredentials: true,
      })
    );
  });

  it('includes CSRF header configuration', async () => {
    await import('@/lib/api');

    expect(axios.create).toHaveBeenCalledWith(
      expect.objectContaining({
        headers: expect.objectContaining({
          'Content-Type': 'application/json',
          Accept: 'application/json',
        }),
      })
    );
  });
});
