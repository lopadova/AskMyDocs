/**
 * Sanitizzazione difensiva di ogni testo letto dal DOM prima dell'invio al
 * backend/modello (port di KITT utils/sanitize.js, spec §3). Prima linea
 * anti prompt-injection: il modello non deve poter vedere tag HTML o fence
 * di codice provenienti dai valori della pagina.
 */

const ZERO_WIDTH = /[​‌‍⁠﻿]/g;

export function sanitizeText(input: unknown): string {
    let str = String(input ?? '');
    str = str.replace(/[<>]/g, ' '); // niente markup
    str = str.replace(/```/g, '   '); // niente code fence
    str = str.replace(ZERO_WIDTH, ''); // niente caratteri zero-width
    str = str.replace(/\s+/g, ' ').trim();

    return str;
}

/** Tronca preservando la sanitizzazione già applicata. */
export function clamp(value: string, max: number): string {
    return value.length > max ? value.slice(0, max) : value;
}

/** sanitizeText + clamp in un colpo. */
export function clean(input: unknown, max: number): string {
    return clamp(sanitizeText(input), max);
}
