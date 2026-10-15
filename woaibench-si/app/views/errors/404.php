<?php
/** Error page (404). Rendered by abort(); the markup lives in partials/error-body.php. */
defined('WB') || exit;
echo partial('partials/error-body', ['code' => (int) ($code ?? 404), 'message' => $message ?? null]);
