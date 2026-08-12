<?php

namespace App\Http\Middleware;

use App\Modules\SystemSuperadmin\Models\BusinessProfileDraft;
use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;
use App\Support\SystemRoles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BusinessProfilePreviewContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $draftId = $request->session()->get('business_profile_preview_draft_id');

        if (! $draftId || ! $request->user()?->hasRole(SystemRoles::SYSTEM_SUPERADMIN)) {
            return $next($request);
        }

        $draft = BusinessProfileDraft::query()->find($draftId);

        if (! $draft) {
            $request->session()->forget('business_profile_preview_draft_id');

            return $next($request);
        }

        app()->instance('business_profile_preview_draft', $draft);
        app()->instance('business_profile_preview_payload', ActiveBusinessProfile::draftPayload($draft));

        return $next($request);
    }
}
