<?php
/*
 *********************************************************************************************************
 * daloRADIUS - RADIUS Web Platform
 * Copyright (C) 2007 - Liran Tal <liran@lirantal.com> All Rights Reserved.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 *********************************************************************************************************
 */

// prevent this file to be directly accessed
if (strpos($_SERVER['PHP_SELF'], '/common/includes/mail_body_note.php') !== false) {
    http_response_code(404);
    exit;
}

/**
 * Append the configured mail body note to an HTML email body.
 *
 * Set CONFIG_MAIL_BODY_NOTE in daloradius.conf.php or MAIL_BODY_NOTE in .env (Docker).
 * Use \n for line breaks; basic HTML tags are allowed.
 */
function append_mail_body_note($config_values, $body) {
    $note = trim($config_values['CONFIG_MAIL_BODY_NOTE'] ?? '');
    if ($note === '') {
        return $body;
    }

    $note = str_replace(array('\\n', "\r\n", "\r"), array("\n", "\n", ''), $note);
    $note = nl2br($note, false);

    return $body . '<br><br><b>Note</b><br>' . $note;
}
