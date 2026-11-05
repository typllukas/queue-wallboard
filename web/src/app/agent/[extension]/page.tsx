import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { connection } from 'next/server';
import { fetchFloorState } from '@/api/fetchFloorState';
import { AgentPanel } from '@/components/agent/AgentPanel';
import { findAgentByExtension } from '@/domain/floorReducer';

export async function generateMetadata({ params }: PageProps<'/agent/[extension]'>): Promise<Metadata> {
  const { extension } = await params;
  return { title: `Agent ${extension}` };
}

export default async function AgentPage({ params }: PageProps<'/agent/[extension]'>) {
  await connection();
  const { extension } = await params;
  const floorState = await fetchFloorState();
  const isFloorKnown = floorState !== null && Object.keys(floorState.agents).length > 0;
  if (isFloorKnown && findAgentByExtension(floorState, extension) === undefined) {
    notFound();
  }
  return <AgentPanel extension={extension} initialState={floorState} />;
}
