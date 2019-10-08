<?php

namespace Paysafe\Payment\Model\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Handler extends Base
{
    protected $fileName = '/var/log/paysafe.log';
    protected $loggerType = Logger::DEBUG;
}
