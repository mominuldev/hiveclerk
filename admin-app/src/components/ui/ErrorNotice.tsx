import { ApiError } from '@/api/client';
import { Button } from '@/components/ui/Button';

interface ErrorNoticeProps {
  error: unknown;
  onRetry?: () => void;
}

/**
 * Turn an error into something a person can act on.
 *
 * Errors say what happened and what to do next. They never apologise and
 * they are never vague — "An error occurred" tells the operator nothing
 * they can use.
 */
function describe(error: unknown): { title: string; detail: string } {
  if (error instanceof ApiError) {
    switch (error.code) {
      case 'hvc_unauthorized':
        return {
          title: 'Your session expired',
          detail: 'Reload the page to sign in again.',
        };
      case 'hvc_forbidden':
        return {
          title: 'No access to this',
          detail: 'Your account is missing the capability this screen needs.',
        };
      case 'hvc_rate_limited':
        return {
          title: 'Too many requests',
          detail: 'Wait a moment, then try again.',
        };
      case 'hvc_licence_required':
        return {
          title: 'This needs a Pro licence',
          detail: 'Activate a licence in Settings to use it.',
        };
      default:
        return { title: 'That request failed', detail: error.message };
    }
  }

  return {
    title: "Couldn't reach the server",
    detail: 'Check that the site is reachable, then try again.',
  };
}

export function ErrorNotice({ error, onRetry }: ErrorNoticeProps) {
  const { title, detail } = describe(error);

  return (
    <div
      role="alert"
      className="rounded-lg border border-border bg-surface p-5"
    >
      <p className="text-sm font-semibold text-content">{title}</p>
      <p className="mt-1 text-sm text-content-secondary">{detail}</p>
      {onRetry && (
        <Button size="sm" onClick={onRetry} className="mt-3">
          Try again
        </Button>
      )}
    </div>
  );
}
