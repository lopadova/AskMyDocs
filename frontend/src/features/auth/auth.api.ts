import { api, ensureCsrfCookie, resetCsrf } from '../../lib/api';
import type { AuthMePayload, AuthUser } from '../../lib/auth-store';

export type LoginResponse = {
    user: AuthUser;
    abilities?: unknown[];
};

export async function login(email: string, password: string, remember: boolean): Promise<LoginResponse> {
    await ensureCsrfCookie();
    const { data } = await api.post<LoginResponse>('/api/auth/login', {
        email,
        password,
        remember,
    });
    return data;
}

export async function logout(): Promise<void> {
    await api.post('/api/auth/logout');
    resetCsrf();
}

export async function me(): Promise<AuthMePayload> {
    const { data } = await api.get<AuthMePayload>('/api/auth/me');
    return data;
}

export async function forgot(email: string): Promise<void> {
    await ensureCsrfCookie();
    await api.post('/api/auth/forgot-password', { email });
}

export async function reset(
    token: string,
    email: string,
    password: string,
    password_confirmation: string,
): Promise<void> {
    await ensureCsrfCookie();
    await api.post('/api/auth/reset-password', {
        token,
        email,
        password,
        password_confirmation,
    });
}
