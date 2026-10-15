<?php
/** Error page (403). Rendered by abort(); the markup lives in partials/error-body.php. */
defined('WB') || exit;
echo partial('partials/error-body', ['code' => (int) ($code ?? 403), 'message' => $message ?? null]);
