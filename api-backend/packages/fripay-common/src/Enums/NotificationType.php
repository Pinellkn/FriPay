<?php

namespace App\Enums;

enum NotificationType: string
{
    case TransactionUpdate = 'transaction_update';
    case Security = 'security';
    case Marketing = 'marketing';
    case System = 'system';
}
