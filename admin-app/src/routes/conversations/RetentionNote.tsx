import { useRetentionPolicy } from '@/api/queries/useConversations';

/**
 * What the retention policy is about to delete.
 *
 * Shown on the screen the deletion happens to, not buried in settings. A
 * policy whose effect is invisible until history disappears is one nobody
 * set deliberately — and this is the only place an operator would think
 * to look afterwards.
 */
export function RetentionNote() {
  const { data } = useRetentionPolicy();

  if (!data) {
    return null;
  }

  if (data.months === 0) {
    return (
      <p className="px-1 text-[11px] leading-relaxed text-content-tertiary">
        Conversations are kept indefinitely. Change that under Settings →
        Privacy.
      </p>
    );
  }

  return (
    <p className="px-1 text-[11px] leading-relaxed text-content-tertiary">
      Conversations are kept for {data.months} months.
      {data.pending > 0
        ? ` ${data.pending.toLocaleString()} ${data.pending === 1 ? 'is' : 'are'} past that and will be deleted tonight.`
        : ' Nothing is due for deletion.'}
    </p>
  );
}
