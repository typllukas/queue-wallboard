import type { Metadata } from 'next';
import { DemoPage } from '@/components/demo/DemoPage';

export const metadata: Metadata = { title: { absolute: 'Queue wallboard demo' } };

export default function HomePage() {
  return <DemoPage />;
}
