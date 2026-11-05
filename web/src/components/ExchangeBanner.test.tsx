import { render, screen } from '@testing-library/react';
import { expect, test } from 'vitest';
import { ExchangeBanner } from '@/components/ExchangeBanner';

test('a disconnected exchange shows the banner', () => {
  render(<ExchangeBanner exchangeConnected={false} />);

  expect(screen.getByRole('status')).toHaveTextContent('PBX disconnected');
});

test('a connected exchange shows nothing', () => {
  render(<ExchangeBanner exchangeConnected />);

  expect(screen.queryByText('PBX disconnected')).not.toBeInTheDocument();
});
