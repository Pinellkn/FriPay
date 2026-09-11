<?php

namespace App\Enums;

enum ClientType: string
{
    case Person = 'P';
    case Company = 'C';
    case Business = 'B';
    case Government = 'G';
}
