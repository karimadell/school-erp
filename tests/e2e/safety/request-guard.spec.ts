import { expect, test } from '@playwright/test';
import { isPhaseZeroRequestAllowed } from '../fixtures/auth.fixture';

const uatOrigin = new URL(process.env.UAT_BASE_URL!).origin;
const livewireUrl = `${uatOrigin}/livewire-7faaae89/update`;
const livewireHeaders = {
    'content-type': 'application/json',
    'x-livewire': '1',
};

function livewirePayload(calls: unknown[] = [], updates: Record<string, unknown> = {}): string {
    return JSON.stringify({
        _token: 'test-token',
        components: [{
            snapshot: '{"memo":{"path":"admin","method":"GET"},"checksum":"signed"}',
            updates,
            calls,
        }],
    });
}

test('allows Cloudflare infrastructure requests only on the configured UAT origin', () => {
    expect(isPhaseZeroRequestAllowed('POST', `${uatOrigin}/cdn-cgi/challenge-platform/verify`)).toBe(true);
    expect(isPhaseZeroRequestAllowed('PATCH', `${uatOrigin}/cdn-cgi/challenge-platform/verify`)).toBe(true);
    expect(isPhaseZeroRequestAllowed('POST', 'https://example.invalid/cdn-cgi/challenge-platform/verify')).toBe(false);
});

test('allows only login and logout application POST requests', () => {
    expect(isPhaseZeroRequestAllowed('POST', `${uatOrigin}/login`)).toBe(true);
    expect(isPhaseZeroRequestAllowed('POST', `${uatOrigin}/logout`)).toBe(true);
    expect(isPhaseZeroRequestAllowed('POST', `${uatOrigin}/dashboard/invoices`)).toBe(false);
    expect(isPhaseZeroRequestAllowed('POST', 'https://example.invalid/login')).toBe(false);
});

test('blocks mutating application methods while retaining read-only methods', () => {
    for (const method of ['PUT', 'PATCH', 'DELETE']) {
        expect(isPhaseZeroRequestAllowed(method, `${uatOrigin}/dashboard/students/1`)).toBe(false);
    }

    for (const method of ['GET', 'HEAD', 'OPTIONS']) {
        expect(isPhaseZeroRequestAllowed(method, `${uatOrigin}/dashboard`)).toBe(true);
    }
});

test('allows strictly read-only Livewire hydration and lazy rendering payloads', () => {
    expect(isPhaseZeroRequestAllowed('POST', livewireUrl, livewirePayload(), livewireHeaders)).toBe(true);
    expect(isPhaseZeroRequestAllowed('POST', livewireUrl, livewirePayload([{
        method: '__lazyLoad',
        params: ['signed-lazy-mount-snapshot'],
        metadata: {},
    }]), livewireHeaders)).toBe(true);
});

test('blocks explicit Livewire actions and component state updates', () => {
    for (const method of ['save', 'create', 'delete', 'mountAction', 'callMountedAction']) {
        expect(isPhaseZeroRequestAllowed('POST', livewireUrl, livewirePayload([{
            method,
            params: [],
            metadata: {},
        }]), livewireHeaders)).toBe(false);
    }

    expect(isPhaseZeroRequestAllowed(
        'POST',
        livewireUrl,
        livewirePayload([], { 'data.amount': '1000' }),
        livewireHeaders,
    )).toBe(false);
});

test('blocks malformed, cross-origin, and unmarked Livewire requests', () => {
    expect(isPhaseZeroRequestAllowed('POST', livewireUrl, '{bad json', livewireHeaders)).toBe(false);
    expect(isPhaseZeroRequestAllowed('POST', livewireUrl, livewirePayload(), {})).toBe(false);
    expect(isPhaseZeroRequestAllowed(
        'POST',
        'https://example.invalid/livewire-7faaae89/update',
        livewirePayload(),
        livewireHeaders,
    )).toBe(false);
});
