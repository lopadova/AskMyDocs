<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

/**
 * Single-action controller that renders the React SPA shell. Every
 * path under /app/* resolves here so React owns its own routing. The
 * legacy Blade chat UI served from /chat stays untouched.
 */
class SpaController extends Controller
{
    public function __invoke(): View
    {
        return view('app');
    }
}
