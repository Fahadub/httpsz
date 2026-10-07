<?php

declare(strict_types=1);

namespace Udeyou\Controllers\Web;

use Udeyou\Core\Config;
use Udeyou\Core\Request;
use Udeyou\Core\Response;
use Udeyou\Core\Session;
use Udeyou\Core\View;
use Udeyou\Repositories\ClientRepository;
use Udeyou\Services\CreditService;
use Udeyou\Services\Mail\MailCarrierFactory;
use Udeyou\Services\Mail\MailDeliveryException;
use Udeyou\Services\Mail\MailMessage;
use Udeyou\Services\OtpTemplate;

/** Platform owner panel: list clients, top up credits, suspend/activate accounts. */
final class AdminController
{
    public function index(Request $request): void
    {
        $admin = self::requireAdmin();
        View::render('admin', [
            'title'   => 'الإدارة',
            'client'  => $admin,
            'clients' => (new ClientRepository())->all(),
            'notice'  => Session::flash('notice'),
            'error'   => Session::flash('error'),
        ]);
    }

    public function addCredits(Request $request, string $clientId): void
    {
        Session::verifyCsrf($request);
        self::requireAdmin();

        $amount = (int) $request->input('amount');
        if ($amount !== 0 && abs($amount) <= 1_000_000 && (new ClientRepository())->find((int) $clientId) !== null) {
            (new CreditService())->add((int) $clientId, $amount, $amount > 0 ? 'topup' : 'adjustment', null, $request->input('note') ?: null);
            Session::flash('notice', "تم تعديل رصيد العميل #{$clientId} بمقدار {$amount}");
        }
        Response::redirect('/admin');
    }

    public function toggleStatus(Request $request, string $clientId): void
    {
        Session::verifyCsrf($request);
        $admin = self::requireAdmin();

        $repo = new ClientRepository();
        $target = $repo->find((int) $clientId);
        if ($target !== null && (int) $target['id'] !== (int) $admin['id']) {
            $repo->setStatus((int) $clientId, $target['status'] === 'active' ? 'suspended' : 'active');
            Session::flash('notice', "تم تغيير حالة العميل #{$clientId}");
        }
        Response::redirect('/admin');
    }

    /** Sends a sample OTP email to the admin to check the SMTP settings in .env (no credits used). */
    public function testMail(Request $request): void
    {
        Session::verifyCsrf($request);
        $admin = self::requireAdmin();

        $appName = (string) Config::get('APP_NAME', 'Udeyou OTP');
        $email = OtpTemplate::render('123456', $appName, 300, 'ar', null, null);
        try {
            MailCarrierFactory::make()->send(new MailMessage($admin['email'], $appName, $email['subject'], $email['html'], $email['text']));
            Session::flash('notice', "تم إرسال بريد تجريبي إلى {$admin['email']} — تحقق من الوارد (والـ Spam).");
        } catch (MailDeliveryException $e) {
            Session::flash('error', 'فشل الإرسال: ' . $e->getMessage());
        }
        Response::redirect('/admin');
    }

    private static function requireAdmin(): array
    {
        $client = DashboardController::currentClient();
        if (!(bool) $client['is_admin']) {
            http_response_code(403);
            View::render('error', ['title' => '403', 'message' => 'غير مصرح لك بالدخول لهذه الصفحة']);
            exit;
        }
        return $client;
    }
}
