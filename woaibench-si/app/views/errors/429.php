<?php
/** Error page (429). Rendered by abort(); the markup lives in partials/error-body.php. */
defined('WB') || exit;
echo partial('partials/error-body', ['code' => (int) ($code ?? 429), 'message' => $message ?? null]);
