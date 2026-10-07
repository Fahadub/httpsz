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

final class AuthController
{
    public function __construct(private readonly ClientRepository $clients = new ClientRepository())
    {
    }

    public function showLogin(Request $request): void
    {
        View::render('login', ['title' => 'تسجيل الدخول', 'error' => Session::flash('error'), 'old' => []]);
    }

    public function login(Request $request): void
    {
        Session::verifyCsrf($request);
        $email = strtolower($request->input('email'));
        $client = $this->clients->findByEmail($email);

        if ($client === null || !password_verify($request->input('password'), $client['password_hash'])) {
            usleep(400_000); // slow down brute-force attempts
            View::render('login', ['title' => 'تسجيل الدخول', 'error' => 'البريد أو كلمة المرور غير صحيحة', 'old' => ['email' => $email]]);
            return;
        }
        if ($client['status'] !== 'active') {
            View::render('login', ['title' => 'تسجيل الدخول', 'error' => 'الحساب موقوف، تواصل مع الدعم', 'old' => ['email' => $email]]);
            return;
        }

        Session::login((int) $client['id']);
        Response::redirect('/dashboard');
    }

    public function showRegister(Request $request): void
    {
        View::render('register', ['title' => 'إنشاء حساب', 'errors' => [], 'old' => []]);
    }

    public function register(Request $request): void
    {
        Session::verifyCsrf($request);
        if (!Config::bool('REGISTRATION_OPEN', true)) {
            View::render('error', ['title' => 'التسجيل مغلق', 'message' => 'التسجيل مغلق حالياً.']);
            return;
        }

        $old = ['company_name' => $request->input('company_name'), 'email' => strtolower($request->input('email'))];
        $password = $request->input('password');
        $errors = [];

        if (mb_strlen($old['company_name']) < 2 || mb_strlen($old['company_name']) > 120) {
            $errors[] = 'اسم الشركة/المتجر مطلوب (2-120 حرف)';
        }
        if (filter_var($old['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'البريد الإلكتروني غير صالح';
        } elseif ($this->clients->findByEmail($old['email']) !== null) {
            $errors[] = 'هذا البريد مسجل مسبقاً';
        }
        if (strlen($password) < 8) {
            $errors[] = 'كلمة المرور 8 أحرف على الأقل';
        }

        if ($errors) {
            View::render('register', ['title' => 'إنشاء حساب', 'errors' => $errors, 'old' => $old]);
            return;
        }

        $isAdmin = strtolower((string) Config::get('ADMIN_EMAIL', '')) === $old['email'];
        $clientId = $this->clients->create($old['company_name'], $old['email'], $password, $isAdmin);

        $bonus = Config::int('SIGNUP_BONUS_CREDITS', 10);
        if ($bonus > 0) {
            (new CreditService())->add($clientId, $bonus, 'signup_bonus', null, 'رصيد ترحيبي');
        }

        Session::login($clientId);
        Response::redirect('/dashboard');
    }

    public function logout(Request $request): void
    {
        Session::verifyCsrf($request);
        Session::logout();
        Response::redirect('/login');
    }
}
