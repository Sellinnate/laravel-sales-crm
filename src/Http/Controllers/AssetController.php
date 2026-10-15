<?php

namespace Sellinnate\SalesCrm\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Serves the precompiled CSS of the UI, so the host application needs no build step. Long cache:
 * the URL carries the hash of the file ({@see self::url()}).
 */
class AssetController extends Controller
{
    public const PATH = __DIR__.'/../../../resources/dist/sales-crm.css';

    public function __invoke(): Response
    {
        abort_unless(is_file(self::PATH), 404);

        return new Response((string) file_get_contents(self::PATH), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public static function url(): string
    {
        $version = is_file(self::PATH) ? substr((string) md5_file(self::PATH), 0, 10) : '0';

        return route('sales-crm.assets.css', ['v' => $version]);
    }
}
