<?php

namespace App\Domain\Pharmacy\Support;

use App\Domain\Pharmacy\Data\TrudRelease;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * NHS TRUD with OUR key (W4): the latest release of item 24 ("NHSBSA dm+d") and its zip. TRUD's API puts the key
 * in the URL path (`/trud/api/v1/keys/{key}/items/24/releases?latest`), so no URL is ever logged and every exception
 * leaves here as a RuntimeException whose message went through DmdSwitch::redact(). Timeouts from config
 * `pharmacy.dmd.trud`. Tests fake it with Http::fake; nothing here is called with the switch off.
 */
class TrudClient
{
    /**
     * @throws RuntimeException
     */
    public function latestRelease(): TrudRelease
    {
        $url = rtrim((string) config('pharmacy.dmd.trud.base_url'), '/')
            .'/keys/'.rawurlencode(DmdSwitch::trudKey()).'/items/'.(int) config('pharmacy.dmd.trud.item', 24).'/releases?latest';

        try {
            $response = Http::connectTimeout((int) config('pharmacy.dmd.trud.connect_timeout', 15))
                ->timeout((int) config('pharmacy.dmd.trud.timeout', 30))
                ->acceptJson()
                ->get($url)
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException(DmdSwitch::redact("TRUD release list answered HTTP {$e->response->status()}: ".mb_substr($e->response->body(), 0, 200)));
        } catch (ConnectionException $e) {
            throw new RuntimeException(DmdSwitch::redact('TRUD release list could not be reached: '.$e->getMessage()));
        }

        $releases = $response->json('releases');

        if (! is_array($releases) || $releases === [] || ! is_array($releases[0] ?? null)) {
            throw new RuntimeException('TRUD listed no release for item '.(int) config('pharmacy.dmd.trud.item', 24).'.');
        }

        return TrudRelease::fromTrud($releases[0]);
    }

    /**
     * Downloads the release's zip to a local file (streamed to disk, never held in memory).
     *
     * @throws RuntimeException
     */
    public function download(TrudRelease $release, string $toFile): void
    {
        $host = strtolower((string) parse_url($release->archiveFileUrl, PHP_URL_HOST));

        if (! in_array($host, array_map('strtolower', (array) config('pharmacy.dmd.trud.download_hosts', [])), true)) {
            throw new RuntimeException("TRUD listed {$release->archiveFileName} on a host the portal does not download from ({$host}).");
        }

        try {
            $response = Http::connectTimeout((int) config('pharmacy.dmd.trud.connect_timeout', 15))
                ->timeout((int) config('pharmacy.dmd.trud.download_timeout', 900))
                ->sink($toFile)
                ->get($release->archiveFileUrl)
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException(DmdSwitch::redact("TRUD download of {$release->archiveFileName} answered HTTP {$e->response->status()}."));
        } catch (ConnectionException $e) {
            throw new RuntimeException(DmdSwitch::redact("TRUD download of {$release->archiveFileName} failed: ".$e->getMessage()));
        }

        // A faked response (tests) skips Guzzle's sink: write its body. A real one is already on disk.
        clearstatcache(true, $toFile);

        if (! is_file($toFile) || filesize($toFile) === 0) {
            file_put_contents($toFile, $response->body());
        }
    }
}
