<?php

namespace App\Http\Controllers;

use App\Support\AdminPrivileges;
use Illuminate\Http\JsonResponse;

class AdminPrivilegeController extends Controller
{
    /**
     * Serve the privilege catalogue and its presets.
     *
     * The admin account form needs both to render, and serving them beats
     * hard-coding a second copy in the SPA: a privilege added to
     * AdminPrivileges then appears in the form without a client release, and
     * the label the form shows is the label the gate enforces.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'privileges' => AdminPrivileges::catalogue(),
            'presets' => AdminPrivileges::presets(),
        ]);
    }
}
