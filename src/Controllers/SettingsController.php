<?php
declare(strict_types=1);

namespace QRoute\Controllers;

use QRoute\Core\Database;
use QRoute\Http\Response;
use QRoute\Models\ApiKey;
use QRoute\Models\User;
use QRoute\Services\Plan;

final class SettingsController extends Controller
{
    public function show(?string $newKey = null, array $errors = []): Response
    {
        $user = $this->requireUser();
        return $this->view('app/settings', [
            'title'     => 'Settings — QRoute',
            'activeNav' => 'settings',
            'user'      => $user,
            'apiKeys'   => ApiKey::forUser($user->id()),
            'newKey'    => $newKey,
            'errors'    => $errors,
        ]);
    }

    public function updateProfile(): Response
    {
        $user = $this->requireUser();
        $this->requireCsrf('/app/settings');

        $user->setDisplayName((string) $this->request->input('display_name', ''));
        $this->flash('Profile saved.', 'success');

        return $this->redirect('/app/settings');
    }

    public function updatePassword(): Response
    {
        $user = $this->requireUser();
        $this->requireCsrf('/app/settings');

        $current = (string) $this->request->input('current_password', '');
        $new = (string) $this->request->input('new_password', '');

        if (User::attemptLogin($user->email(), $current) === null) {
            return $this->show(null, ['Your current password is not correct.']);
        }

        $problem = $user->changePassword($new);
        if ($problem !== null) {
            return $this->show(null, [$problem]);
        }

        // changePassword() dropped every session, including this one.
        $this->session->login($user->id());
        $this->flash('Password changed. Other devices have been signed out.', 'success');

        return $this->session->applyTo($this->redirect('/app/settings'));
    }

    public function createKey(): Response
    {
        $user = $this->requireUser();
        $this->requireCsrf('/app/settings');

        if (!Plan::can($user, 'api_access')) {
            $this->flash('API access is a Pro feature.', 'warning');
            return $this->redirect('/pricing');
        }
        if (count(ApiKey::forUser($user->id())) >= 10) {
            return $this->show(null, ['You already have the maximum of 10 active keys.']);
        }

        $created = ApiKey::create($user->id(), (string) $this->request->input('name', ''));

        return $this->show($created['plaintext']);
    }

    public function revokeKey(string $id): Response
    {
        $user = $this->requireUser();
        $this->requireCsrf('/app/settings');

        ApiKey::revoke((int) $id, $user->id());
        $this->flash('Key revoked.', 'success');

        return $this->redirect('/app/settings');
    }

    /**
     * Plan switching.
     *
     * Payment processing is intentionally left to a provider: this endpoint
     * is where a Stripe Checkout session would be created, and the webhook
     * that confirms payment is what should call User::setPlan(). Until a
     * provider is configured it is available only when explicitly enabled,
     * so a live deployment cannot hand out paid plans by accident.
     */
    public function billing(): Response
    {
        $user = $this->requireUser();

        if (!\QRoute\Core\Config::bool('ALLOW_SELF_SERVE_PLAN_CHANGE', false)) {
            return $this->view('app/billing', [
                'title'     => 'Billing — QRoute',
                'activeNav' => 'settings',
                'user'      => $user,
            ]);
        }

        // Development convenience only, gated by configuration.
        $plan = (string) $this->request->input('plan', '');
        if (isset(Plan::PLANS[$plan])) {
            $user->setPlan($plan, $plan === User::PLAN_FREE ? null : time() + 31 * 86400);
            $this->flash('Plan changed to ' . Plan::displayName($plan) . '.', 'success');
        }
        return $this->redirect('/app/settings');
    }
}
