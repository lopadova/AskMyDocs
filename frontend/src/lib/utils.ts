import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * shadcn/ui class-name helper: merge conditional classes (clsx) and
 * de-duplicate conflicting Tailwind utilities (tailwind-merge) so the
 * last-wins rule holds when a consumer overrides a component default.
 */
export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
