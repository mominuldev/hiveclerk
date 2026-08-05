import { boot } from '@/boot';

/**
 * Shape returned by every endpoint. Documented in Deliverable 9 §1.3.
 */
export interface ApiEnvelope<T> {
  data: T;
  meta?: {
    pagination?: {
      page: number;
      per_page: number;
      total: number;
      total_pages: number;
    };
  };
}

export interface ApiErrorBody {
  code: string;
  message: string;
  data?: {
    status: number;
    errors?: Record<string, string[]>;
  };
}

/**
 * An error carrying the server's own code and field errors, so callers can
 * react to `hvc_licence_required` without string-matching a message.
 */
export class ApiError extends Error {
  readonly code: string;
  readonly status: number;
  readonly fieldErrors: Record<string, string[]>;

  constructor(body: ApiErrorBody, status: number) {
    super(body.message);
    this.name = 'ApiError';
    this.code = body.code;
    this.status = body.data?.status ?? status;
    this.fieldErrors = body.data?.errors ?? {};
  }
}

type Query = Record<string, string | number | boolean | undefined | null>;

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
  body?: unknown;
  query?: Query;
  signal?: AbortSignal;
}

function buildUrl(path: string, query?: Query): string {
  const { restUrl } = boot();
  const url = new URL(
    `${restUrl.replace(/\/$/, '')}/${path.replace(/^\//, '')}`,
    window.location.origin
  );

  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null) {
      url.searchParams.set(key, String(value));
    }
  }

  return url.toString();
}

export async function request<T>(
  path: string,
  options: RequestOptions = {}
): Promise<ApiEnvelope<T>> {
  const { method = 'GET', body, query, signal } = options;

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-WP-Nonce': boot().nonce,
  };

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(buildUrl(path, query), {
    method,
    headers,
    credentials: 'same-origin',
    ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    ...(signal ? { signal } : {}),
  });

  if (response.status === 204) {
    return { data: undefined as T };
  }

  const payload: unknown = await response.json().catch(() => null);

  if (!response.ok) {
    const fallback: ApiErrorBody = {
      code: 'hvc_server_error',
      message: `Request failed with status ${response.status}.`,
    };

    throw new ApiError(
      (payload as ApiErrorBody | null) ?? fallback,
      response.status
    );
  }

  return payload as ApiEnvelope<T>;
}

export const api = {
  get: <T>(path: string, query?: Query, signal?: AbortSignal) =>
    request<T>(path, { method: 'GET', ...(query ? { query } : {}), ...(signal ? { signal } : {}) }),
  post: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'POST', body }),
  put: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'PUT', body }),
  patch: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'PATCH', body }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
};
