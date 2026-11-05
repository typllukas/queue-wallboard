import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { z } from 'zod';

const contractExampleSchema = z.object({ snapshot: z.unknown(), pull: z.unknown() });

function readContractExample() {
  return contractExampleSchema.parse(JSON.parse(readFileSync(resolve(process.cwd(), '../contract/wire.example.json'), 'utf8')));
}

export function readSnapshotExample(): unknown {
  return readContractExample().snapshot;
}

export function readPullExample(): unknown {
  return readContractExample().pull;
}
