<?php

namespace App;

enum AccountType: string
{
    case Checking = 'checking';
    case Card = 'card';
    case Savings = 'savings';
    case Cash = 'cash';
}
