<?php

declare(strict_types=1);

namespace Udeyou\Controllers\Api;

use Udeyou\Core\Request;
use Udeyou\Core\Response;
use Udeyou\Services\ApiException;
use Udeyou\Services\ApiKeyService;
use Udeyou\Services\CreditService;
use Udeyou\Services\Mail\MailCarrierFactory;
use Udeyou\Services\OtpService;

final class OtpController
{
    /** POST /api/v1/otp/send */
    public function send(Request $request): void
    {
        $key = (new ApiKeyService())->authenticate($request->apiKey());
        $result = (new OtpService(MailCarrierFactory::make()))->send($key, $this->body($request), $request->ip);
        Response::json(['success' => true, 'data' => $result], 201);
    }

    /** POST /api/v1/otp/verify */
    public function verify(Request $request): void
    {
        $key = (new ApiKeyService())->authenticate($request->apiKey());
        $result = (new OtpService(MailCarrierFactory::make()))->verify($key, $this->body($request));
        Response::json(['success' => true, 'data' => $result]);
    }

    /** GET /api/v1/balance */
    public function balance(Request $request): void
    {
        $key = (new ApiKeyService())->authenticate($request->apiKey());
        Response::json(['success' => true, 'data' => [
            'company' => $key['company_name'],
            'mode'    => $key['mode'],
            'credits' => (new CreditService())->balance((int) $key['client_id']),
        ]]);
    }

    private function body(Request $request): array
    {
        $body = $request->json();
        if ($body === null) {
            throw new ApiException('invalid_json', 'Request body must be a JSON object (Content-Type: application/json).', 400);
        }
        return $body;
    }
}
