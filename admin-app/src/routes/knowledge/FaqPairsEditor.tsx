import { useRef, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Field, Input, Textarea } from '@/components/ui/Field';
import { toast } from '@/components/ui/Toast';
import { useParseFaqCsv } from '@/api/queries/useKnowledge';

export interface FaqPair {
  question: string;
  answer: string;
  url: string;
}

interface FaqPairsEditorProps {
  pairs: FaqPair[];
  onChange: (pairs: FaqPair[]) => void;
}

export function emptyPair(): FaqPair {
  return { question: '', answer: '', url: '' };
}

/**
 * Writing and importing question-and-answer pairs (FR-KB-04, FR-KB-05).
 *
 * The FAQ extractor, the CSV parser and `POST /admin/knowledge/faq/parse`
 * have existed since Sprint 3; this is the screen that was carried past
 * seven sprints, during which a FAQ source could be created through the API
 * and not through the product.
 *
 * ## Import parses before it saves
 *
 * The endpoint hands back what it understood and how many rows it ignored,
 * and both go on screen before anything is stored. A customer uploading 240
 * rows needs to see that three were dropped *while they can still fix the
 * file* — an import that silently indexes 237 pairs is one where the three
 * missing answers are discovered by a visitor.
 *
 * Imported pairs land in the same editable list as hand-written ones rather
 * than in a separate "imported" bucket. There is no meaningful difference
 * once they are stored, and a customer who spots a typo in row 12 should be
 * able to fix it here instead of re-uploading.
 */
export function FaqPairsEditor({ pairs, onChange }: FaqPairsEditorProps) {
  const parse = useParseFaqCsv();
  const fileInput = useRef<HTMLInputElement>(null);
  const [showImport, setShowImport] = useState(false);
  const [csv, setCsv] = useState('');

  const update = (index: number, patch: Partial<FaqPair>) => {
    onChange(pairs.map((pair, i) => (i === index ? { ...pair, ...patch } : pair)));
  };

  const remove = (index: number) => {
    const next = pairs.filter((_, i) => i !== index);
    // Never leave the list with nothing in it. An empty editor reads as a
    // broken screen; one blank pair reads as an invitation.
    onChange(next.length > 0 ? next : [emptyPair()]);
  };

  const runImport = (text: string) => {
    parse.mutate(text, {
      onSuccess: (result) => {
        if (result.pairs.length === 0) {
          toast.error(
            'Nothing could be read from that file',
            'Each row needs a question in the first column and an answer in the second.'
          );
          return;
        }

        // Blank rows the customer never filled in are dropped rather than
        // kept above the import, which would otherwise push every imported
        // pair below an empty one.
        const written = pairs.filter(
          (pair) => pair.question.trim() !== '' || pair.answer.trim() !== ''
        );

        onChange([...written, ...result.pairs]);
        setCsv('');
        setShowImport(false);

        toast.success(
          `${result.pairs.length} ${result.pairs.length === 1 ? 'pair' : 'pairs'} imported`,
          result.skipped > 0
            ? `${result.skipped} ${result.skipped === 1 ? 'row was' : 'rows were'} ignored for having no question or no answer.`
            : 'Every row was understood.'
        );
      },
      onError: (error) => toast.error('That file could not be read', error.message),
    });
  };

  const onFile = (file: File | undefined) => {
    if (!file) return;

    const reader = new FileReader();
    reader.onload = () => runImport(String(reader.result ?? ''));
    reader.onerror = () =>
      toast.error('That file could not be opened', 'Try pasting its contents instead.');
    reader.readAsText(file);
  };

  const complete = pairs.filter(
    (pair) => pair.question.trim() !== '' && pair.answer.trim() !== ''
  ).length;

  return (
    <div className="space-y-4">
      <div className="flex items-start justify-between gap-3">
        <p className="text-xs text-content-secondary">
          {complete === 0
            ? 'A question and its answer are indexed together, so a match returns the whole answer.'
            : `${complete} ${complete === 1 ? 'pair is' : 'pairs are'} ready to index. Pairs missing a question or an answer are skipped.`}
        </p>
        {/* shrink-0 so the label never wraps to two lines: the counter beside
            it grows with the pair count and was squeezing the button. */}
        <Button
          variant="ghost"
          className="shrink-0 whitespace-nowrap"
          onClick={() => setShowImport((open) => !open)}
          aria-expanded={showImport}
        >
          {showImport ? 'Close import' : 'Import CSV'}
        </Button>
      </div>

      {showImport && (
        <div className="space-y-3 rounded-lg border border-border bg-surface-sunken p-3">
          <p className="text-xs leading-relaxed text-content-secondary">
            Two columns: the question, then the answer. A third column is
            treated as the page the answer came from. A header row is detected
            and skipped.
          </p>

          <input
            ref={fileInput}
            type="file"
            accept=".csv,text/csv,text/plain"
            className="sr-only"
            onChange={(event) => {
              onFile(event.target.files?.[0]);
              event.target.value = '';
            }}
          />

          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" onClick={() => fileInput.current?.click()}>
              Choose a file
            </Button>
            <Button
              variant="secondary"
              onClick={() => runImport(csv)}
              disabled={csv.trim() === ''}
              loading={parse.isPending}
            >
              Read pasted text
            </Button>
          </div>

          <Field label="Or paste the rows" hint="Useful for a handful of pairs.">
            {({ id, describedBy }) => (
              <Textarea
                id={id}
                aria-describedby={describedBy}
                rows={4}
                value={csv}
                onChange={(event) => setCsv(event.target.value)}
                placeholder={'Do you ship to Ireland?,"Yes — £8, four working days."'}
              />
            )}
          </Field>
        </div>
      )}

      <ul className="space-y-3">
        {pairs.map((pair, index) => (
          <li
            key={index}
            className="space-y-3 rounded-lg border border-border bg-surface-sunken p-3"
          >
            <div className="flex items-start justify-between gap-3">
              <span className="pt-1 text-xs font-medium text-content-tertiary">
                {index + 1}
              </span>
              <Button
                variant="ghost"
                onClick={() => remove(index)}
                aria-label={`Remove pair ${index + 1}`}
              >
                Remove
              </Button>
            </div>

            <Field label="Question">
              {({ id, describedBy }) => (
                <Input
                  id={id}
                  aria-describedby={describedBy}
                  value={pair.question}
                  onChange={(event) => update(index, { question: event.target.value })}
                  placeholder="Do you ship to Ireland?"
                />
              )}
            </Field>

            <Field
              label="Answer"
              hint="Written the way a clerk should say it. This is what a visitor sees."
            >
              {({ id, describedBy }) => (
                <Textarea
                  id={id}
                  aria-describedby={describedBy}
                  rows={3}
                  value={pair.answer}
                  onChange={(event) => update(index, { answer: event.target.value })}
                  placeholder="Yes — delivery is £8 and takes four working days."
                />
              )}
            </Field>

            <Field
              label="Source page"
              hint="Optional. Shown as the citation when this answer is used."
            >
              {({ id, describedBy }) => (
                <Input
                  id={id}
                  aria-describedby={describedBy}
                  value={pair.url}
                  onChange={(event) => update(index, { url: event.target.value })}
                  placeholder="https://example.com/delivery"
                  mono
                />
              )}
            </Field>
          </li>
        ))}
      </ul>

      <Button variant="secondary" onClick={() => onChange([...pairs, emptyPair()])}>
        Add another pair
      </Button>
    </div>
  );
}
