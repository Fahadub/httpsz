<?php
/** Error page (500). Rendered by abort(); the markup lives in partials/error-body.php. */
defined('WB') || exit;
echo partial('partials/error-body', ['code' => (int) ($code ?? 500), 'message' => $message ?? null]);
