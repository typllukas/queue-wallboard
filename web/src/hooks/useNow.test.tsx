import { act, renderHook } from '@testing-library/react';
import { renderToString } from 'react-dom/server';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { useNow } from '@/hooks/useNow';

beforeEach(() => {
  vi.useFakeTimers({ now: new Date('2026-11-03T10:00:00Z') });
});

afterEach(() => {
  vi.useRealTimers();
});

test('the clock ticks once per interval and stops on unmount', () => {
  const { result, unmount } = renderHook(() => useNow(1000));
  expect(result.current?.toISOString()).toBe('2026-11-03T10:00:00.000Z');

  act(() => {
    vi.advanceTimersByTime(3000);
  });
  expect(result.current?.toISOString()).toBe('2026-11-03T10:00:03.000Z');

  unmount();
  expect(vi.getTimerCount()).toBe(0);
});

test('the server render has no time, so hydration matches whatever the clock says later', () => {
  function ShowNow() {
    return <p>{String(useNow(1000))}</p>;
  }

  expect(renderToString(<ShowNow />)).toBe('<p>null</p>');
});
