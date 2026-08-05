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
 * How long ago, or how long until.
 *
 * Used where the exact instant is not the question — "last synced 4
 * minutes ago" answers "is this working" and a UTC timestamp does not.
 * Falls back to the absolute form past a week, because "38 days ago" is a
 * number nobody converts back into a date correctly.
 */
export function relative(value: string | null): string {
  if (!value) {
    return '';
  }

  const parsed = new Date(value);

  if (Number.isNaN(parsed.getTime())) {
    return value;
  }

  const seconds = Math.round((parsed.getTime() - Date.now()) / 1000);
  const future = seconds > 0;
  const magnitude = Math.abs(seconds);

  if (magnitude > 604_800) {
    return formatTimestamp(value);
  }

  const [amount, unit] =
    magnitude < 60
      ? [magnitude, 'second']
      : magnitude < 3600
        ? [Math.round(magnitude / 60), 'minute']
        : magnitude < 86_400
          ? [Math.round(magnitude / 3600), 'hour']
          : [Math.round(magnitude / 86_400), 'day'];

  const plural = amount === 1 ? unit : `${unit}s`;

  return future ? `in ${amount} ${plural}` : `${amount} ${plural} ago`;
}

/**
 * A delay in minutes, as an operator would say it.
 *
 * "2 days" rather than "2880 minutes". The sequence builder stores
 * minutes because that is what a delay is; nobody reads one.
 */
export function formatDelay(minutes: number): string {
  if (minutes <= 0) {
    return 'immediately';
  }

  if (minutes < 60) {
    return `${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`;
  }

  if (minutes < 1440) {
    const hours = Math.round((minutes / 60) * 10) / 10;

    return `${hours} ${hours === 1 ? 'hour' : 'hours'}`;
  }

  const days = Math.round((minutes / 1440) * 10) / 10;

  return `${days} ${days === 1 ? 'day' : 'days'}`;
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
