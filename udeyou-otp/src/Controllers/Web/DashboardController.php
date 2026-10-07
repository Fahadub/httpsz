<?php

declare(strict_types=1);

namespace Udeyou\Controllers\Web;

use Udeyou\Core\Request;
use Udeyou\Core\Response;
use Udeyou\Core\Session;
use Udeyou\Core\View;
use Udeyou\Repositories\ApiKeyRepository;
use Udeyou\Repositories\ClientRepository;
use Udeyou\Repositories\OtpRepository;
use Udeyou\Services\ApiKeyService;
use Udeyou\Services\CreditService;

final class DashboardController
{
    public function index(Request $request): void
    {
        $client = self::currentClient();
        $id = (int) $client['id'];

        View::render('dashboard', [
            'title'   => 'لوحة التحكم',
            'client'  => $client,
            'keys'    => (new ApiKeyRepository())->listForClient($id),
            'otps'    => (new OtpRepository())->recentForClient($id, 20),
            'stats'   => (new OtpRepository())->statsForClient($id),
            'credits' => (new CreditService())->history($id, 5),
            'newKey'  => Session::flash('new_key'),
            'notice'  => Session::flash('notice'),
        ]);
    }

    public function createKey(Request $request): void
    {
        Session::verifyCsrf($request);
        $client = self::currentClient();

        $name = mb_substr($request->input('name') ?: 'Default', 0, 80);
        $mode = $request->input('mode') === 'test' ? 'test' : 'live';

        Session::flash('new_key', (new ApiKeyService())->generate((int) $client['id'], $name, $mode));
        Response::redirect('/dashboard#keys');
    }

    public function revokeKey(Request $request, string $keyId): void
    {
        Session::verifyCsrf($request);
        $client = self::currentClient();

        (new ApiKeyRepository())->revoke((int) $client['id'], (int) $keyId);
        Session::flash('notice', 'تم إلغاء المفتاح، لن يقبل أي طلب بعد الآن.');
        Response::redirect('/dashboard#keys');
    }

    public function docs(Request $request): void
    {
        View::render('docs', ['title' => 'التوثيق والربط', 'client' => self::optionalClient()]);
    }

    public function home(Request $request): void
    {
        View::render('home', ['title' => 'الرئيسية', 'client' => self::optionalClient()]);
    }

    /** Logged-in client or redirect to /login. */
    public static function currentClient(): array
    {
        $client = self::optionalClient();
        if ($client === null || $client['status'] !== 'active') {
            Session::logout();
            Response::redirect('/login');
        }
        return $client;
    }

    public static function optionalClient(): ?array
    {
        $id = Session::clientId();
        return $id === null ? null : (new ClientRepository())->find($id);
    }
}
