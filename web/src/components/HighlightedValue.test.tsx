import { render, screen } from '@testing-library/react';
import { renderToString } from 'react-dom/server';
import { expect, test } from 'vitest';
import { HighlightedValue } from '@/components/HighlightedValue';

test('a changed value carries its direction until the highlight ends, and the server render carries none', () => {
  expect(renderToString(<HighlightedValue value={3} worseWhen="higher">3</HighlightedValue>)).not.toContain('data-change');

  const { rerender } = render(<HighlightedValue value={3} worseWhen="higher">3</HighlightedValue>);
  expect(screen.getByText('3')).not.toHaveAttribute('data-change');

  rerender(<HighlightedValue value={4} worseWhen="higher">4</HighlightedValue>);
  expect(screen.getByText('4')).toHaveAttribute('data-change', 'worse');
});
