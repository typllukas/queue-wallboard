import type { Metadata } from 'next';
import { connection } from 'next/server';
import { fetchFloorState } from '@/api/fetchFloorState';
import { Wallboard } from '@/components/wallboard/Wallboard';

export const metadata: Metadata = { title: 'Wallboard' };

export default async function WallboardPage() {
  await connection();
  return <Wallboard initialState={await fetchFloorState()} />;
}
