import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { useChangeHighlight, type WorseWhen } from '@/hooks/useChangeHighlight';

beforeEach(() => {
  vi.useFakeTimers();
});

afterEach(() => {
  vi.useRealTimers();
});

type HighlightProps = { value: number | null; worseWhen: WorseWhen };

function renderHighlight(initialProps: HighlightProps) {
  return renderHook((props: HighlightProps) => useChangeHighlight(props.value, props.worseWhen), { initialProps });
}

test('the first render and an unchanged value have no highlight', () => {
  const { result, rerender } = renderHighlight({ value: 2, worseWhen: 'higher' });
  expect(result.current).toBeNull();

  rerender({ value: 2, worseWhen: 'higher' });
  expect(result.current).toBeNull();
});

test('a value moving in the worse direction is worse and one moving the other way is better', () => {
  const waitingCountHighlight = renderHighlight({ value: 2, worseWhen: 'higher' });
  waitingCountHighlight.rerender({ value: 3, worseWhen: 'higher' });
  expect(waitingCountHighlight.result.current).toBe('worse');
  waitingCountHighlight.rerender({ value: 2, worseWhen: 'higher' });
  expect(waitingCountHighlight.result.current).toBe('better');

  const availableCountHighlight = renderHighlight({ value: 2, worseWhen: 'lower' });
  availableCountHighlight.rerender({ value: 1, worseWhen: 'lower' });
  expect(availableCountHighlight.result.current).toBe('worse');
  availableCountHighlight.rerender({ value: 2, worseWhen: 'lower' });
  expect(availableCountHighlight.result.current).toBe('better');
});

test('a number with no better or worse direction is neutral in both directions', () => {
  const { result, rerender } = renderHighlight({ value: 2, worseWhen: null });
  rerender({ value: 3, worseWhen: null });
  expect(result.current).toBe('neutral');
  rerender({ value: 1, worseWhen: null });
  expect(result.current).toBe('neutral');
});

test('the highlight ends one second after the change', () => {
  const { result, rerender } = renderHighlight({ value: 2, worseWhen: 'higher' });
  rerender({ value: 3, worseWhen: 'higher' });

  act(() => {
    vi.advanceTimersByTime(999);
  });
  expect(result.current).toBe('worse');

  act(() => {
    vi.advanceTimersByTime(1);
  });
  expect(result.current).toBeNull();
});

test('a second change restarts the one-second highlight instead of ending on the timer of the first change', () => {
  const { result, rerender } = renderHighlight({ value: 2, worseWhen: 'higher' });
  rerender({ value: 3, worseWhen: 'higher' });
  act(() => {
    vi.advanceTimersByTime(600);
  });
  rerender({ value: 4, worseWhen: 'higher' });

  act(() => {
    vi.advanceTimersByTime(600);
  });
  expect(result.current).toBe('worse');

  act(() => {
    vi.advanceTimersByTime(400);
  });
  expect(result.current).toBeNull();
});

test('a value appearing from or turning into nothing is not highlighted', () => {
  const { result, rerender } = renderHighlight({ value: null, worseWhen: 'lower' });
  rerender({ value: 0.8, worseWhen: 'lower' });
  expect(result.current).toBeNull();

  rerender({ value: null, worseWhen: 'lower' });
  expect(result.current).toBeNull();
});

test('unmounting leaves no timer behind', () => {
  const { rerender, unmount } = renderHighlight({ value: 2, worseWhen: 'higher' });
  rerender({ value: 3, worseWhen: 'higher' });
  expect(vi.getTimerCount()).toBe(1);

  unmount();
  expect(vi.getTimerCount()).toBe(0);
});
