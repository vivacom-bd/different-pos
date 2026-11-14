/**
 * Browser features that only exist on HTTPS or localhost, with fallbacks so the app also works on
 * plain HTTP (the dev server). crypto.getRandomValues() is available in every context.
 */

/** RFC 4122 version 4 UUID. */
export function randomUuid(): string {
    const crypto = globalThis.crypto;
    if (typeof crypto.randomUUID === "function") {
        return crypto.randomUUID();
    }

    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x40;
    bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join(
        "",
    );

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** Copies text, resolving to whether it worked. */
export async function copyToClipboard(text: string): Promise<boolean> {
    if (navigator.clipboard) {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch {
            return copyWithSelection(text);
        }
    }

    return copyWithSelection(text);
}

function copyWithSelection(text: string): boolean {
    const previous =
        document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;
    const field = document.createElement("textarea");
    field.value = text;
    field.readOnly = true;
    field.setAttribute("aria-hidden", "true");
    field.style.position = "fixed";
    field.style.opacity = "0";
    // Inside an open dialog, because its focus trap would take focus back from anywhere else.
    (previous?.closest('[role="dialog"]') ?? document.body).append(field);
    field.select();

    try {
        return document.execCommand("copy");
    } catch {
        return false;
    } finally {
        field.remove();
        previous?.focus();
    }
}
