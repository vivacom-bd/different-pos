/**
 * Composer helpers for the WhatsApp & SMS hub: SMS segment counting (GSM-7 vs UCS-2, so Bangla text
 * costs more parts), the cost estimate in integer paisa, variable-chip insertion at the caret and the
 * phone-preview rendering and the send's idempotency key. Pure functions; the server fills the real values
 * per customer (SendSmsBroadcast / FanOutSmsBroadcast).
 */

import { randomUuid } from '@/lib/browser';

const GSM_BASIC =
    "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?" +
    "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
const GSM_EXTENDED = "^{}\\[~]|€\f";

const basic = new Set(GSM_BASIC);
const extended = new Set(GSM_EXTENDED);

export type SmsEncoding = "gsm7" | "ucs2";

export type SegmentInfo = {
    encoding: SmsEncoding;
    /** Characters as the network counts them (GSM extension characters count twice, UCS-2 counts UTF-16 units). */
    units: number;
    segments: number;
    /** Characters per segment for this message (160/153 GSM-7, 70/67 UCS-2). */
    perSegment: number;
};

export function smsSegments(text: string): SegmentInfo {
    let gsm = true;
    let units = 0;
    for (const ch of text) {
        if (basic.has(ch)) {
            units += 1;
        } else if (extended.has(ch)) {
            units += 2;
        } else {
            gsm = false;
            break;
        }
    }

    if (!gsm) {
        units = text.length;
    }
    const single = gsm ? 160 : 70;
    const multi = gsm ? 153 : 67;
    const segments =
        units === 0 ? 0 : units <= single ? 1 : Math.ceil(units / multi);

    return {
        encoding: gsm ? "gsm7" : "ucs2",
        units,
        segments,
        perSegment: segments > 1 ? multi : single,
    };
}

/** Estimated cost in paisa: segments × recipients × price per segment (all integers). */
export function smsCost(
    segments: number,
    recipients: number,
    perSegmentPaisa: number,
): number {
    return (
        Math.max(0, segments) *
        Math.max(0, recipients) *
        Math.max(0, perSegmentPaisa)
    );
}

/**
 * Inserts `{variable}` at the caret (replacing a selection). A space is added before it when the
 * previous character is not whitespace, so chips never glue onto a word. Returns the new caret.
 */
export function insertVariable(
    text: string,
    variable: string,
    start: number = text.length,
    end: number = start,
): { text: string; caret: number } {
    const from = Math.max(0, Math.min(start, text.length));
    const to = Math.max(from, Math.min(end, text.length));
    const before = text.slice(0, from);
    const pad = before !== "" && !/\s$/.test(before) ? " " : "";
    const token = `${pad}{${variable}}`;

    return {
        text: before + token + text.slice(to),
        caret: from + token.length,
    };
}

/** Replaces known `{placeholder}`s; unknown ones stay as typed (the server does the same). */
export function renderPreview(
    body: string,
    values: Record<string, string>,
): string {
    return body.replace(/\{([a-z_]+)\}/g, (match, key: string) =>
        Object.prototype.hasOwnProperty.call(values, key)
            ? (values[key] ?? match)
            : match,
    );
}

/** The person placeholders: templates use {customer}, the composer chips {name}; both mean the same. */
const PERSON_ALIASES = ["name", "customer"] as const;

/**
 * The values the phone preview renders with. `live` is the server's payload for the picked customer
 * (GET /app/messages/preview, the same BroadcastAudience::values the send path fills), so the due and
 * the number are the customer's real ones; without a customer the samples stand in. {name} and
 * {customer} are kept in step whichever one the source filled.
 */
export function previewValues(
    sample: Record<string, string>,
    live: Record<string, string> | null,
): Record<string, string> {
    const values = { ...sample, ...(live ?? {}) };
    const person =
        PERSON_ALIASES.map((k) => live?.[k]).find((v) => v !== undefined) ??
        PERSON_ALIASES.map((k) => sample[k]).find((v) => v !== undefined);
    if (person !== undefined) {
        for (const key of PERSON_ALIASES) {
            values[key] = person;
        }
    }

    return values;
}

export type BroadcastKey = { key: string; fingerprint: string };

/**
 * The idempotency key for a send. The composer makes one when it opens; a send of the same payload
 * (a double click, a retry after a timeout) reuses it so the server returns the first broadcast, and a
 * changed payload (other text or audience) gets a fresh key.
 */
export function broadcastKey(
    current: BroadcastKey,
    fingerprint: string,
    make: () => string = () => randomUuid(),
): BroadcastKey {
    if (current.fingerprint === "" || current.fingerprint === fingerprint) {
        return { key: current.key, fingerprint };
    }

    return { key: make(), fingerprint };
}
