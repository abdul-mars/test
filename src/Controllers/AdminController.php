<?php
declare(strict_types=1);

namespace QRoute\Controllers;

use QRoute\Core\Database;
use QRoute\Core\Security;
use QRoute\Http\Response;
use QRoute\Models\Link;
use QRoute\Models\User;
use QRoute\Services\Plan;

/**
 * Operator tools.
 *
 * The job this exists to do: with payments not yet connected, somebody has
 * to move a customer onto a paid plan after they pay, and somebody has to
 * be able to switch off a code that is pointing at a phishing page. Both
 * were command-line-only before.
 */
final class AdminController extends Controller
{
    /**
     * Every action goes through here first.
     *
     * A non-admin gets a 404 rather than a 403, so the existence of the
     * admin area is not confirmed to someone probing for it.
     */
    private function requireAdmin(): User
    {
        $user = $this->requireUser();
        if (!$user->isAdmin()) {
            throw new HttpError('Page not found', 404);
        }
        return $user;
    }

    public function dashboard(): Response
    {
        $admin = $this->requireAdmin();
        $db = Database::instance();

        $now = time();
        $monthStart = (int) strtotime(gmdate('Y-m-01 00:00:00', $now) . ' UTC');

        $planCounts = [];
        foreach ($db->all('SELECT plan, COUNT(*) AS n FROM users GROUP BY plan') as $row) {
            $planCounts[(string) $row['plan']] = (int) $row['n'];
        }

        return $this->view('admin/dashboard', [
            'title'     => 'Admin — QRoute',
            'activeNav' => 'admin',
            'stats'     => [
                'users'        => User::countAll(),
                'admins'       => User::countAdmins(),
                'links'        => (int) $db->scalar('SELECT COUNT(*) FROM links WHERE archived_at IS NULL'),
                'rules'        => (int) $db->scalar('SELECT COUNT(*) FROM rules'),
                'scans_total'  => (int) $db->scalar('SELECT COUNT(*) FROM scans'),
                'scans_month'  => (int) $db->scalar(
                    'SELECT COUNT(*) FROM scans WHERE scanned_at >= :t AND is_bot = 0',
                    ['t' => $monthStart]
                ),
                'new_users_30d' => (int) $db->scalar(
                    'SELECT COUNT(*) FROM users WHERE created_at >= :t',
                    ['t' => $now - 30 * 86400]
                ),
            ],
            'planCounts' => $planCounts,
            'recent'     => User::all(10),
        ]);
    }

    public function users(): Response
    {
        $this->requireAdmin();

        $search = (string) $this->request->input('q', '');
        $page = max(1, (int) ($this->request->input('page', '1') ?? 1));
        $perPage = 25;

        return $this->view('admin/users', [
            'title'     => 'Users — Admin',
            'activeNav' => 'admin',
            'users'     => User::all($perPage, ($page - 1) * $perPage, $search),
            'search'    => $search,
            'page'      => $page,
            'total'     => User::countAll(),
            'perPage'   => $perPage,
        ]);
    }

    public function updateUser(string $id): Response
    {
        $admin = $this->requireAdmin();
        $this->requireCsrf('/admin/users');

        $target = User::find((int) $id);
        if ($target === null) {
            throw new HttpError('User not found', 404);
        }

        $action = (string) $this->request->input('action', '');

        switch ($action) {
            case 'plan':
                $plan = (string) $this->request->input('plan', '');
                if (!isset(Plan::PLANS[$plan])) {
                    $this->flash('Unknown plan.', 'error');
                    break;
                }
                $months = max(0, min(120, (int) ($this->request->input('months', '1') ?? 1)));
                $expires = ($plan === User::PLAN_FREE || $months === 0)
                    ? null
                    : time() + $months * 31 * 86400;
                $target->setPlan($plan, $expires);
                $this->audit($admin, 'admin.plan_changed', $target->email() . ' -> ' . $plan);
                $this->flash($target->email() . ' moved to ' . Plan::displayName($plan) . '.', 'success');
                break;

            case 'suspend':
            case 'activate':
                if ($target->id() === $admin->id()) {
                    $this->flash('You cannot suspend your own account.', 'error');
                    break;
                }
                $status = $action === 'suspend' ? 'suspended' : 'active';
                $target->setStatus($status);
                $this->audit($admin, 'admin.status_changed', $target->email() . ' -> ' . $status);
                $this->flash($target->email() . ' is now ' . $status . '.', 'success');
                break;

            case 'promote':
            case 'demote':
                $makeAdmin = $action === 'promote';
                // Removing the last administrator would lock everyone out of
                // the admin area with no way back except the command line.
                if (!$makeAdmin && $target->isAdmin() && User::countAdmins() <= 1) {
                    $this->flash('This is the only administrator. Promote someone else first.', 'error');
                    break;
                }
                if (!$makeAdmin && $target->id() === $admin->id()) {
                    $this->flash('You cannot remove your own administrator rights.', 'error');
                    break;
                }
                $target->setAdmin($makeAdmin);
                $this->audit($admin, $makeAdmin ? 'admin.promoted' : 'admin.demoted', $target->email());
                $this->flash($target->email() . ($makeAdmin ? ' is now an administrator.' : ' is no longer an administrator.'), 'success');
                break;

            default:
                $this->flash('Unknown action.', 'error');
        }

        return $this->redirect('/admin/users');
    }

    /** Code moderation: switching off a destination that should not be live. */
    public function links(): Response
    {
        $this->requireAdmin();

        $search = trim((string) $this->request->input('q', ''));
        $params = [];
        $sql = 'SELECT l.*, u.email AS owner_email FROM links l JOIN users u ON u.id = l.user_id';
        if ($search !== '') {
            $sql .= ' WHERE l.slug LIKE :q OR l.default_url LIKE :q OR u.email LIKE :q';
            $params['q'] = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
        }
        $sql .= ' ORDER BY l.scan_count DESC LIMIT 50';

        return $this->view('admin/links', [
            'title'     => 'Codes — Admin',
            'activeNav' => 'admin',
            'rows'      => Database::instance()->all($sql, $params),
            'search'    => $search,
        ]);
    }

    public function updateLink(string $id): Response
    {
        $admin = $this->requireAdmin();
        $this->requireCsrf('/admin/links');

        $link = Link::find((int) $id);
        if ($link === null) {
            throw new HttpError('Code not found', 404);
        }

        $action = (string) $this->request->input('action', '');
        if ($action === 'disable') {
            $link->update(['is_active' => 0]);
            $this->audit($admin, 'admin.link_disabled', $link->slug());
            $this->flash('Code /' . $link->slug() . ' disabled.', 'success');
        } elseif ($action === 'enable') {
            $link->update(['is_active' => 1]);
            $this->audit($admin, 'admin.link_enabled', $link->slug());
            $this->flash('Code /' . $link->slug() . ' enabled.', 'success');
        }

        return $this->redirect('/admin/links');
    }

    private function audit(User $admin, string $action, string $subject): void
    {
        try {
            Database::instance()->insert('audit_log', [
                'user_id'    => $admin->id(),
                'action'     => $action,
                'subject'    => mb_substr($subject, 0, 191),
                'meta'       => null,
                'ip_hash'    => substr(Security::hmac($this->request->ip(), 'ip'), 0, 32),
                'created_at' => time(),
            ]);
        } catch (\Throwable) {
            // Auditing is best effort and must not block the action.
        }
    }
}
