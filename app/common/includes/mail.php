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
 *
 * Authors:    Filippo Lauria <filippo.lauria@iit.cnr.it>
 *
 *********************************************************************************************************
 */

// prevent this file to be directly accessed
if (strpos($_SERVER['PHP_SELF'], '/common/includes/mail.php') !== false) {
    http_response_code(404);
    exit;
}

include_once 'config_read.php';
include_once 'mail_body_note.php';

// Include PHPMailer classes
include implode(DIRECTORY_SEPARATOR, [ $configValues['COMMON_LIBRARY'], 'phpmailer', 'Exception.php' ]);
include implode(DIRECTORY_SEPARATOR, [ $configValues['COMMON_LIBRARY'], 'phpmailer', 'SMTP.php' ]);
include implode(DIRECTORY_SEPARATOR, [ $configValues['COMMON_LIBRARY'], 'phpmailer', 'PHPMailer.php' ]);


/**
 * Function for sending an email using PHPMailer.
 *
 * @param array  $config_values           Configuration values.
 * @param string $recipient_email_address Recipient's email address.
 * @param string $recipient_name          Recipient's name.
 * @param string $subject                 Email subject.
 * @param string $body                    Email body.
 *
 * @return array [bool, string] An array indicating success or failure and a message.
 */
function send_email($config_values, $recipient_email_address, $recipient_name, $subject, $body, $attachment=array()) {
    // Create a PHPMailer instance
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // Configure SMTP settings
        $mail->isSMTP();
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->Host = $config_values['CONFIG_MAIL_SMTPADDR'];
        $mail->Port = $config_values['CONFIG_MAIL_SMTPPORT'];
        $mail->SMTPSecure = $config_values['CONFIG_MAIL_SMTP_SECURITY'];

        // Check if the username and password are not empty before enabling authentication
        if (!empty($config_values['CONFIG_MAIL_SMTP_USERNAME']) && !empty($config_values['CONFIG_MAIL_SMTP_PASSWORD'])) {
            $mail->SMTPAuth = true;
            $mail->Username = $config_values['CONFIG_MAIL_SMTP_USERNAME'];
            $mail->Password = $config_values['CONFIG_MAIL_SMTP_PASSWORD'];
        }

        // Set sender and recipient
        $mail->setFrom($config_values['CONFIG_MAIL_SMTPFROM'], $config_values['CONFIG_MAIL_SMTP_SENDER_NAME']);
        $mail->addAddress(trim($recipient_email_address), trim($recipient_name));

        // Set email content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = append_mail_body_note($config_values, $body);

        if (is_array($attachment) && !empty($attachment)) {
            // Check if it is a single attachment associative array or a list of attachments
            $attachments_list = (isset($attachment['content']) && isset($attachment['filename'])) ? array($attachment) : $attachment;

            foreach ($attachments_list as $att) {
                if (is_array($att) && isset($att['content']) && isset($att['filename'])) {
                    $filename = $att['filename'];
                    $content = $att['content'];
                    $mimetype = $att['mimetype'] ?? ($att['type'] ?? '');

                    if (empty($mimetype)) {
                        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        if ($ext === 'pdf') {
                            $mimetype = 'application/pdf';
                        } else if ($ext === 'ovpn') {
                            $mimetype = 'application/x-openvpn-profile';
                        } else {
                            $mimetype = 'application/octet-stream';
                        }
                    }

                    $mail->addStringAttachment($content, $filename,
                                               PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64, $mimetype, 'attachment');
                }
            }
        }

        // Send the email
        $mail->send();

        return [true, "Email sent successfully"];
    } catch (Exception $e) {
        return [false, $e->getMessage()];
    }

    return [false, "Cannot send the email"];
}
