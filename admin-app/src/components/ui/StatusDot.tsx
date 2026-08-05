import { cn } from '@/lib/cn';

export type DutyStatus =
  | 'on_duty'
  | 'indexing'
  | 'paused'
  | 'draft'
  | 'error';

interface StatusMeta {
  label: string;
  colour: string;
  glyph: string;
  pulse: boolean;
}

/**
 * Status is never conveyed by colour alone: every state pairs a colour with
 * a distinct glyph and a text label, so it survives greyscale, colour
 * blindness and screen readers.
 */
const STATUS: Record<DutyStatus, StatusMeta> = {
  on_duty: { label: 'On duty', colour: 'text-on-duty', glyph: '●', pulse: false },
  indexing: { label: 'Indexing', colour: 'text-indexing', glyph: '◐', pulse: true },
  paused: { label: 'Paused', colour: 'text-paused', glyph: '○', pulse: false },
  draft: { label: 'Draft', colour: 'text-draft', glyph: '◌', pulse: false },
  error: { label: 'Needs attention', colour: 'text-danger', glyph: '⊘', pulse: false },
};

interface StatusDotProps {
  status: DutyStatus;
  /** Hide the text label. Only for the Roster rail, where space is tight and
   *  the accessible name is carried by aria-label. */
  iconOnly?: boolean;
  className?: string;
}

export function StatusDot({ status, iconOnly = false, className }: StatusDotProps) {
  // A miss used to read `.label` off undefined and throw. There is no error
  // boundary above this, so one unmapped value did not break a badge — it
  // unmounted the whole admin and the screen went white. Falling back to
  // draft renders something honest and keeps the rest of the page alive;
  // the type is still the real guard against getting here.
  const meta = STATUS[status] ?? STATUS.draft;

  return (
    <span
      role="status"
      aria-label={meta.label}
      className={cn('inline-flex items-center gap-1.5', className)}
    >
      <span
        aria-hidden="true"
        className={cn(meta.colour, 'text-[10px] leading-none', meta.pulse && 'hvc-pulse')}
      >
        {meta.glyph}
      </span>
      {!iconOnly && (
        <span className="text-xs font-medium text-content-secondary">
          {meta.label}
        </span>
      )}
    </span>
  );
}

export function statusLabel(status: DutyStatus): string {
  return STATUS[status].label;
}
