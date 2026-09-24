import { revealLicenceKeys } from '@/components/admin/licences/reveal-keys';
import { type IssuedKeysReply } from '@/components/admin/licences/types';
import { showToast } from '@/components/shared/toaster';
import { sendJson } from '@/lib/http';
import { router } from '@inertiajs/react';

/**
 * POST to an endpoint that creates licence keys (reissue, issue missing, issue for a till), show the keys once in
 * the "Licence key created" dialog, then refresh the page data. Resolves true on success.
 */
export async function requestKeys(url: string, options: { title?: string; only?: string[] } = {}): Promise<boolean> {
    const result = await sendJson<IssuedKeysReply>('POST', url);

    if (!result.ok || !result.data) {
        showToast(result.message ?? 'The licence could not be issued.', 'error');

        return false;
    }

    if (result.data.keys.length === 0) {
        showToast(result.data.message);
    } else {
        revealLicenceKeys({ keys: result.data.keys, title: options.title });
    }

    router.reload({ only: options.only });

    return true;
}
