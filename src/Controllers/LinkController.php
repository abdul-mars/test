<?php
declare(strict_types=1);

namespace QRoute\Controllers;

use QRoute\Http\Response;
use QRoute\Models\Link;
use QRoute\Models\Rule;
use QRoute\Services\Analytics;
use QRoute\Services\Plan;
use QRoute\Services\QrCode;
use QRoute\Services\QrRenderer;
use QRoute\Services\RuleEngine;
use QRoute\Services\UrlValidator;

final class LinkController extends Controller
{
    public function index(): Response
    {
        $user = $this->requireUser();
        $links = Link::forUser($user->id());

        return $this->view('app/dashboard', [
            'title'     => 'Your codes — QRoute',
            'activeNav' => 'links',
            'links'     => $links,
            'summary'   => Analytics::userSummary($user->id(), 30),
            'usage'     => $this->usage($user, count($links)),
        ]);
    }

    public function createForm(): Response
    {
        $user = $this->requireUser();
        if (Link::countForUser($user->id()) >= Plan::maxLinks($user)) {
            $this->flash('You have reached the code limit on your plan.', 'warning');
            return $this->redirect('/pricing');
        }
        return $this->view('app/link_form', [
            'title'         => 'New code — QRoute',
            'activeNav'     => 'links',
            'canCustomSlug' => $user->isPaid(),
        ]);
    }

    public function create(): Response
    {
        $user = $this->requireUser();
        $this->requireCsrf('/app/links/new');

        if (Link::countForUser($user->id()) >= Plan::maxLinks($user)) {
            $this->flash('You have reached the code limit on your plan.', 'warning');
            return $this->redirect('/pricing');
        }

        $errors = [];
        $values = [
            'default_url' => (string) $this->request->input('default_url', ''),
            'title'       => (string) $this->request->input('title', ''),
            'slug'        => (string) $this->request->input('slug', ''),
        ];

        $url = UrlValidator::check($values['default_url'], Plan::can($user, 'custom_schemes'));
        if (!$url['ok']) {
            $errors[] = $url['error'];
        }

        $slug = '';
        if ($values['slug'] !== '') {
            if (!$user->isPaid()) {
                $errors[] = 'Custom short links are a Pro feature.';
            } else {
                $check = Link::validateCustomSlug($values['slug']);
                if (!$check['ok']) {
                    $errors[] = $check['error'];
                } else {
                    $slug = $check['slug'];
                }
            }
        }

        if ($errors !== []) {
            return $this->view('app/link_form', [
                'title'         => 'New code — QRoute',
                'activeNav'     => 'links',
                'errors'        => $errors,
                'values'        => $values,
                'canCustomSlug' => $user->isPaid(),
            ]);
        }

        if ($slug === '') {
            $slug = Link::generateSlug();
        }

        $link = Link::create($user->id(), $slug, $url['url'], ['title' => $values['title']]);
        $this->flash('Your code is ready. Download it once you are happy with the destination.', 'success');

        return $this->redirect('/app/links/' . $link->id());
    }

    public function edit(string $id): Response
    {
        $user = $this->requireUser();
        $link = Link::findForUser((int) $id, $user->id());
        if ($link === null) {
            throw new HttpError('Code not found.', 404);
        }

        $style = $link->renderStyle();
        $qr = QrCode::encode($link->shortUrl($this->request->baseUrl()), QrCode::eccFromName($style['ecc']));

        return $this->view('app/link_edit', [
            'title'     => $link->title() . ' — QRoute',
            'activeNav' => 'links',
            'link'      => $link,
            'rules'     => Rule::forLink($link->id()),
            'plan'      => Plan::for($user),
            'qrSvg'     => QrRenderer::svg($qr, $style),
        ]);
    }

    public function updateDestination(string $id): Response
    {
        $user = $this->requireUser();
        $link = $this->ownedLink($id, $user->id());
        $this->requireCsrf('/app/links/' . $link->id());

        $url = UrlValidator::check(
            (string) $this->request->input('default_url', ''),
            Plan::can($user, 'custom_schemes')
        );
        if (!$url['ok']) {
            return $this->editWithErrors($link, $user, [$url['error']]);
        }

        $link->update(['default_url' => $url['url']]);
        $this->flash('Destination updated. Every scan from now on goes there.', 'success');

        return $this->redirect('/app/links/' . $link->id());
    }

    public function updateSettings(string $id): Response
    {
        $user = $this->requireUser();
        $link = $this->ownedLink($id, $user->id());
        $this->requireCsrf('/app/links/' . $link->id());

        $link->update([
            'title'     => mb_substr((string) $this->request->input('title', ''), 0, 120),
            'is_active' => $this->request->boolean('is_active') ? 1 : 0,
        ]);
        $this->flash('Settings saved.', 'success');

        return $this->redirect('/app/links/' . $link->id());
    }

    public function updateStyle(string $id): Response
    {
        $user = $this->requireUser();
        $link = $this->ownedLink($id, $user->id());
        $this->requireCsrf('/app/links/' . $link->id());

        if (!Plan::can($user, 'custom_colors')) {
            $this->flash('Custom colours are a Pro feature.', 'warning');
            return $this->redirect('/pricing');
        }

        $dark  = QrRenderer::colour((string) $this->request->input('dark', ''), QrRenderer::DEFAULT_DARK);
        $light = QrRenderer::colour((string) $this->request->input('light', ''), QrRenderer::DEFAULT_LIGHT);
        $ecc   = strtoupper((string) $this->request->input('ecc', 'Q'));
        $ecc   = in_array($ecc, ['L', 'M', 'Q', 'H'], true) ? $ecc : 'Q';

        // A low-contrast code is an unscannable code, and the customer will
        // only find out after it is printed.
        if (QrRenderer::contrastRatio($dark, $light) < 4.0) {
            return $this->editWithErrors($link, $user, [
                'Those two colours are too similar for a scanner to read reliably. Pick a darker foreground or a lighter background.',
            ]);
        }

        $link->update(['style_json' => json_encode(compact('dark', 'light', 'ecc'))]);
        $this->flash('Appearance saved.', 'success');

        return $this->redirect('/app/links/' . $link->id());
    }

    public function destroy(string $id): Response
    {
        $user = $this->requireUser();
        $link = $this->ownedLink($id, $user->id());
        $this->requireCsrf('/app/links/' . $link->id());

        $link->delete();
        $this->flash('Code deleted.', 'success');

        return $this->redirect('/app');
    }

    // ------------------------------------------------------------- rules

    public function addRule(string $id): Response
    {
        $user = $this->requireUser();
        $link = $this->ownedLink($id, $user->id());
        $this->requireCsrf('/app/links/' . $link->id());

        if (Rule::countForLink($link->id()) >= Plan::maxRules($user)) {
            $this->flash('You have reached the rule limit for this code.', 'warning');
            return $this->redirect('/pricing');
        }

        $url = UrlValidator::check(
            (string) $this->request->input('target_url', ''),
            Plan::can($user, 'custom_schemes')
        );
        if (!$url['ok']) {
            return $this->editWithErrors($link, $user, [$url['error']]);
        }

        $raw = [
            'device'   => $this->request->inputArray('device'),
            'os'       => $this->request->inputArray('os'),
            'browser'  => $this->request->inputArray('browser'),
            'country'  => $this->request->inputArray('country'),
            'lang'     => $this->request->inputArray('lang'),
            'referer'  => $this->request->inputArray('referer'),
            'schedule' => is_array($this->request->post['schedule'] ?? null) ? $this->request->post['schedule'] : [],
            'window'   => is_array($this->request->post['window'] ?? null) ? $this->request->post['window'] : [],
            'scans'    => is_array($this->request->post['scans'] ?? null) ? $this->request->post['scans'] : [],
        ];

        $clean = RuleEngine::sanitize($raw);
        if (!$clean['ok']) {
            return $this->editWithErrors($link, $user, [$clean['error']]);
        }
        if ($clean['conditions'] === []) {
            return $this->editWithErrors($link, $user, [
                'A rule needs at least one condition, otherwise it would catch every scan and your default destination would never be used.',
            ]);
        }

        Rule::create($link->id(), $url['url'], $clean['conditions'], (string) $this->request->input('label', ''));
        $this->flash('Rule added.', 'success');

        return $this->redirect('/app/links/' . $link->id());
    }

    public function toggleRule(string $ruleId): Response
    {
        $user = $this->requireUser();
        [$rule, $link] = $this->ownedRule($ruleId, $user->id());
        $this->requireCsrf('/app/links/' . $link->id());

        $rule->update(['is_active' => $rule->isActive() ? 0 : 1]);
        return $this->redirect('/app/links/' . $link->id());
    }

    public function deleteRule(string $ruleId): Response
    {
        $user = $this->requireUser();
        [$rule, $link] = $this->ownedRule($ruleId, $user->id());
        $this->requireCsrf('/app/links/' . $link->id());

        $rule->delete();
        $this->flash('Rule deleted.', 'success');
        return $this->redirect('/app/links/' . $link->id());
    }

    // --------------------------------------------------------- helpers

    private function ownedLink(string $id, int $userId): Link
    {
        $link = Link::findForUser((int) $id, $userId);
        if ($link === null) {
            throw new HttpError('Code not found.', 404);
        }
        return $link;
    }

    /** @return array{0:Rule,1:Link} */
    private function ownedRule(string $ruleId, int $userId): array
    {
        $rule = Rule::find((int) $ruleId);
        if ($rule === null) {
            throw new HttpError('Rule not found.', 404);
        }
        $link = Link::findForUser($rule->linkId(), $userId);
        if ($link === null) {
            throw new HttpError('Rule not found.', 404);
        }
        return [$rule, $link];
    }

    /** @param list<string> $errors */
    private function editWithErrors(Link $link, \QRoute\Models\User $user, array $errors): Response
    {
        $style = $link->renderStyle();
        $qr = QrCode::encode($link->shortUrl($this->request->baseUrl()), QrCode::eccFromName($style['ecc']));

        return $this->view('app/link_edit', [
            'title'     => $link->title() . ' — QRoute',
            'activeNav' => 'links',
            'link'      => $link,
            'rules'     => Rule::forLink($link->id()),
            'plan'      => Plan::for($user),
            'qrSvg'     => QrRenderer::svg($qr, $style),
            'errors'    => $errors,
        ]);
    }

    /** @return array<string,mixed> */
    private function usage(\QRoute\Models\User $user, int $linkCount): array
    {
        return [
            'plan'       => $user->plan(),
            'links_used' => $linkCount,
            'links_max'  => Plan::maxLinks($user),
            'scans_used' => Analytics::monthlyScanCount($user->id()),
            'scans_max'  => Plan::scansPerMonth($user),
        ];
    }
}
