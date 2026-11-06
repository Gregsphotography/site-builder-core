import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';

function canonical(value) {
  if (Array.isArray(value)) return `[${value.map(canonical).join(',')}]`;
  if (value !== null && typeof value === 'object') {
    return `{${Object.keys(value).sort().map(key => `${JSON.stringify(key)}:${canonical(value[key])}`).join(',')}}`;
  }
  return JSON.stringify(value);
}
const fixtures = JSON.parse(readFileSync(fileURLToPath(new URL('../tests/Fixtures/canonical.json', import.meta.url)), 'utf8'));
for (const fixture of fixtures) {
  const actual = canonical(JSON.parse(fixture.input));
  if (actual !== fixture.canonical || createHash('sha256').update(actual).digest('hex') !== fixture.sha256) {
    throw new Error('PHP/JavaScript canonical fixture mismatch');
  }
}
console.log(`${fixtures.length} independent JavaScript canonical/digest fixtures passed.`);
const numbers = JSON.parse(readFileSync(fileURLToPath(new URL('../tests/Fixtures/canonical-numbers.json', import.meta.url)), 'utf8'));
for (const fixture of numbers) {
  if (JSON.stringify(JSON.parse(fixture.input)) !== fixture.canonical) throw new Error('Independent numeric fixture mismatch');
}
console.log(`${numbers.length} independent JavaScript numeric fixtures passed.`);
