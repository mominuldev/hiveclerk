import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router';
import { Unplug } from 'lucide-react';
import { ConnectModal } from './ConnectModal';
import { ConnectorCard } from './ConnectorCard';
import { FieldMapping } from './FieldMapping';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Skeleton } from '@/components/ui/Skeleton';
import { toast } from '@/components/ui/Toast';
import {
  useDisconnect,
  useIntegrations,
  useTestIntegration,
  type IntegrationCard,
} from '@/api/queries/useIntegrations';

/**
 * The connectors grid (D11 §8).
 *
 * ## The OAuth callback lands back here
 *
 * The REST callback redirects to `#/integrations?provider=…&connected=1`
 * or `…&error=…`. Reading it from the URL rather than from state is the
 * only option available: the operator left this application entirely,
 * went to HubSpot, and came back through a full page load.
 */
export function Integrations() {
  const grid = useIntegrations();
  const test = useTestIntegration();
  const disconnect = useDisconnect();

  const [params, setParams] = useSearchParams();
  const [connecting, setConnecting] = useState<IntegrationCard | null>(null);
  const [configuring, setConfiguring] = useState<string | null>(null);

  const returned = params.get('provider');
  const connected = params.get('connected');
  const failure = params.get('error');

  useEffect(() => {
    if (!returned) {
      return;
    }

    if (connected) {
      toast.success('Connected');
      setConfiguring(returned);
    } else if (failure) {
      toast.error('That did not connect', failure);
    }

    // Cleared so a refresh does not re-announce a connection that
    // happened ten minutes ago.
    setParams({}, { replace: true });
  }, [returned, connected, failure, setParams]);

  if (grid.isError) {
    return <ErrorNotice error={grid.error} onRetry={() => void grid.refetch()} />;
  }

  if (grid.isLoading || !grid.data) {
    return <Skeleton className="h-64 w-full" />;
  }

  const crm = grid.data.integrations.filter((card) => card.kind === 'crm');
  const notify = grid.data.integrations.filter(
    (card) => card.kind === 'notification'
  );

  const open = grid.data.integrations.find((card) => card.id === configuring);

  const runTest = (provider: string): void => {
    test.mutate(provider, {
      onSuccess: (result) => {
        if (result.ok) {
          toast.success(
            result.account ? `Reached ${result.account}` : 'Connection works',
            result.message
          );
        } else {
          toast.error('That did not work', result.message);
        }
      },
      onError: (error) => toast.error('The test failed', error.message),
    });
  };

  const runDisconnect = (card: IntegrationCard): void => {
    disconnect.mutate(card.id, {
      onSuccess: () => {
        toast.success(
          `${card.name} disconnected`,
          'The field mapping and the sync history are kept.'
        );
        setConfiguring(null);
      },
      onError: (error) => toast.error('That did not disconnect', error.message),
    });
  };

  return (
    <div className="space-y-6">
      <Section title="CRM">
        {crm.map((card) => (
          <ConnectorCard
            key={card.id}
            card={card}
            onConnect={() => setConnecting(card)}
            onConfigure={() => setConfiguring(card.id)}
          />
        ))}
      </Section>

      <Section title="Notifications">
        {notify.map((card) => (
          <ConnectorCard
            key={card.id}
            card={card}
            onConnect={() => setConnecting(card)}
            onConfigure={() => setConfiguring(card.id)}
          />
        ))}
      </Section>

      {open && (
        <>
          <FieldMapping card={open} events={grid.data.events} />

          <Card title={`Connection · ${open.name}`}>
            <div className="flex flex-wrap items-center gap-2">
              <Button loading={test.isPending} onClick={() => runTest(open.id)}>
                Test connection
              </Button>
              <Button onClick={() => setConnecting(open)}>
                Replace credentials
              </Button>
              <Button
                variant="ghost"
                icon={<Unplug size={14} aria-hidden="true" />}
                loading={disconnect.isPending}
                onClick={() => runDisconnect(open)}
              >
                Disconnect
              </Button>
            </div>

            <p className="mt-3 text-xs leading-relaxed text-content-tertiary">
              Disconnecting clears the stored credentials. Your field mapping and
              the sync history stay, so reconnecting picks up where this left
              off.
            </p>
          </Card>
        </>
      )}

      <ConnectModal
        card={connecting}
        onClose={() => setConnecting(null)}
        onConnected={(provider) => {
          setConnecting(null);
          setConfiguring(provider);
        }}
      />
    </div>
  );
}

function Section({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <section>
      <h2 className="mb-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-content-tertiary">
        {title}
      </h2>
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{children}</div>
    </section>
  );
}
