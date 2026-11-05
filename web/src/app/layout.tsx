import type { Metadata } from 'next';
import './globals.css';

export const metadata: Metadata = {
  title: { template: '%s · Queue wallboard', default: 'Queue wallboard' },
  description: 'Live queue statistics',
};

export default function RootLayout({ children }: LayoutProps<'/'>) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}
