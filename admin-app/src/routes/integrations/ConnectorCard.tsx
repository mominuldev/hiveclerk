import { AlertTriangle, ArrowUpRight, CheckCircle2, Circle, Clock } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { cn } from '@/lib/cn';
import { relative } from '@/lib/format';
import type { IntegrationCard } from '@/api/queries/useIntegrations';
import { useFeature } from '@/api/queries/useLicence';

interface ConnectorCardProps {
  card: IntegrationCard;
  onConnect: () => void;
  onConfigure: () => void;
}

/**
 * One card on the grid (D11 §8).
 *
 * ## The second line is the point
 *
 * A card that says "Connected" and nothing else is a card nobody reads
 * twice. The line under the status carries the fact an operator is
 * actually looking for — how many contacts have gone across, or what
 * broke, or that the plugin this needs is not installed. D11 draws it as
 * "214 contacts" and "Token expired"; both are the same slot.
 *
 * A locked tier feature shows what it does and where to get it. Never a
 * dead disabled control.
 */
export function ConnectorCard({
  card,
  onConnect,
  onConfigure,
}: ConnectorCardProps) {
  const hasCrm = useFeature('crm');
  const unavailable = !card.available;
  const connected = card.status === 'connected' || card.status === 'degraded';

  return (
    <div
      className={cn(
        'flex flex-col rounded-xl border border-border bg-surface p-4',
        'shadow-sm [box-shadow:var(--hvc-elevate),var(--hvc-shadow-sm)]',
        card.status === 'degraded' && 'border-warning/40',
        card.status === 'expired' && 'border-warning/40'
      )}
    >
      <div className="flex items-start justify-between gap-3">
        <h3 className="font-display text-sm font-bold tracking-[-0.01em] text-content">
          {card.name}
        </h3>
        {/* Only when the tier does not already cover it. A "Pro" badge
            beside a connector an Agency licence includes reads as a
            refusal, and the operator goes looking for a paywall that is
            not there. */}
        {card.is_pro && !connected && !hasCrm && (
          <Badge tone="info">Pro</Badge>
        )}
      </div>

      <div className="mt-2 flex items-center gap-1.5">
        <StatusGlyph status={card.status} />
        <span className="text-xs font-medium text-content-secondary">
          {card.status_label}
        </span>
      </div>

      <p className="mt-3 min-h-[2.5rem] text-xs leading-relaxed text-content-tertiary">
        {detail(card)}
      </p>

      <div className="mt-4 flex items-center gap-2">
        {unavailable ? (
          <Button
            variant="link"
            onClick={() => window.open(card.docs_url, '_blank', 'noopener')}
          >
            How to install it
            <ArrowUpRight size={13} aria-hidden="true" />
          </Button>
        ) : connected ? (
          <Button size="sm" onClick={onConfigure}>
            Configure
          </Button>
        ) : (
          <Button size="sm" variant="primary" onClick={onConnect}>
            {card.status === 'expired' ? 'Reconnect' : 'Connect'}
          </Button>
        )}
      </div>
    </div>
  );
}

/**
 * The line under the status.
 *
 * Ordered by what an operator needs to know first: a broken thing before
 * a working one, a missing dependency before either.
 */
function detail(card: IntegrationCard): string {
  if (!card.available) {
    return `${card.name} is not active on this site. Install it, then come back here.`;
  }

  if (card.status === 'expired') {
    return 'The stored token expired. Reconnect to start syncing again.';
  }

  if (card.status === 'degraded') {
    return card.last_error
      ? `${card.failures} failed ${card.failures === 1 ? 'sync' : 'syncs'} — ${card.last_error}`
      : `${card.failures} failed ${card.failures === 1 ? 'sync' : 'syncs'}.`;
  }

  if (card.status === 'connected') {
    // Never an invented metric. A connection with nothing across yet says
    // so rather than showing a zero that reads like a failure.
    if (card.contacts === 0) {
      return card.account
        ? `Connected to ${card.account}. Nothing has synced yet.`
        : 'Connected. Nothing has synced yet.';
    }

    const when = card.last_sync_at ? `, last ${relative(card.last_sync_at)}` : '';

    return `${card.contacts} ${card.contacts === 1 ? 'contact' : 'contacts'} synced${when}.`;
  }

  return card.summary;
}

function StatusGlyph({ status }: { status: IntegrationCard['status'] }) {
  // Colour is never the only signal: each state pairs a distinct glyph
  // with the text label beside it.
  if (status === 'connected') {
    return <CheckCircle2 size={13} className="text-on-duty" aria-hidden="true" />;
  }

  if (status === 'degraded') {
    return <AlertTriangle size={13} className="text-warning" aria-hidden="true" />;
  }

  if (status === 'expired') {
    return <Clock size={13} className="text-warning" aria-hidden="true" />;
  }

  return <Circle size={13} className="text-content-tertiary" aria-hidden="true" />;
}
