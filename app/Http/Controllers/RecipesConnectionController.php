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
 * The result is shown where the connection was started (`from`: the settings or the week planner).
 */
class RecipesConnectionController extends Controller
{
    private const STATE = 'recipes_oauth_state';

    private const FROM = 'recipes_oauth_from';

    /** Views of the app the connection can be started from (`?from=`), the first is the default. */
    private const VIEWS = ['settings', 'planner'];

    public function connect(Request $request, RecipesClient $recipes): RedirectResponse
    {
        abort_unless(RecipesClient::enabled(), 404);

        $state = Str::random(40);
        $request->session()->put(self::STATE, $state);
        $from = (string) $request->query('from');
        $request->session()->put(self::FROM, in_array($from, self::VIEWS, true) ? $from : self::VIEWS[0]);

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
        $view = session()->pull(self::FROM);

        return redirect('/?'.http_build_query(['recipes' => $result]).'#/'.(in_array($view, self::VIEWS, true) ? $view : self::VIEWS[0]));
    }
}
