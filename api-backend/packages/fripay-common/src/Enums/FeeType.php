<?php

namespace App\Enums;

enum FeeType: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';
    case Tiered = 'tiered';
}
