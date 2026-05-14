import { describe, expect, it, jest } from '@jest/globals';
import express from 'express';
import request from 'supertest';

import { internalAuthMiddleware } from '../../src/auth.js';

function buildApp(token: string) {
    const app = express();
    app.use(internalAuthMiddleware(token));
    app.get('/protected', (_req, res) => {
        res.json({ ok: true });
    });
    return app;
}

describe('internalAuthMiddleware', () => {
    it('rejects request without Authorization header', async () => {
        const app = buildApp('secret-token-1234');
        const response = await request(app).get('/protected');
        expect(response.status).toBe(401);
        expect(response.body.error).toMatch(/Invalid or missing/i);
    });

    it('rejects request with a wrong bearer token', async () => {
        const app = buildApp('secret-token-1234');
        const response = await request(app)
            .get('/protected')
            .set('Authorization', 'Bearer not-the-right-token');
        expect(response.status).toBe(401);
    });

    it('accepts request with the correct bearer token', async () => {
        const app = buildApp('secret-token-1234');
        const response = await request(app)
            .get('/protected')
            .set('Authorization', 'Bearer secret-token-1234');
        expect(response.status).toBe(200);
        expect(response.body).toEqual({ ok: true });
    });

    it('returns 503 when expected token is empty (misconfigured)', async () => {
        const app = buildApp('');
        const response = await request(app)
            .get('/protected')
            .set('Authorization', 'Bearer whatever');
        expect(response.status).toBe(503);
    });

    it('rejects tokens of different length (timing-safe)', async () => {
        const app = buildApp('secret');
        const response = await request(app)
            .get('/protected')
            .set('Authorization', 'Bearer secret-but-longer');
        expect(response.status).toBe(401);
    });
});
