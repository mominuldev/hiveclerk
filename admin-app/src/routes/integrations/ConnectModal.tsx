import { useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Field, Input } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { toast } from '@/components/ui/Toast';
import { useConnect, type IntegrationCard } from '@/api/queries/useIntegrations';

interface ConnectModalProps {
  card: IntegrationCard | null;
  onClose: () => void;
  onConnected: (provider: string) => void;
}

/**
 * The connect dialog.
 *
 * Every field it renders was declared by the connector itself, so a
 * third-party integration registered through `hiveclerk/crm/connectors`
 * gets a working form without shipping any front-end code.
 *
 * ## Secrets are write-only, and the form says so
 *
 * A password field left blank means "leave what is stored alone" — the
 * server merges submitted values over the existing ones. The alternative,
 * showing a masked value in the input, produces a form where saving
 * without touching the field writes the mask back as the key.
 */
export function ConnectModal({ card, onClose, onConnected }: ConnectModalProps) {
  const connect = useConnect();
  const [values, setValues] = useState<Record<string, string>>({});

  if (!card) {
    return null;
  }

  const submit = (): void => {
    connect.mutate(
      { provider: card.id, settings: values },
      {
        onSuccess: (data) => {
          if (data.redirect) {
            // The whole window, not a popup. A blocked popup leaves the
            // operator looking at a screen where nothing happened.
            window.location.href = data.redirect;

            return;
          }

          toast.success(`${card.name} connected`);
          setValues({});
          onConnected(card.id);
        },
        onError: (error) => toast.error('That did not connect', error.message),
      }
    );
  };

  const missing = card.settings.some(
    (setting) => setting.required && !values[setting.key]?.trim()
  );

  return (
    <Modal
      open
      onClose={onClose}
      title={`Connect ${card.name}`}
      description={card.summary}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button
            variant="primary"
            loading={connect.isPending}
            disabled={missing}
            onClick={submit}
          >
            {card.auth === 'oauth' ? 'Continue to ' + card.name : 'Connect'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {card.settings.length === 0 && (
          <p className="text-sm leading-relaxed text-content-secondary">
            Nothing to configure — {card.name} runs on this site, so there is no
            API key and no data leaves the server.
          </p>
        )}

        {card.settings.map((setting) => (
          <Field
            key={setting.key}
            label={setting.label}
            {...(setting.help ? { hint: setting.help } : {})}
          >
            {({ id, describedBy }) => (
              <Input
                id={id}
                aria-describedby={describedBy}
                type={setting.secret ? 'password' : 'text'}
                mono={setting.secret || setting.type === 'url'}
                autoComplete="off"
                placeholder={setting.placeholder}
                value={values[setting.key] ?? ''}
                onChange={(event) =>
                  setValues({ ...values, [setting.key]: event.target.value })
                }
              />
            )}
          </Field>
        ))}

        {card.auth === 'oauth' && (
          <p className="rounded-lg border border-border bg-surface-sunken p-3 text-xs leading-relaxed text-content-secondary">
            You will be sent to {card.name} to approve the connection, then
            brought straight back here. The credentials stay encrypted on this
            server.
          </p>
        )}
      </div>
    </Modal>
  );
}
