import { AlertTriangle, Check, Minus } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { Skeleton } from '@/components/ui/Skeleton';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { useSystemHealth } from '@/api/queries/useSystemStatus';
import { cn } from '@/lib/cn';

/**
 * System status (FR-SYS-07).
 *
 * The screen a support conversation starts on, so it is built to be
 * screenshotted and read by somebody who is not looking at the site. Every
 * row states a value rather than a verdict wherever a verdict would be a
 * guess: "no persistent object cache" is a fact with a consequence, not a
 * failure, and colouring it red would send operators to fix something
 * their host may not offer.
 *
 * The three things this exists to surface are the three that are
 * invisible until somebody has already lost a day: a stalled cron, a
 * pending migration, and a queue depth that only grows.
 */
export function SystemStatus() {
  const { data, isPending, isError, error, refetch } = useSystemHealth();

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  if (isPending || !data) {
    return <Skeleton className="h-[560px] w-full rounded-xl" />;
  }

  const migrationPending = data.database.version < data.database.latest;

  return (
    <div className="space-y-5">
      {(migrationPending ||
        data.cron.overdue > 0 ||
        data.cron.stalled > 0 ||
        data.database.missing.length > 0) && (
        <div
          className="flex items-start gap-2 rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 text-sm text-content"
          role="status"
        >
          <AlertTriangle size={16} aria-hidden="true" className="mt-0.5 shrink-0" />
          <div className="space-y-1">
            {migrationPending && (
              <p>
                The database is at version {data.database.version} and the plugin
                expects {data.database.latest}. Migrations run on the next admin
                page load; if this persists, something is failing them.
              </p>
            )}
            {data.database.missing.length > 0 && (
              <p>
                {data.database.missing.length} table
                {1 === data.database.missing.length ? ' is' : 's are'} missing:{' '}
                <span className="font-mono text-xs">
                  {data.database.missing.join(', ')}
                </span>
              </p>
            )}
            {data.cron.overdue > 0 && (
              <p>
                {data.cron.overdue} scheduled{' '}
                {1 === data.cron.overdue ? 'job is' : 'jobs are'} more than an hour
                late. On a quiet site WP-Cron only fires when somebody visits — a
                real cron entry hitting wp-cron.php fixes it.
              </p>
            )}
            {data.cron.stalled > 0 && (
              <p>
                {data.cron.stalled} scheduled{' '}
                {1 === data.cron.stalled ? 'job is' : 'jobs are'} being rescheduled
                but never actually running. The times below will keep looking
                correct, because WordPress books the next run whether or not
                anything answered the last one. The usual cause is cron running
                under a different PHP version from the site — check that the
                PHP your cron entry uses is the same one the site runs on.
              </p>
            )}
          </div>
        </div>
      )}

      <div className="grid gap-5 md:grid-cols-2">
        <Card eyebrow="Environment" title="Server">
          <Rows
            rows={[
              ['PHP', data.php.version],
              [
                data.mysql.mariadb ? 'MariaDB' : 'MySQL',
                data.mysql.version || 'Not reported',
              ],
              ['WordPress', data.wordpress.version],
              ['Character set', `${data.mysql.charset} · ${data.mysql.collation}`],
              ['Memory limit', '-1' === data.php.memory_limit ? 'Unlimited' : data.php.memory_limit],
              [
                'Max execution time',
                '0' === data.php.max_execution_time
                  ? 'Unlimited'
                  : `${data.php.max_execution_time}s`,
              ],
              ['Multisite', data.wordpress.multisite],
              ['OpenSSL', data.php.openssl],
            ]}
          />

          {!data.php.openssl && (
            <p className="mt-3 text-xs leading-relaxed text-content-secondary">
              Without OpenSSL, provider keys cannot be encrypted at rest and no key
              can be saved.
            </p>
          )}
        </Card>

        <Card eyebrow="Storage" title="Database">
          <Rows
            rows={[
              ['Schema version', `${data.database.version} of ${data.database.latest}`],
              [
                'Tables',
                `${data.database.tables_present} of ${data.database.tables_total} present`,
              ],
              ['Persistent object cache', data.object_cache.persistent],
            ]}
          />

          <p className="mt-3 text-xs leading-relaxed text-content-secondary">
            {data.object_cache.note}
          </p>
        </Card>
      </div>

      <Card eyebrow="Background work" title="Queue and schedule">
        <Rows
          rows={[
            ['Driver', 'wp-cron' === data.queue.driver ? 'WP-Cron (fallback)' : data.queue.driver],
            ['Waiting', data.queue.depth < 0 ? 'Not reportable' : String(data.queue.depth)],
            ['WP-Cron', data.wordpress.cron_disabled ? 'Disabled in wp-config' : 'Enabled'],
          ]}
        />

        {0 === data.cron.scheduled ? (
          <p className="mt-4 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs leading-relaxed text-content">
            Nothing is scheduled. Sequences, the analytics rollup and the retention
            purge all depend on recurring jobs, so this means none of them is
            running.
          </p>
        ) : (
          <ul className="mt-4 space-y-1.5">
            {data.cron.events.map((event) => (
              <li
                key={event.hook}
                className="flex flex-wrap items-baseline justify-between gap-2 rounded-lg border border-border bg-surface-sunken px-3 py-2"
              >
                <span className="font-mono text-xs text-content-secondary">
                  {event.hook}
                </span>
                <span
                  className={cn(
                    'text-xs',
                    event.is_stalled || event.is_late
                      ? 'text-warning'
                      : 'text-content-tertiary'
                  )}
                >
                  {/*
                    Last run leads when a job has stalled, because the next-run
                    time is the misleading half: it advances on schedule while
                    nothing happens. Showing "Next 14:20" first would be
                    reassuring and wrong.
                  */}
                  {event.is_stalled
                    ? `${
                        event.last_run
                          ? `Last ran ${event.last_run} UTC`
                          : 'Has never run'
                      } · still scheduled for ${event.next_run} UTC`
                    : `${event.is_late ? 'Overdue since ' : 'Next '}${event.next_run} UTC`}
                  {!event.is_stalled && event.last_run && (
                    <span className="text-content-tertiary">
                      {' '}
                      · last ran {event.last_run} UTC
                    </span>
                  )}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card eyebrow="Models" title="Providers">
        {0 === data.providers.length ? (
          <p className="text-sm text-content-secondary">
            No provider key is configured, so no clerk can answer anything. Add one
            on the Providers tab.
          </p>
        ) : (
          <>
            <ul className="space-y-1.5">
              {data.providers.map((provider) => (
                <li
                  key={provider.provider}
                  className="flex flex-wrap items-baseline justify-between gap-2 rounded-lg border border-border bg-surface-sunken px-3 py-2"
                >
                  <span className="text-sm text-content">
                    {provider.provider}
                    {provider.from_config && (
                      <span className="ml-2 text-xs text-content-tertiary">
                        set in wp-config.php
                      </span>
                    )}
                  </span>
                  <span className="text-xs text-content-tertiary">
                    {provider.verified_at
                      ? `Last answered ${provider.verified_at} UTC`
                      : 'Never verified'}
                  </span>
                </li>
              ))}
            </ul>

            {/*
              Stated rather than implied. An operator reading "last answered
              three weeks ago" needs to know that is a record and not a live
              check, or they will conclude the provider is down.
            */}
            <p className="mt-3 text-xs leading-relaxed text-content-secondary">
              These are the last successful checks, not live probes. Reaching every
              provider on each page load would add their latency to this screen and
              bill you for the model lists. Re-check from the Providers tab.
            </p>
          </>
        )}
      </Card>
    </div>
  );
}

type Row = [label: string, value: string | boolean];

function Rows({ rows }: { rows: Row[] }) {
  return (
    <dl className="divide-y divide-border">
      {rows.map(([label, value]) => (
        <div key={label} className="flex items-baseline justify-between gap-3 py-2">
          <dt className="text-sm text-content-secondary">{label}</dt>
          <dd className="text-sm text-content">
            {'boolean' === typeof value ? <Flag on={value} /> : value}
          </dd>
        </div>
      ))}
    </dl>
  );
}

/**
 * A yes/no that reads as one without relying on colour at all.
 *
 * Uncoloured deliberately. The design system has no success token, and
 * the right response to that is not to invent a green — most rows here
 * are neutral facts rather than passes, and a tick in positive green
 * beside "Multisite: Yes" would assert an approval nobody meant.
 */
function Flag({ on }: { on: boolean }) {
  return (
    <span className="inline-flex items-center gap-1.5">
      {on ? (
        <Check size={13} aria-hidden="true" className="text-content-secondary" />
      ) : (
        <Minus size={13} aria-hidden="true" className="text-content-tertiary" />
      )}
      {on ? 'Yes' : 'No'}
    </span>
  );
}
