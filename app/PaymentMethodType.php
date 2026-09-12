<?php

namespace App;

enum PaymentMethodType: string
{
    case Single = 'single';
    case Composite = 'composite';
}
