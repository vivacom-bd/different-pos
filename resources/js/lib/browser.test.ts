import { afterEach, describe, expect, it, vi } from "vitest";
import { randomUuid } from "./browser";

const UUID_V4 =
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

describe("randomUuid", () => {
    afterEach(() => vi.unstubAllGlobals());

    it("uses the native generator when the browser offers it", () => {
        expect(randomUuid()).toMatch(UUID_V4);
    });

    it("still makes unique version 4 ids on plain HTTP, where randomUUID is missing", () => {
        vi.stubGlobal("crypto", {
            getRandomValues: (bytes: Uint8Array) =>
                globalThis.Array.from(bytes).reduce((out, _, i) => {
                    out[i] = Math.floor(Math.random() * 256);

                    return out;
                }, bytes),
        });

        const first = randomUuid();

        expect(first).toMatch(UUID_V4);
        expect(randomUuid()).not.toBe(first);
    });
});
