import { useMemo, useState } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Field, Input, Select } from '@/components/ui/Field';
import { toast } from '@/components/ui/Toast';
import {
  useCreateSource,
  useSourceTypes,
  type SourceTypeOption,
} from '@/api/queries/useKnowledge';
import { FaqPairsEditor, emptyPair, type FaqPair } from './FaqPairsEditor';

interface AddSourceModalProps {
  open: boolean;
  onClose: () => void;
}

/**
 * Adding a source.
 *
 * The type is chosen first and the rest of the form follows from it,
 * because the fields have nothing in common — a crawl needs a URL and a
 * page budget, a text source needs a textarea, WordPress content needs
 * post types. A single form covering all of them would be mostly
 * disabled inputs.
 *
 * Types the site cannot use are shown and disabled rather than hidden.
 * "Why can I not index my products" is a question with an answer, and
 * hiding the option leaves the customer to guess it.
 */
export function AddSourceModal({ open, onClose }: AddSourceModalProps) {
  const { data: types } = useSourceTypes();
  const create = useCreateSource();

  const [type, setType] = useState('wp_content');
  const [name, setName] = useState('');
  const [url, setUrl] = useState('');
  const [maxPages, setMaxPages] = useState('100');
  const [text, setText] = useState('');
  const [pairs, setPairs] = useState<FaqPair[]>([emptyPair()]);

  const completePairs = pairs.filter(
    (pair) => pair.question.trim() !== '' && pair.answer.trim() !== ''
  );

  const selected = useMemo(
    () => types?.find((option) => option.value === type),
    [types, type]
  );

  const reset = () => {
    setType('wp_content');
    setName('');
    setUrl('');
    setMaxPages('100');
    setText('');
    setPairs([emptyPair()]);
  };

  const config = (): Record<string, unknown> => {
    switch (type) {
      case 'website_crawl':
        return {
          url,
          max_pages: Number(maxPages) || 100,
          use_sitemap: true,
        };
      case 'text':
        return { content: text };
      case 'faq':
        // Only the complete pairs are sent. The extractor drops half-written
        // ones anyway, and storing them would mean a source whose pair count
        // never matches what it indexes.
        return { pairs: completePairs };
      case 'wp_content':
        return { post_types: ['post', 'page'] };
      default:
        return {};
    }
  };

  const ready = (): boolean => {
    if (selected && !selected.available) return false;
    if (type === 'website_crawl') return url.trim().startsWith('http');
    if (type === 'text') return text.trim().length > 0;
    if (type === 'faq') return completePairs.length > 0;
    return true;
  };

  const submit = () => {
    create.mutate(
      {
        name: name.trim() || (selected?.label ?? 'Knowledge source'),
        type,
        config: config(),
      },
      {
        onSuccess: (source) => {
          toast.success(
            `${source.name} is queued`,
            'Indexing runs in the background. Progress appears in the list.'
          );
          reset();
          onClose();
        },
        onError: (error) => toast.error('Could not add the source', error.message),
      }
    );
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Add a knowledge source"
      description="Content a clerk can answer from. Indexing starts as soon as it is added."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button
            variant="primary"
            onClick={submit}
            disabled={!ready()}
            loading={create.isPending}
          >
            Add and index
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field
          label="Source type"
          hint={
            selected && !selected.available
              ? selected.unavailable_reason
              : 'What kind of content this is.'
          }
          {...(selected && !selected.available
            ? { error: 'This source type cannot be used on this site.' }
            : {})}
        >
          {({ id, describedBy }) => (
            <Select
              id={id}
              aria-describedby={describedBy}
              value={type}
              onChange={(event) => setType(event.target.value)}
              invalid={selected ? !selected.available : false}
            >
              {(types ?? []).map((option: SourceTypeOption) => (
                <option
                  key={option.value}
                  value={option.value}
                  disabled={!option.available}
                >
                  {option.label}
                  {option.available ? '' : ' — unavailable'}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <Field label="Name" hint="How this appears in the sources list.">
          {({ id, describedBy }) => (
            <Input
              id={id}
              aria-describedby={describedBy}
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder={selected?.label ?? 'Knowledge source'}
            />
          )}
        </Field>

        {type === 'website_crawl' && (
          <>
            <Field
              label="Starting URL"
              hint="The crawler follows links from here, stays on this domain, and obeys robots.txt."
            >
              {({ id, describedBy }) => (
                <Input
                  id={id}
                  aria-describedby={describedBy}
                  value={url}
                  onChange={(event) => setUrl(event.target.value)}
                  placeholder="https://example.com/"
                  mono
                />
              )}
            </Field>

            <Field
              label="Page limit"
              hint="A hard ceiling. Crawls stop here even if the site has more pages."
            >
              {({ id, describedBy }) => (
                <Input
                  id={id}
                  aria-describedby={describedBy}
                  type="number"
                  min={1}
                  max={2000}
                  value={maxPages}
                  onChange={(event) => setMaxPages(event.target.value)}
                />
              )}
            </Field>
          </>
        )}

        {type === 'text' && (
          <Field
            label="Content"
            hint="Anything the clerk should know that is not written down elsewhere."
          >
            {({ id, describedBy }) => (
              <textarea
                id={id}
                aria-describedby={describedBy}
                value={text}
                onChange={(event) => setText(event.target.value)}
                rows={8}
                className="w-full rounded-lg border border-border bg-surface-sunken px-3 py-2 text-sm text-content placeholder:text-content-tertiary hover:border-border-strong focus:border-accent focus:outline-none"
                placeholder="Opening hours over the holidays are..."
              />
            )}
          </Field>
        )}

        {type === 'faq' && <FaqPairsEditor pairs={pairs} onChange={setPairs} />}

        {type === 'wp_content' && (
          <p className="rounded-lg border border-border bg-surface-sunken px-3 py-2 text-xs leading-relaxed text-content-secondary">
            Published posts and pages are indexed. Password-protected content
            is always excluded.
          </p>
        )}
      </div>
    </Modal>
  );
}
