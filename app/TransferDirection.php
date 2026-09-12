<?php

namespace App;

enum TransferDirection: string
{
    case Source = 'source';
    case Destination = 'destination';
}
