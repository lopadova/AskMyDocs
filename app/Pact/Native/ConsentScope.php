<?php

declare(strict_types=1);

namespace App\Pact\Native;

use App\Http\Middleware\AuthorizeTenantHeader;
use App\Models\User;
use App\Services\Auth\UserTeamsResolver;
use Closure;
use Illuminate\Http\Request;
use PactLaravel\Models\OAuthClient;
use Symfony\Component\HttpFoundation\Response;

/** Binds the native OAuth consent to a project selected by the local user. */
final readonly class ConsentScope
{
    private const OAUTH_SESSION = ['pact.oauth.identity', 'authToken', 'authRequest'];

    public function __construct(private UserTeamsResolver $teams, private SubjectResolver $subjects) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->user($request);
        if ($request->routeIs('pact.oauth.approve', 'pact.oauth.deny')) {
            return $this->decide($request, $user, $next);
        }

        $parameters = array_filter(
            $request->only(['client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'code_challenge', 'code_challenge_method']),
            static fn ($value) => $value !== null,
        );
        if ($parameters === []) {
            return $this->restart(400);
        }
        abort_unless(($parameters['response_type'] ?? null) === 'code'
            && ($parameters['code_challenge_method'] ?? null) === 'S256'
            && is_string($parameters['code_challenge'] ?? null)
            && preg_match('/^[A-Za-z0-9_-]{43}$/D', $parameters['code_challenge']) === 1, 400);
        $client = $this->client($parameters);
        if ($request->query->has('pact_consent')) {
            $consent = PendingConsent::fromRequest($request, $user);
            if ($consent === null || $consent->parameters !== $parameters || $consent->subject_id === null) {
                return $this->restart();
            }

            return $this->showConsent($request, $consent, $client, $next);
        }
        $consent = PendingConsent::start($request, $user, $parameters);

        return $this->privateResponse(response()->view('pact-client.project', [
            'client' => $client, 'teams' => $this->choices($user), 'consentId' => $consent->id,
        ]));
    }

    public function select(Request $request): Response
    {
        $user = $this->user($request);
        $consent = PendingConsent::fromRequest($request, $user);
        if ($consent === null || $consent->subject_id !== null) {
            return $this->restart();
        }
        $choice = $request->input('project');
        foreach ($this->choices($user) as $team) {
            foreach ($team['projects'] as $project) {
                if ($choice !== $project['selection']) {
                    continue;
                }
                $subject = Subject::query()->firstOrCreate(['user_id' => $user->id, 'tenant_id' => $team['tenant_id'], 'project_key' => $project['project_key']]);
                abort_unless($subject->authorizedUser() !== null, 403);
                $selected = $consent->pending()->whereNull('subject_id')->update([
                    'subject_id' => $subject->id,
                    'active_tenant' => $request->session()->get(AuthorizeTenantHeader::ACTIVE_TENANT_SESSION_KEY),
                ]);
                if ($selected !== 1) {
                    return $this->restart();
                }

                return $this->privateResponse(redirect()->route('pact.oauth.authorize', [
                    ...$consent->parameters, 'pact_consent' => $consent->id,
                ], 303));
            }
        }

        $consent->pending()->whereNull('subject_id')->delete();

        return $this->privateResponse(redirect()->route('pact.oauth.authorize', $consent->parameters, 303)
            ->withErrors(['project' => 'Scegli un progetto a cui hai accesso.']));
    }

    private function showConsent(Request $request, PendingConsent $consent, OAuthClient $client, Closure $next): Response
    {
        $subject = $this->subject($request, $consent);
        if ($subject === null) {
            return $this->reselect($consent);
        }
        $request->attributes->set(Subject::class, $subject);
        $request->attributes->set(PendingConsent::class, $consent);
        $session = $request->session();
        $passport = $consent->passport;
        if ($passport === null) {
            // Passport takes its request from the session during a decision. Keep
            // those fields request-local and persist the SDK snapshot per consent.
            $session->forget(self::OAUTH_SESSION);
            try {
                $response = $next($request);
                $passport = array_combine(self::OAUTH_SESSION, array_map($session->get(...), self::OAUTH_SESSION));
            } finally {
                $session->forget(self::OAUTH_SESSION);
            }
            if (! $response->isSuccessful() || in_array(null, $passport, true)) {
                return $this->privateResponse($response);
            }
            $consent->forceFill(['passport' => $passport]);
            if ($consent->pending()->whereNull('passport')->update($consent->getDirty()) === 1) {
                return $this->privateResponse($response);
            }
            // Two GETs can prepare the same request. Both forms use the first snapshot.
            $passport = $consent->pending()->first()?->passport;
        }

        return $passport === null ? $this->restart() : $this->privateResponse(response()->view('pact::consent', [
            'client' => $client, 'authToken' => $passport['authToken'],
        ]));
    }

    private function decide(Request $request, User $user, Closure $next): Response
    {
        $consent = PendingConsent::fromRequest($request, $user);
        $passport = $consent?->passport;
        $token = $request->input('auth_token');
        if ($passport === null || ! is_string($token) || ! hash_equals($passport['authToken'], $token)) {
            return $this->restart();
        }
        if ($request->routeIs('pact.oauth.approve')) {
            $subject = $this->subject($request, $consent);
            if ($subject === null || $passport['pact.oauth.identity'] !== (string) $subject->id) {
                return $this->reselect($consent);
            }
            $request->attributes->set(Subject::class, $subject);
        }
        if ($consent->pending()->delete() !== 1) {
            return $this->restart();
        }
        $session = $request->session();
        $session->put($passport);
        try {
            return $this->returnToMaster($next($request), $consent->parameters['redirect_uri']);
        } finally {
            $session->forget(self::OAUTH_SESSION);
        }
    }

    private function reselect(PendingConsent $consent): Response
    {
        $consent->pending()->delete();

        return $this->privateResponse(redirect()->route('pact.oauth.authorize', $consent->parameters, 303)
            ->with('pact.client.reselect', true));
    }

    private function restart(int $status = 409): Response
    {
        return $this->privateResponse(response()->view('pact-client.restart', [], $status));
    }

    private function user(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->is_active, 403);

        return $user;
    }

    private function client(array $parameters): OAuthClient
    {
        $id = $parameters['client_id'] ?? null;
        $client = is_string($id) && strlen($id) <= 100 ? OAuthClient::query()->where('oauth_client_id', $id)->first() : null;
        abort_unless($client !== null && ($parameters['redirect_uri'] ?? null) === $client->callback_url, 403);

        return $client;
    }

    private function subject(Request $request, PendingConsent $consent): ?Subject
    {
        $subject = $this->subjects->find((string) $consent->subject_id);
        $activeTenant = $request->session()->get(AuthorizeTenantHeader::ACTIVE_TENANT_SESSION_KEY);

        return $subject instanceof Subject && (int) $subject->user_id === (int) $consent->user_id
            && in_array($activeTenant, [null, $consent->active_tenant, $subject->tenant_id], true) ? $subject : null;
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
