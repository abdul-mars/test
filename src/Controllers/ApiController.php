<?php
declare(strict_types=1);

namespace QRoute\Controllers;

use QRoute\Core\RateLimiter;
use QRoute\Http\Request;
use QRoute\Http\Response;
use QRoute\Models\ApiKey;
use QRoute\Models\Link;
use QRoute\Models\Rule;
use QRoute\Models\User;
use QRoute\Services\Analytics;
use QRoute\Services\Plan;
use QRoute\Services\RuleEngine;
use QRoute\Services\UrlValidator;

/**
 * JSON API, authenticated by bearer key.
 *
 * Separate from the session-based web controllers on purpose: there are no
 * cookies here and therefore no CSRF surface, and every response is JSON
 * including errors.
 */
final class ApiController
{
    private ?User $user = null;

    public function __construct(private Request $request)
    {
    }

    public function handle(string $action, array $args = []): Response
    {
        try {
            $this->authenticate();
            return match ($action) {
                'me'           => $this->me(),
                'listLinks'    => $this->listLinks(),
                'createLink'   => $this->createLink(),
                'showLink'     => $this->showLink((int) $args['id']),
                'updateLink'   => $this->updateLink((int) $args['id']),
                'deleteLink'   => $this->deleteLink((int) $args['id']),
                'createRule'   => $this->createRule((int) $args['id']),
                'deleteRule'   => $this->deleteRule((int) $args['id']),
                'stats'        => $this->stats((int) $args['id']),
                default        => $this->error('Unknown endpoint.', 404),
            };
        } catch (HttpError $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            if (\QRoute\Core\Config::bool('APP_DEBUG', false)) {
                return $this->error($e->getMessage(), 500);
            }
            error_log('[qroute] api error: ' . $e->getMessage());
            return $this->error('Internal error.', 500);
        }
    }

    // ----------------------------------------------------------- auth

    private function authenticate(): void
    {
        $header = $this->request->header('authorization');
        $token = '';
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
            $token = $m[1];
        } elseif ($this->request->header('x-api-key') !== '') {
            $token = $this->request->header('x-api-key');
        }

        if ($token === '') {
            throw new HttpError('Missing API key. Send it as: Authorization: Bearer qr_live_...', 401);
        }

        // Limit by key prefix before the hash lookup so a flood of invalid
        // keys cannot be used to hammer the database.
        $bucket = 'api:' . substr(hash('sha256', $token), 0, 24);
        $limit = RateLimiter::hit($bucket, 600, 60);
        if (!$limit['allowed']) {
            throw new HttpError('Rate limit exceeded.', 429);
        }

        $user = ApiKey::authenticate($token);
        if ($user === null) {
            throw new HttpError('Invalid or revoked API key.', 401);
        }
        if (!Plan::can($user, 'api_access')) {
            throw new HttpError('API access requires a Pro or Team plan.', 403);
        }
        $this->user = $user;
    }

    private function user(): User
    {
        if ($this->user === null) {
            throw new HttpError('Not authenticated.', 401);
        }
        return $this->user;
    }

    // ------------------------------------------------------ endpoints

    private function me(): Response
    {
        $user = $this->user();
        return $this->ok([
            'email' => $user->email(),
            'plan'  => $user->plan(),
            'usage' => [
                'links'           => Link::countForUser($user->id()),
                'links_limit'     => Plan::maxLinks($user),
                'scans_this_month' => Analytics::monthlyScanCount($user->id()),
                'scans_limit'     => Plan::scansPerMonth($user),
            ],
        ]);
    }

    private function listLinks(): Response
    {
        $user = $this->user();
        $base = $this->request->baseUrl();
        $links = array_map(
            static fn(Link $l) => $l->toArray($base),
            Link::forUser($user->id())
        );
        return $this->ok(['links' => $links, 'count' => count($links)]);
    }

    private function createLink(): Response
    {
        $user = $this->user();
        $body = $this->body();

        if (Link::countForUser($user->id()) >= Plan::maxLinks($user)) {
            throw new HttpError('Code limit reached for your plan.', 402);
        }

        $url = UrlValidator::check(
            (string) ($body['default_url'] ?? ''),
            Plan::can($user, 'custom_schemes')
        );
        if (!$url['ok']) {
            throw new HttpError($url['error'], 422);
        }

        $slug = trim((string) ($body['slug'] ?? ''));
        if ($slug !== '') {
            $check = Link::validateCustomSlug($slug);
            if (!$check['ok']) {
                throw new HttpError($check['error'], 422);
            }
            $slug = $check['slug'];
        } else {
            $slug = Link::generateSlug();
        }

        $link = Link::create($user->id(), $slug, $url['url'], [
            'title' => (string) ($body['title'] ?? ''),
        ]);

        return $this->ok(['link' => $link->toArray($this->request->baseUrl())], 201);
    }

    private function showLink(int $id): Response
    {
        $link = $this->ownedLink($id);
        return $this->ok([
            'link'  => $link->toArray($this->request->baseUrl()),
            'rules' => array_map(static fn(Rule $r) => $r->toArray(), Rule::forLink($link->id())),
        ]);
    }

    private function updateLink(int $id): Response
    {
        $user = $this->user();
        $link = $this->ownedLink($id);
        $body = $this->body();
        $changes = [];

        if (array_key_exists('default_url', $body)) {
            $url = UrlValidator::check((string) $body['default_url'], Plan::can($user, 'custom_schemes'));
            if (!$url['ok']) {
                throw new HttpError($url['error'], 422);
            }
            $changes['default_url'] = $url['url'];
        }
        if (array_key_exists('title', $body)) {
            $changes['title'] = mb_substr((string) $body['title'], 0, 120);
        }
        if (array_key_exists('is_active', $body)) {
            $changes['is_active'] = $body['is_active'] ? 1 : 0;
        }

        if ($changes === []) {
            throw new HttpError('Nothing to update. Send default_url, title or is_active.', 422);
        }

        $link->update($changes);
        return $this->ok(['link' => $link->toArray($this->request->baseUrl())]);
    }

    private function deleteLink(int $id): Response
    {
        $link = $this->ownedLink($id);
        $link->delete();
        return $this->ok(['deleted' => true]);
    }

    private function createRule(int $linkId): Response
    {
        $user = $this->user();
        $link = $this->ownedLink($linkId);
        $body = $this->body();

        if (Rule::countForLink($link->id()) >= Plan::maxRules($user)) {
            throw new HttpError('Rule limit reached for your plan.', 402);
        }

        $url = UrlValidator::check((string) ($body['target_url'] ?? ''), Plan::can($user, 'custom_schemes'));
        if (!$url['ok']) {
            throw new HttpError($url['error'], 422);
        }

        $conditions = $body['conditions'] ?? [];
        if (!is_array($conditions)) {
            throw new HttpError('conditions must be an object.', 422);
        }

        $clean = RuleEngine::sanitize($conditions);
        if (!$clean['ok']) {
            throw new HttpError($clean['error'], 422);
        }
        if ($clean['conditions'] === []) {
            throw new HttpError(
                'No usable conditions. Supported keys: ' . implode(', ', RuleEngine::CONDITION_TYPES) . '.',
                422
            );
        }

        $rule = Rule::create($link->id(), $url['url'], $clean['conditions'], (string) ($body['label'] ?? ''));
        return $this->ok(['rule' => $rule->toArray()], 201);
    }

    private function deleteRule(int $ruleId): Response
    {
        $user = $this->user();
        $rule = Rule::find($ruleId);
        if ($rule === null || Link::findForUser($rule->linkId(), $user->id()) === null) {
            throw new HttpError('Rule not found.', 404);
        }
        $rule->delete();
        return $this->ok(['deleted' => true]);
    }

    private function stats(int $linkId): Response
    {
        $user = $this->user();
        $link = $this->ownedLink($linkId);
        $days = max(1, min((int) ($this->request->input('days', '30') ?? 30), Plan::historyDays($user)));

        return $this->ok([
            'days'    => $days,
            'daily'   => Analytics::daily($link->id(), $days),
            'device'  => Analytics::breakdown($link->id(), 'device', $days),
            'os'      => Analytics::breakdown($link->id(), 'os', $days),
            'country' => Analytics::breakdown($link->id(), 'country', $days),
            'rule'    => Analytics::breakdown($link->id(), 'rule', $days),
        ]);
    }

    // -------------------------------------------------------- helpers

    private function ownedLink(int $id): Link
    {
        $link = Link::findForUser($id, $this->user()->id());
        if ($link === null) {
            throw new HttpError('Code not found.', 404);
        }
        return $link;
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        $json = $this->request->json();
        if ($json !== []) {
            return $json;
        }
        return $this->request->post;
    }

    private function ok(array $data, int $status = 200): Response
    {
        return Response::json(['ok' => true] + $data, $status)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function error(string $message, int $status): Response
    {
        return Response::json(['ok' => false, 'error' => $message], $status)
            ->withHeader('Cache-Control', 'no-store');
    }
}
