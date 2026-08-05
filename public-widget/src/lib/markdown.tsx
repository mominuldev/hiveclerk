/** @jsxImportSource preact */
/**
 * A very small Markdown renderer.
 *
 * ## Why this is not a library, and not `innerHTML`
 *
 * Model output is untrusted input (SEC-07). The usual pipeline —
 * markdown-to-HTML, then sanitise, then inject — has three places to get
 * wrong and ships a parser and a sanitiser for the privilege, inside a
 * 40 KB budget.
 *
 * This renderer never produces HTML. It produces Preact nodes, and Preact
 * escapes every text node it is given. A reply containing
 * `<img src=x onerror=…>` renders as those literal characters, because
 * there is no code path anywhere in this file that could turn a string
 * into an element. That property is structural rather than enforced, which
 * is the only kind worth relying on for the thing an attacker controls.
 *
 * It supports what a clerk actually writes: paragraphs, bullet and
 * numbered lists, bold, italic, inline code, and links. Anything else
 * renders as its own source text, which is a legible failure.
 */

import type { JSX, VNode } from 'preact';

/** Inline spans: code first, so its contents are never re-scanned. */
const INLINE =
  /(`[^`]+`)|(\*\*[^*]+\*\*)|(__[^_]+__)|(\*[^*\n]+\*)|(\[[^\]]+\]\([^)\s]+\))/;

/**
 * Render Markdown-ish text as nodes.
 */
export function markdown(text: string): VNode[] {
  const blocks: VNode[] = [];
  const lines = text.split('\n');

  let paragraph: string[] = [];
  let list: { ordered: boolean; items: string[] } | null = null;
  let key = 0;

  const flushParagraph = (): void => {
    if (paragraph.length) {
      blocks.push(<p key={`p${key++}`}>{inline(paragraph.join(' '))}</p>);
      paragraph = [];
    }
  };

  const flushList = (): void => {
    if (!list) {
      return;
    }

    const items = list.items.map((item, index) => <li key={`li${index}`}>{inline(item)}</li>);

    blocks.push(
      list.ordered ? <ol key={`l${key++}`}>{items}</ol> : <ul key={`l${key++}`}>{items}</ul>,
    );

    list = null;
  };

  for (const raw of lines) {
    const line = raw.trimEnd();

    if (!line.trim()) {
      flushParagraph();
      flushList();
      continue;
    }

    const bullet = /^\s*[-*+]\s+(.*)$/.exec(line);
    const numbered = /^\s*\d+[.)]\s+(.*)$/.exec(line);

    if (bullet || numbered) {
      flushParagraph();

      const ordered = Boolean(numbered);
      const content = (bullet?.[1] ?? numbered?.[1]) as string;

      if (!list || list.ordered !== ordered) {
        flushList();
        list = { ordered, items: [] };
      }

      list.items.push(content);
      continue;
    }

    flushList();
    paragraph.push(line.trim());
  }

  flushParagraph();
  flushList();

  return blocks;
}

/**
 * Render the inline spans of one line.
 */
function inline(text: string): Array<string | VNode> {
  const out: Array<string | VNode> = [];

  let rest = text;
  let key = 0;

  for (;;) {
    const match = INLINE.exec(rest);

    if (!match || match.index === undefined) {
      break;
    }

    if (match.index > 0) {
      out.push(rest.slice(0, match.index));
    }

    const token = match[0];

    out.push(span(token, key++));

    rest = rest.slice(match.index + token.length);
  }

  if (rest) {
    out.push(rest);
  }

  return out;
}

/** One matched inline token as a node. */
function span(token: string, key: number): VNode | string {
  if (token.startsWith('`')) {
    return <code key={key}>{token.slice(1, -1)}</code>;
  }

  if (token.startsWith('**') || token.startsWith('__')) {
    return <strong key={key}>{token.slice(2, -2)}</strong>;
  }

  if (token.startsWith('*')) {
    return <em key={key}>{token.slice(1, -1)}</em>;
  }

  const link = /^\[([^\]]+)\]\(([^)\s]+)\)$/.exec(token);

  if (link) {
    const href = safeHref(link[2] ?? '');

    // An unsafe scheme renders as the label alone rather than as a dead
    // link. `javascript:` in a reply is either an attack or a mistake, and
    // neither is worth an anchor element.
    if (!href) {
      return link[1] ?? token;
    }

    return (
      <a key={key} href={href} target="_blank" rel="noopener noreferrer nofollow">
        {link[1]}
      </a>
    );
  }

  return token;
}

/**
 * A URL safe to put in an href, or null.
 *
 * Allowlisted rather than blocklisted: `javascript:`, `data:` and `vbscript:`
 * are the ones everybody remembers, and the list of the ones they do not
 * is the problem with blocklists.
 */
function safeHref(url: string): string | null {
  const trimmed = url.trim();

  if (trimmed.startsWith('/') || trimmed.startsWith('#')) {
    return trimmed;
  }

  return /^https?:\/\//i.test(trimmed) ? trimmed : null;
}

export type { JSX };
