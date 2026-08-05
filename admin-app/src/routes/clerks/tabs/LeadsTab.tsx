import { Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Field, Input, Select, Textarea } from '@/components/ui/Field';
import type { AgentDetail, AgentInput } from '@/api/queries/useAgents';

interface Question {
  key: string;
  question: string;
  type: string;
  required: boolean;
}

interface LeadConfig extends Record<string, unknown> {
  enabled?: boolean;
  ask_after?: number;
  scan_replies?: boolean;
  consent_text?: string | null;
  questions?: Question[];
}

interface LeadsTabProps {
  agent: AgentDetail;
  onChange: (changes: AgentInput) => void;
}

/**
 * What this clerk is allowed to ask for (FR-LED-01, FR-LED-02).
 *
 * Off by default and off for every existing clerk. A clerk that started
 * asking for email addresses because the plugin updated would have
 * changed the customer's site behaviour without being told to, and the
 * first person to notice is a visitor.
 *
 * The `key` is fixed once it exists. It is what the answer is stored
 * under, what a scoring rule addresses as `custom.budget`, and what a CRM
 * mapping binds to next sprint — so rewording the question must not
 * orphan every answer already collected.
 */
export function LeadsTab({ agent, onChange }: LeadsTabProps) {
  const config: LeadConfig = agent.lead_config ?? {};
  const questions = config.questions ?? [];

  const patch = (changes: LeadConfig): void => {
    onChange({ lead_config: { ...config, ...changes } });
  };

  const patchQuestion = (index: number, changes: Partial<Question>): void => {
    patch({
      questions: questions.map((question, position) =>
        position === index ? { ...question, ...changes } : question
      ),
    });
  };

  return (
    <div className="space-y-6">
      <label className="flex items-start gap-2.5 text-sm text-content">
        <input
          type="checkbox"
          className="mt-0.5"
          checked={config.enabled === true}
          onChange={(event) => patch({ enabled: event.target.checked })}
        />
        <span>
          Let {agent.name} collect contact details
          <span className="mt-0.5 block text-xs text-content-secondary">
            The clerk asks in conversation, never as a form wall, and always
            accepts no for an answer.
          </span>
        </span>
      </label>

      {config.enabled === true && (
        <>
          <div className="grid gap-4 md:grid-cols-2">
            <Field
              label="Ask after"
              hint="Visitor messages before the clerk asks for an address. Asking on the first message reads as a form wall and produces junk addresses."
            >
              {({ id }) => (
                <Input
                  id={id}
                  type="number"
                  min={0}
                  max={20}
                  value={config.ask_after ?? 2}
                  onChange={(event) =>
                    patch({ ask_after: Number(event.target.value) })
                  }
                />
              )}
            </Field>

            <Field
              label="Read details from what they type"
              hint="Picks up an address or phone number the visitor mentions in passing. Names and companies are only taken when stated outright."
            >
              {({ id }) => (
                <Select
                  id={id}
                  value={config.scan_replies === false ? 'no' : 'yes'}
                  onChange={(event) =>
                    patch({ scan_replies: event.target.value === 'yes' })
                  }
                >
                  <option value="yes">Yes</option>
                  <option value="no">No — only what they submit</option>
                </Select>
              )}
            </Field>
          </div>

          <Field
            label="Marketing consent wording"
            hint="Said verbatim before asking for permission. Leave empty if you do not ask for marketing consent here."
          >
            {({ id }) => (
              <Textarea
                id={id}
                rows={2}
                value={config.consent_text ?? ''}
                onChange={(event) => patch({ consent_text: event.target.value })}
              />
            )}
          </Field>

          <section className="space-y-3">
            <div className="flex items-center justify-between">
              <div>
                <h3 className="text-sm font-medium text-content">
                  Qualification questions
                </h3>
                <p className="text-xs text-content-secondary">
                  Asked one at a time, in the clerk&rsquo;s own words. Answers
                  become fields you can score on and export.
                </p>
              </div>

              <Button
                size="sm"
                icon={<Plus size={14} />}
                disabled={questions.length >= 6}
                onClick={() =>
                  patch({
                    questions: [
                      ...questions,
                      {
                        key: `question_${questions.length + 1}`,
                        question: '',
                        type: 'text',
                        required: false,
                      },
                    ],
                  })
                }
              >
                Add
              </Button>
            </div>

            {questions.length === 0 ? (
              <p className="rounded-lg border border-border bg-surface-sunken p-3 text-sm text-content-secondary">
                No questions yet. {agent.name} will still ask for an email
                address — these are the extra things worth knowing, like budget
                or timeline.
              </p>
            ) : (
              <ul className="space-y-2">
                {questions.map((question, index) => (
                  <li
                    key={question.key}
                    className="grid grid-cols-1 gap-2 rounded-lg border border-border p-3 md:grid-cols-12"
                  >
                    <div className="md:col-span-7">
                      <Input
                        aria-label="Question"
                        placeholder="What is your budget for this?"
                        value={question.question}
                        onChange={(event) =>
                          patchQuestion(index, { question: event.target.value })
                        }
                      />
                    </div>

                    <div className="md:col-span-3">
                      <Input
                        aria-label="Stored as"
                        placeholder="budget"
                        value={question.key}
                        onChange={(event) =>
                          patchQuestion(index, { key: event.target.value })
                        }
                      />
                    </div>

                    <div className="md:col-span-2 flex items-center justify-end">
                      <Button
                        size="sm"
                        variant="ghost"
                        aria-label="Remove question"
                        icon={<Trash2 size={14} />}
                        onClick={() =>
                          patch({
                            questions: questions.filter(
                              (_entry, position) => position !== index
                            ),
                          })
                        }
                      />
                    </div>

                    <p className="md:col-span-12 text-xs text-content-tertiary">
                      Stored as{' '}
                      <code className="text-content-secondary">
                        custom.{question.key || '…'}
                      </code>{' '}
                      — scoring rules and exports use that name, so changing it
                      leaves answers already collected under the old one.
                    </p>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      )}
    </div>
  );
}
