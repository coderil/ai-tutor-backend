<?php

namespace App\Enums;

enum PaginationType: string
{
    case PAGE = 'page';
    case CURSOR = 'cursor';
}