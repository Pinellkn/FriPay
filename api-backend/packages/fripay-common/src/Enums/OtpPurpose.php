<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case Registration = 'registration';
    case Login = 'login';
    case TransactionConfirmation = 'transaction_confirmation';
    case PasswordReset = 'password_reset';
}
