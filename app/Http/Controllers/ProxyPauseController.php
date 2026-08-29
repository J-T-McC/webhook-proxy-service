<?php

namespace App\Http\Controllers;

use App\Actions\ResumeProxyDispatch;
use App\Models\Proxy;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Pause/resume dispatch for a proxy (item #15). Gated by the existing proxy
 * `update` permission (AC6) — same treatment PRD-06 gives retry-policy
 * configuration and PRD-10 gives verification configuration, and the same
 * authorization call `ProxySigningController` uses.
 */
class ProxyPauseController extends Controller
{
    public function __construct(private ResumeProxyDispatch $resume) {}

    /**
     * Pause: sets `paused_at` (AC1, AC14). Confirmation (AC10) is enforced
     * client-side only — this endpoint trusts the request the same way every
     * other confirmed destructive action in this app does.
     */
    public function store(string $current_team, Proxy $proxy): RedirectResponse
    {
        $this->authorize('update', $proxy);

        $proxy->forceFill(['paused_at' => now()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Proxy paused.')]);

        return back();
    }

    /**
     * Resume: clears `paused_at` and releases the proxy's waiting work
     * immediately (AC4) — no confirmation required (AC10).
     */
    public function destroy(string $current_team, Proxy $proxy): RedirectResponse
    {
        $this->authorize('update', $proxy);

        $proxy->forceFill(['paused_at' => null])->save();

        $this->resume->handle($proxy);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Proxy resumed.')]);

        return back();
    }
}
