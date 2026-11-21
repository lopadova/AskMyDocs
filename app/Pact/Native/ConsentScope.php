<?php

declare(strict_types=1);

namespace App\Pact\Native;

use App\Http\Middleware\AuthorizeTenantHeader;
use App\Models\User;
use App\Services\Auth\UserTeamsResolver;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use PactLaravel\Models\OAuthClient;
use Symfony\Component\HttpFoundation\Response;

/** Binds the native OAuth consent to a project selected by the local user. */
final readonly class ConsentScope
{
    private const DRAFT = 'pact.client.project_draft';
    private const SELECTION = 'pact.client.selected_subject';

    public function __construct(private UserTeamsResolver $teams, private SubjectResolver $subjects) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->user($request);
        $session = $request->session();
        $callback = $session->get(self::SELECTION.'.callback');
        if ($request->routeIs('pact.oauth.deny')) {
            $session->forget([self::DRAFT, self::SELECTION]);

            return $this->returnToMaster($next($request), $callback);
        }
        if ($request->routeIs('pact.oauth.approve')) {
            $request->attributes->set(Subject::class, $this->subject($request, $user));
            $session->forget([self::DRAFT, self::SELECTION]);

            return $this->returnToMaster($next($request), $callback);
        }

        $parameters = array_filter(
            $request->only(['client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'code_challenge', 'code_challenge_method']),
            static fn ($value) => $value !== null,
        );
        $client = $this->client($parameters);
        $draft = $session->get(self::DRAFT, []);
        $subject = $this->subject($request, $user);
        if ($subject !== null && $this->matchesDraft($draft, $user) && $draft['parameters'] === $parameters) {
            $request->attributes->set(Subject::class, $subject);
            $session->forget(self::DRAFT);

            return $this->privateResponse($next($request));
        }
        $session->forget(self::SELECTION);
        $session->put(self::DRAFT, ['user' => $user->id, 'parameters' => $parameters, 'until' => now()->addMinutes(10)->timestamp]);

        return $this->privateResponse(response()->view('pact-client.project', ['client' => $client, 'teams' => $this->choices($user)]));
    }

    public function select(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $draft = $request->session()->get(self::DRAFT, []);
        abort_unless($this->matchesDraft($draft, $user), 403);
        $choice = $request->input('project');
        $redirect = redirect()->route('pact.oauth.authorize', $draft['parameters']);
        foreach ($this->choices($user) as $team) {
            foreach ($team['projects'] as $project) {
                if ($choice !== $project['selection']) {
                    continue;
                }
                $subject = Subject::query()->firstOrCreate(['user_id' => $user->id, 'tenant_id' => $team['tenant_id'], 'project_key' => $project['project_key']]);
                abort_unless($subject->authorizedUser() !== null, 403);
                $request->session()->put([
                    self::SELECTION => [
                        'id' => (string) $subject->id, 'until' => $draft['until'], 'callback' => $draft['parameters']['redirect_uri'],
                    ],
                    AuthorizeTenantHeader::ACTIVE_TENANT_SESSION_KEY => $subject->tenant_id,
                ]);

                return $redirect;
            }
        }

        return $redirect->withErrors(['project' => 'Scegli un progetto a cui hai accesso.']);
    }

    private function user(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->is_active && $user->email_verified_at !== null, 403);

        return $user;
    }

    private function client(array $parameters): OAuthClient
    {
        $id = $parameters['client_id'] ?? null;
        $client = is_string($id) && strlen($id) <= 100 ? OAuthClient::query()->where('oauth_client_id', $id)->first() : null;
        abort_unless($client !== null && ($parameters['redirect_uri'] ?? null) === $client->callback_url, 403);

        return $client;
    }

    private function subject(Request $request, User $user): ?Subject
    {
        $selection = $request->session()->get(self::SELECTION, []);
        if (($selection['until'] ?? 0) < now()->timestamp || ! is_string($selection['id'] ?? null)) {
            return null;
        }
        $subject = $this->subjects->find($selection['id']);

        return $subject instanceof Subject && (int) $subject->user_id === (int) $user->id
            && $subject->tenant_id === $request->session()->get(AuthorizeTenantHeader::ACTIVE_TENANT_SESSION_KEY) ? $subject : null;
    }

    private function matchesDraft(array $draft, User $user): bool
    {
        return ($draft['user'] ?? null) === $user->id && ($draft['until'] ?? 0) >= now()->timestamp;
    }

    private function choices(User $user): array
    {
        $teams = $this->teams->resolve($user);
        foreach ($teams as &$team) {
            $team['projects'] = array_values(array_filter($team['projects'], static fn ($project) =>
                in_array($project['role'], Subject::PROJECT_ROLES, true) && ! in_array($project['project_key'], ['', '*'], true)));
            foreach ($team['projects'] as &$project) {
                $project['selection'] = hash('sha256', $team['tenant_id']."\0".$project['project_key']);
            }
            unset($project);
        }
        unset($team);

        return array_filter($teams, fn ($team) => $team['projects'] !== []);
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function returnToMaster(Response $response, mixed $callback): Response
    {
        $location = $response->headers->get('Location');
        $return = $response->isRedirection() && is_string($callback) && is_string($location)
            && (str_starts_with($location, $callback.'?') || $location === $callback);

        if ($return) {
            // Chrome may apply form-action 'self' to the OAuth POST redirect.
            $response = response()->view('pact-client.return', ['callback' => $location]);
        }

        return $this->privateResponse($response);
    }
}
