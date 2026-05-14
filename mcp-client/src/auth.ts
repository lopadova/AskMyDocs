import { Request, Response, NextFunction } from 'express';

export function internalAuthMiddleware(expectedToken: string) {
    return (req: Request, res: Response, next: NextFunction): void => {
        if (!expectedToken) {
            res.status(503).json({ error: 'Sidecar internal auth token not configured' });
            return;
        }

        const header = req.header('authorization') ?? req.header('Authorization') ?? '';
        const provided = header.startsWith('Bearer ') ? header.slice('Bearer '.length) : '';

        if (!provided || !timingSafeEquals(provided, expectedToken)) {
            res.status(401).json({ error: 'Invalid or missing sidecar auth token' });
            return;
        }

        next();
    };
}

function timingSafeEquals(a: string, b: string): boolean {
    if (a.length !== b.length) {
        return false;
    }
    let diff = 0;
    for (let i = 0; i < a.length; i++) {
        diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
    }
    return diff === 0;
}
