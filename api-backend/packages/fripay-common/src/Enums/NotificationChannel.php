<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case Push = 'push';
    case Sms = 'sms';
    case Email = 'email';
    case InApp = 'in_app';
}
