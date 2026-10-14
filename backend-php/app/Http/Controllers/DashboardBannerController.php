<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Models\DashboardBanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DashboardBannerController extends Controller
{
    use ResolvesApiUser;

    public function dismiss(Request $request): Response
    {
        $user = $this->apiUser($request);
        $revision = $request->json('revision');
        if (is_string($revision) && preg_match('/^\d+$/', $revision)) {
            $revision = (int) $revision;
        }
        if (! is_int($revision) || $revision < 1) {
            throw new ApiException('Validation error', 422);
        }
        DB::transaction(function () use ($user, $revision) {
            $banner = DashboardBanner::query()->where('id', 1)->where('is_active', true)->lockForUpdate()->first();
            if (! $banner || (int) $banner->revision !== $revision) {
                throw new ApiException('Dashboard banner revision is stale or invalid', 409);
            }
            if ((int) $user->dismissed_dashboard_banner_revision !== $revision) {
                $user->dismissed_dashboard_banner_revision = $revision;
                $user->save();
            }
        });

        return response()->noContent();
    }
}
