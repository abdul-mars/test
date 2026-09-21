<?php
declare(strict_types=1);

namespace QRoute\Controllers;

use QRoute\Http\Response;
use QRoute\Models\Link;
use QRoute\Services\Plan;
use QRoute\Services\QrCode;
use QRoute\Services\QrRenderer;

/**
 * Serves the QR image for a link.
 *
 * The image is public: it has to be, because it is embedded in the
 * dashboard and downloaded for print. It encodes only the short URL, which
 * is public by definition, so nothing is leaked by serving it.
 */
final class QrController extends Controller
{
    public function render(string $slug, string $format): Response
    {
        $link = Link::findBySlug($slug);
        if ($link === null) {
            throw new HttpError('Code not found.', 404);
        }

        $style = $link->renderStyle();

        // Colour and shape overrides are accepted for the live preview in
        // the editor, but only for the owner and only on a paid plan.
        $owner = $this->user();
        $isOwner = $owner !== null && $owner->id() === $link->userId();
        if ($isOwner && Plan::can($owner, 'custom_colors')) {
            $style['dark']  = QrRenderer::colour((string) $this->request->input('dark', ''), $style['dark']);
            $style['light'] = QrRenderer::colour((string) $this->request->input('light', ''), $style['light']);
            $ecc = strtoupper((string) $this->request->input('ecc', ''));
            if (in_array($ecc, ['L', 'M', 'Q', 'H'], true)) {
                $style['ecc'] = $ecc;
            }
        }

        $qr = QrCode::encode(
            $link->shortUrl($this->request->baseUrl()),
            QrCode::eccFromName((string) $style['ecc'])
        );

        $download = $this->request->input('download', '') !== '';
        $filename = 'qroute-' . $link->slug() . '.' . $format;

        if ($format === 'png') {
            $scale = (int) ($this->request->input('scale', '10') ?? 10);
            $body = QrRenderer::png($qr, $style + ['scale' => $scale]);
            $type = 'image/png';
        } else {
            $body = QrRenderer::svg($qr, $style);
            $type = 'image/svg+xml; charset=UTF-8';
        }

        $response = Response::raw($body, $type)
            // The image only changes when the style does, and the style is
            // part of the URL for previews, so a short cache is safe and
            // keeps the dashboard snappy.
            ->withHeader('Cache-Control', $isOwner ? 'private, max-age=60' : 'public, max-age=3600')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // An SVG served inline can carry script; a strict CSP on the
            // image response itself neutralises that entirely.
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");

        if ($download) {
            $response->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        }

        return $response;
    }
}
