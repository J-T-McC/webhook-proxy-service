import { expect, request } from '@playwright/test';

const MAILPIT_URL = process.env.E2E_MAILPIT_URL ?? 'http://localhost:8025';

type MailpitMessage = { ID: string };

/**
 * Waits for the newest message addressed to `email` and returns its text body.
 * Mailpit is the mail transport in Sail and in the CI job, so the spec reads a
 * real delivered message rather than a faked notification.
 */
export async function latestMessageText(email: string): Promise<string> {
    const api = await request.newContext({ baseURL: MAILPIT_URL });

    try {
        let messageId: string | null = null;

        await expect(async () => {
            const response = await api.get('/api/v1/search', {
                params: { query: `to:${email}`, limit: 1 },
            });
            expect(response.ok()).toBeTruthy();

            const body = (await response.json()) as {
                messages?: MailpitMessage[];
            };
            messageId = body.messages?.[0]?.ID ?? null;
            expect(messageId).not.toBeNull();
        }).toPass({ timeout: 20_000 });

        const message = await api.get(`/api/v1/message/${messageId}`);
        const body = (await message.json()) as { Text?: string; HTML?: string };

        return body.Text ?? body.HTML ?? '';
    } finally {
        await api.dispose();
    }
}

/**
 * The first link in a message body that points at `path` on this application.
 *
 * The path matters: a Laravel markdown mail opens with the application name
 * linked to the site root, so "the first link" is the header, not the one the
 * message is about.
 */
export function applicationLink(
    body: string,
    baseURL: string,
    path: string,
): string {
    const origin = new URL(baseURL).origin;
    const start = body.indexOf(`${origin}${path}`);

    if (start === -1) {
        throw new Error(`No ${origin}${path} link found in the message body.`);
    }

    // Mail bodies wrap and punctuate: stop at the first whitespace or quote, then
    // drop trailing characters that belong to the sentence rather than the URL.
    return body
        .slice(start)
        .split(/[\s"'<>]/)[0]
        .replace(/[).,]+$/, '');
}
