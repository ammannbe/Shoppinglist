<?php

namespace App\Http\Controllers;

use App\Recipes\RecipesClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * "Connect with <recipe app>": OAuth 2 authorization code flow against the recipe app.
 * The result is shown in the settings of the app (/?recipes=…#/settings).
 */
class RecipesConnectionController extends Controller
{
    private const STATE = 'recipes_oauth_state';

    public function connect(Request $request, RecipesClient $recipes): RedirectResponse
    {
        abort_unless(RecipesClient::enabled(), 404);

        $state = Str::random(40);
        $request->session()->put(self::STATE, $state);

        return redirect()->away($recipes->authorizeUrl($state));
    }

    public function callback(Request $request, RecipesClient $recipes): RedirectResponse
    {
        abort_unless(RecipesClient::enabled(), 404);

        $state = $request->session()->pull(self::STATE);
        if (! $state || ! hash_equals($state, (string) $request->query('state')) || ! $request->filled('code')) {
            return $this->back($request->query('error') === 'access_denied' ? 'denied' : 'failed');
        }

        try {
            $recipes->connect($request->user(), (string) $request->query('code'));
        } catch (RequestException $e) {
            report($e);

            return $this->back('failed');
        }

        return $this->back('connected');
    }

    public function destroy(Request $request, RecipesClient $recipes): Response
    {
        $recipes->disconnect($request->user());

        return response()->noContent();
    }

    private function back(string $result): RedirectResponse
    {
        return redirect('/?'.http_build_query(['recipes' => $result]).'#/settings');
    }
}
