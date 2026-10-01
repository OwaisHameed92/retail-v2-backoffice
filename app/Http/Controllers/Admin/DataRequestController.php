<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Privacy\Queries\AdminDataRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer data requests (export, erasure) across every business (module 7.7), read only, for owner and support
 * (`audit.view`). No customer details are shown.
 */
class DataRequestController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/data-requests/index', AdminDataRequests::for($request));
    }
}
