<?php
if (!defined('ABSPATH')) exit;

/**
 * Helper: send email
 * We will expand this later.
 */
function pm_leads_send_email( $to, $subject, $body, $headers = [] ) {

    if (empty($to)) return false;

    if (empty($headers)) {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
    }

    return wp_mail($to, $subject, $body, $headers);
}
