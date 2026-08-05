/**
 * Formatting helpers shared by the reporting screens.
 */

/**
 * Money, with enough precision to be believable.
 *
 * Model calls routinely cost fractions of a cent, and rounding those to
 * two decimals turns a month of real usage into a column of $0.00. Small
 * amounts get four decimal places; anything a person would recognise as
 * money gets two.
 */
export function formatCost(value: number): string {
  if (value === 0) {
    return '$0';
  }

  if (value < 0.01) {
    return `$${value.toFixed(4)}`;
  }

  return `$${value.toFixed(2)}`;
}

/**
 * Large counts, abbreviated.
 *
 * Token counts reach the millions and the exact digit is never the point
 * — whether it is 1.2M or 1.3M is what an operator reads.
 */
export function formatCompact(value: number): string {
  if (value < 1000) {
    return String(value);
  }

  if (value < 1_000_000) {
    return `${(value / 1000).toFixed(1).replace(/\.0$/, '')}k`;
  }

  return `${(value / 1_000_000).toFixed(1).replace(/\.0$/, '')}M`;
}

/**
 * A UTC timestamp as something readable, without inventing a timezone.
 *
 * The server stores and returns UTC. Rendering it as local time without
 * saying so is how a support conversation ends up comparing two different
 * clocks, so the suffix stays.
 */
export function formatTimestamp(value: string): string {
  if (!value) {
    return '';
  }

  const parsed = new Date(value.replace(' ', 'T') + 'Z');

  if (Number.isNaN(parsed.getTime())) {
    return value;
  }

  return `${parsed.toISOString().slice(0, 16).replace('T', ' ')} UTC`;
}

/**
 * A price per million tokens, as providers publish it.
 */
export function formatPerMillion(input: number, output: number): string {
  const format = (value: number) =>
    value >= 1 ? `$${value.toFixed(2)}` : `$${value.toFixed(3)}`;

  if (output === 0) {
    return `${format(input)} / M`;
  }

  return `${format(input)} in · ${format(output)} out / M`;
}
