<?php

namespace App\Http\Middleware;

use App\Ai\OpenAiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Week planner requests need the feature shoppinglist.ai (App\Entitlements\Entitlements); otherwise
 * 403 with a message the app shows at the button.
 */
class EnsureAiFeature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (OpenAiClient::enabled() && ! OpenAiClient::enabledFor($request->user())) {
            return response()->json(['message' => __('The AI features are not enabled for your account.'), 'feature' => OpenAiClient::FEATURE], 403);
        }

        return $next($request);
    }
}
