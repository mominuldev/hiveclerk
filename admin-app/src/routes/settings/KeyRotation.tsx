import { useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import {
  useKeyRotation,
  type RotationState,
  type SystemHealth,
} from '@/api/queries/useSystemStatus';

/**
 * Re-keying every stored secret, as three steps an operator drives.
 *
 * Not one button. Rotation cannot be atomic — the sweep is bounded so it
 * cannot time out part-way through an install with many integrations — and
 * the last step destroys the old key. An operator who has just had their
 * salts leaked needs to see what moved before the old key stops working,
 * not press "rotate" and hope.
 *
 * The cost is shown before commitment, as everywhere else in this product:
 * what will be re-encrypted is listed before anything changes.
 */
export function KeyRotation({ health }: { health: SystemHealth }) {
  const { begin, sweep, finish } = useKeyRotation();
  const [state, setState] = useState<RotationState | null>(null);
  const [error, setError] = useState<string | null>(null);

  const rotating = state?.rotating ?? health.encryption.rotating;
  const outstanding = state?.outstanding ?? [];
  const remaining = state?.remaining ?? health.encryption.outstanding;
  const busy = begin.isPending || sweep.isPending || finish.isPending;

  /* Errors carry what happened and what to do; the mutation only has a code. */
  async function run(step: () => Promise<RotationState>) {
    setError(null);

    try {
      setState(await step());
    } catch (thrown) {
      setError(
        thrown instanceof Error
          ? thrown.message
          : 'The step did not complete. Nothing was changed.'
      );
    }
  }

  return (
    <Card eyebrow="Secrets" title="Encryption key">
      {!rotating ? (
        <>
          <p className="text-xs leading-relaxed text-content-secondary">
            Provider keys, integration tokens and the licence key are encrypted
            with a key derived from this site&rsquo;s salts. Rotate it if those
            salts may have been exposed — in a database dump, a backup, or a
            leaked <code>wp-config.php</code>.
          </p>

          <p className="mt-2 text-xs leading-relaxed text-content-secondary">
            Nothing stops working while it runs. Both keys stay readable until
            you finish, and every secret is moved before the old one is retired.
          </p>

          <Button
            className="mt-4"
            disabled={busy}
            onClick={() => void run(() => begin.mutateAsync())}
          >
            {begin.isPending ? 'Starting…' : 'Start key rotation'}
          </Button>
        </>
      ) : (
        <>
          <p className="text-xs leading-relaxed text-content-secondary">
            A rotation is in progress. Both the old and new keys work until you
            finish, so nothing has broken — but the old key is still valid, so
            the rotation has not protected anything yet.
          </p>

          {remaining > 0 ? (
            <p className="mt-2 text-xs leading-relaxed text-content-secondary">
              {remaining === 1
                ? '1 secret still to move.'
                : `${remaining} secrets still to move.`}
            </p>
          ) : (
            <p className="mt-2 text-xs leading-relaxed text-content-secondary">
              Everything has been moved to the new key. Finishing retires the
              old one.
            </p>
          )}

          {state?.unreadable ? (
            <p className="mt-2 text-xs leading-relaxed text-warning-ink">
              {state.unreadable === 1
                ? '1 secret could not be read by either key and was left alone.'
                : `${state.unreadable} secrets could not be read by either key and were left alone.`}{' '}
              They were already unreadable. You will need to enter them again.
            </p>
          ) : null}

          {outstanding.length > 0 ? (
            <ul className="mt-3 space-y-1 text-xs text-content-tertiary">
              {/* Labels only. The values are what this whole subsystem exists
                  to keep off the wire. */}
              {outstanding.map((label) => (
                <li key={label}>{label}</li>
              ))}
            </ul>
          ) : null}

          <div className="mt-4 flex flex-wrap gap-2">
            <Button
              disabled={busy || remaining === 0}
              onClick={() => void run(() => sweep.mutateAsync())}
            >
              {sweep.isPending ? 'Moving…' : 'Move secrets'}
            </Button>

            <Button
              variant="secondary"
              disabled={busy || remaining > 0}
              onClick={() => void run(() => finish.mutateAsync())}
            >
              {finish.isPending ? 'Finishing…' : 'Finish and retire the old key'}
            </Button>
          </div>
        </>
      )}

      {error ? (
        <p className="mt-3 text-xs leading-relaxed text-danger-ink">{error}</p>
      ) : null}
    </Card>
  );
}
