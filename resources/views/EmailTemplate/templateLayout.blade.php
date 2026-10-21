<!doctype html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $title ?? 'Exclusive Links' }}</title>
    <style>
        body,
        table,
        td,
        a {
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }

        table,
        td {
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
        }

        img {
            -ms-interpolation-mode: bicubic;
            border: 0;
            height: auto;
            line-height: 100%;
            outline: none;
            text-decoration: none;
        }

        body {
            margin: 0;
            padding: 0;
            width: 100% !important;
            height: 100% !important;
            background-color: #f0f2f5;
        }

        @media only screen and (max-width: 620px) {
            .email-container {
                width: 100% !important;
                max-width: 100% !important;
            }

            .fluid {
                width: 100% !important;
                max-width: 100% !important;
                height: auto !important;
            }

            .stack-column {
                display: block !important;
                width: 100% !important;
            }

            .content-padding {
                padding: 30px 24px !important;
            }

            .footer-padding {
                padding: 24px 20px !important;
            }
        }
    </style>
</head>

<body style="margin:0; padding:0; background-color:#f0f2f5; font-family: Arial, Helvetica, sans-serif;">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f0f2f5">
        <tr>
            <td align="center" style="padding: 40px 16px;">

                <table role="presentation" class="email-container" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%; background-color:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.06);">

                    <!-- HEADER -->
                    <tr>
                        <td style="background-color:#00002a; text-align:center; padding: 28px 40px;">
                            <img src="{{ asset('images/email-logo.png') }}" alt="Exclusive Links Real Estate Brokers" width="180" style="display:block; margin:0 auto; max-width:180px; height:auto;" />
                        </td>
                    </tr>

                    <!-- ACCENT LINE -->
                    <tr>
                        <td style="background: linear-gradient(90deg, #c9a84c 0%, #e8d48b 50%, #c9a84c 100%); height:3px; font-size:0; line-height:0;">&nbsp;</td>
                    </tr>

                    <!-- BODY CONTENT -->
                    <tr>
                        <td class="content-padding" style="padding: 48px 50px 40px 50px; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.7; color: #333333;">
                            {!! $body !!}
                        </td>
                    </tr>

                    <!-- DIVIDER -->
                    <tr>
                        <td style="padding: 0 50px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="border-top: 1px solid #e5e7eb; font-size:0; line-height:0; height:1px;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- PRE-FOOTER -->
                    <tr>
                        <td style="padding: 24px 50px 8px 50px; text-align:center; font-family: Arial, Helvetica, sans-serif; font-size:13px; color:#888888; line-height:1.6;">
                            Exclusive Links Real Estate Brokers<br>
                            Dubai, United Arab Emirates<br>
                            <a href="tel:+  97143994937" style="color:#c9a84c; text-decoration:none;">+971 4 399 4937</a>
                            &nbsp;&middot;&nbsp;
                            <a href="https://exclusive-links.com" style="color:#c9a84c; text-decoration:none;">exclusive-links.com</a>
                        </td>
                    </tr>

                    <tr>
                        <td align="center" style="padding: 16px 50px 24px 50px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
                                <tr>
                                    <td style="padding:0 4px;">
                                        <a href="https://facebook.com" target="_blank">
                                            <img src="https://cdn-icons-png.flaticon.com/512/124/124010.png"
                                                width="24" height="24"
                                                alt="Facebook"
                                                style="display:block; border-radius:50%; width:24px; height:24px;">
                                        </a>
                                    </td>
                                    <td style="padding:0 4px;">
                                        <a href="https://linkedin.com" target="_blank">
                                            <img src="https://cdn-icons-png.flaticon.com/512/174/174857.png"
                                                width="24" height="24"
                                                alt="LinkedIn"
                                                style="display:block; border-radius:50%; width:24px; height:24px;">
                                        </a>
                                    </td>
                                    <td style="padding:0 4px;">
                                        <a href="https://instagram.com" target="_blank">
                                            <img src="https://cdn-icons-png.flaticon.com/512/2111/2111463.png"
                                                width="24" height="24"
                                                alt="Instagram"
                                                style="display:block; border-radius:50%; width:24px; height:24px;">
                                        </a>
                                    </td>
                                    <td style="padding:0 4px;">
                                        <a href="https://youtube.com" target="_blank">
                                            <img src="https://cdn-icons-png.flaticon.com/512/1384/1384060.png"
                                                width="24" height="24"
                                                alt="YouTube"
                                                style="display:block; border-radius:50%; width:24px; height:24px;">
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- FOOTER -->
                    <tr>
                        <td class="footer-padding" style="background-color:#00002a; text-align:center; padding: 22px 40px; font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 1.6; color: #8a8a9e;">
                            &copy; {{ $year ?? date('Y') }} Exclusive Links Real Estate Brokers LLC. All rights reserved.<br>
                            <span style="color:#555568;">This email was sent by the Exclusive Links CRM system.</span>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>

</html>