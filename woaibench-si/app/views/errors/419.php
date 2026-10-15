<?php
/** Error page (419). Rendered by abort(); the markup lives in partials/error-body.php. */
defined('WB') || exit;
echo partial('partials/error-body', ['code' => (int) ($code ?? 419), 'message' => $message ?? null]);
