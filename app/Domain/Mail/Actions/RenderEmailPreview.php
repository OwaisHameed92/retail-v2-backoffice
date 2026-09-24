<?php

namespace App\Domain\Mail\Actions;

use App\Domain\Mail\Mailables\BrandedMailable;

/**
 * Renders a branded email with its sample data for the admin preview. Sends nothing, logs nothing.
 */
final class RenderEmailPreview
{
    /**
     * @return array{html: string, text: string}
     */
    public function handle(BrandedMailable $mailable): array
    {
        $html = (string) $mailable->render();

        // Links in the preview open in a new tab instead of inside the iframe.
        $html = preg_replace('/<head>/i', '<head><base target="_blank">', $html, 1) ?? $html;

        return ['html' => $html, 'text' => $mailable->renderText()];
    }
}
