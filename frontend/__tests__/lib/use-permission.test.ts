import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';

// Mock auth module
vi.mock('@/lib/auth', () => ({
  useAuth: vi.fn(() => ({ user: null })),
}));

describe('usePermission', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.resetModules();
  });

  it('returns false when user is null', async () => {
    const { useAuth } = await import('@/lib/auth');
    (useAuth as any).mockReturnValue({ user: null });

    const { usePermission } = await import('@/lib/use-permission');
    const { result } = renderHook(() => usePermission('settings.view'));

    expect(result.current).toBe(false);
  });

  it('returns true when user has the permission', async () => {
    const { useAuth } = await import('@/lib/auth');
    (useAuth as any).mockReturnValue({
      user: { id: 1, permissions: ['settings.view', 'users.edit'] },
    });

    const { usePermission } = await import('@/lib/use-permission');
    const { result } = renderHook(() => usePermission('settings.view'));

    expect(result.current).toBe(true);
  });

  it('returns false when user lacks the permission', async () => {
    const { useAuth } = await import('@/lib/auth');
    (useAuth as any).mockReturnValue({
      user: { id: 1, permissions: ['users.edit'] },
    });

    const { usePermission } = await import('@/lib/use-permission');
    const { result } = renderHook(() => usePermission('settings.view'));

    expect(result.current).toBe(false);
  });

  it('returns false when user has no permissions array', async () => {
    const { useAuth } = await import('@/lib/auth');
    (useAuth as any).mockReturnValue({
      user: { id: 1 },
    });

    const { usePermission } = await import('@/lib/use-permission');
    const { result } = renderHook(() => usePermission('settings.view'));

    expect(result.current).toBe(false);
  });
});

describe('usePermissions', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.resetModules();
  });

  it('returns all false when user is null', async () => {
    const { useAuth } = await import('@/lib/auth');
    (useAuth as any).mockReturnValue({ user: null });

    const { usePermissions } = await import('@/lib/use-permission');
    const { result } = renderHook(() =>
      usePermissions(['settings.view', 'users.edit'])
    );

    expect(result.current).toEqual([false, false]);
  });

  it('returns correct booleans per permission', async () => {
    const { useAuth } = await import('@/lib/auth');
    (useAuth as any).mockReturnValue({
      user: { id: 1, permissions: ['settings.view', 'audit.view'] },
    });

    const { usePermissions } = await import('@/lib/use-permission');
    const { result } = renderHook(() =>
      usePermissions(['settings.view', 'users.edit', 'audit.view'])
    );

    expect(result.current).toEqual([true, false, true]);
  });

  it('returns empty array for empty input', async () => {
    const { useAuth } = await import('@/lib/auth');
    (useAuth as any).mockReturnValue({
      user: { id: 1, permissions: ['settings.view'] },
    });

    const { usePermissions } = await import('@/lib/use-permission');
    const { result } = renderHook(() => usePermissions([]));

    expect(result.current).toEqual([]);
  });
});
